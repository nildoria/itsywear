<?php
defined('ABSPATH') || exit;

// ============ Open Orders Shortcode (selector-first, full-width, 2-col grid) ============
// Usage: [eppdp_open_orders]
add_action('init', function () {
    add_shortcode('eppdp_open_orders', 'eppdp_render_open_orders_shortcode');
});

function eppdp_render_open_orders_shortcode($atts = [])
{
    if (!is_user_logged_in()) {
        return '<div class="woocommerce"><p class="woocommerce-info">Please <a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">log in</a> to view open orders.</p></div>';
    }
    if (!class_exists('WooCommerce') || !function_exists('eppdp_get_all_saved_carts')) {
        return '<div class="woocommerce"><p class="woocommerce-error">WooCommerce / Itsy Toolkit components are not active.</p></div>';
    }

    // Keep snapshot fresh for current employee (no-op if suspended)
    if (function_exists('eppdp_save_current_employee_cart')) {
        eppdp_save_current_employee_cart();
    }

    $uid = get_current_user_id();
    $nonce = wp_create_nonce('eppdp_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $checkout_url = wc_get_checkout_url();

    // Employees map: id => [id, name, code]
    $employees = function_exists('eppdp_get_employees_for_user')
        ? eppdp_get_employees_for_user($uid)
        : apply_filters('eppdp_employees_for_user', [], $uid);

    $emap = [];
    foreach ((array) $employees as $e) {
        $emap[(string) ($e['id'] ?? '')] = [
            'id' => (string) ($e['id'] ?? ''),
            'name' => (string) ($e['name'] ?? ''),
            'code' => (string) ($e['code'] ?? ''),
        ];
    }

    // Pull saved carts (pruned)
    $data = eppdp_prune_expired_carts(eppdp_get_all_saved_carts($uid));
    $carts = isset($data['carts']) && is_array($data['carts']) ? $data['carts'] : [];

    // Prefer live cart lines for the currently selected employee
    $current_emp = function_exists('eppdp_get_selected_employee_id') ? eppdp_get_selected_employee_id() : '';
    if (WC()->cart && $current_emp) {
        $live_items = [];
        foreach (WC()->cart->get_cart() as $ci) {
            if (($ci['eppdp_employee_id'] ?? '') === $current_emp) {
                $live_items[] = [
                    'product_id' => (int) ($ci['product_id'] ?? 0),
                    'variation_id' => (int) ($ci['variation_id'] ?? 0),
                    'qty' => (float) ($ci['quantity'] ?? 1),
                    'variation' => is_array($ci['variation'] ?? null) ? $ci['variation'] : [],
                ];
            }
        }
        if ($live_items) {
            if (!isset($carts[$current_emp]))
                $carts[$current_emp] = [];
            $carts[$current_emp]['items'] = $live_items;
        }
    }

    // Helper: format variation attributes (to HTML)
    if (!function_exists('eppdp__format_variation_attrs')) {
        function eppdp__format_variation_attrs(array $attrs): string
        {
            if (!$attrs)
                return '';
            $out = [];
            foreach ($attrs as $k => $v) {
                $tax = str_replace('attribute_', '', (string) $k);
                $label = wc_attribute_label($tax);
                $val = (string) $v;
                if (taxonomy_exists($tax)) {
                    $term = get_term_by('slug', $v, $tax);
                    if ($term && !is_wp_error($term))
                        $val = $term->name;
                }
                $out[] = esc_html($label . ': ' . $val);
            }
            return $out ? '<div class="eppdp-attrs">' . implode(', ', $out) . '</div>' : '';
        }
    }

    // Build dataset of ONLY employees who have items
    $open_emps = [];
    $dataset = []; // for JS rendering

    foreach ($carts as $emp_id => $entry) {
        $emp_id = (string) $emp_id;
        $items = isset($entry['items']) && is_array($entry['items']) ? $entry['items'] : [];
        if (!$items)
            continue;

        $emp_name = $emap[$emp_id]['name'] ?? $emp_id;
        $emp_code = $emap[$emp_id]['code'] ?? '';

        $count = 0;
        $subtotal = 0.0;
        $render = [];

        foreach ($items as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $vid = (int) ($row['variation_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 1);
            if ($pid <= 0 || $qty <= 0)
                continue;

            $product = wc_get_product($vid ?: $pid);
            if (!$product)
                continue;

            $per = wc_get_price_to_display($product);
            $line = $per * $qty;

            $count += $qty;
            $subtotal += $line;

            $render[] = [
                'thumb_html' => $product->get_image('woocommerce_thumbnail'),
                'title' => $product->get_name(),
                'url' => get_permalink($product->get_id()),
                'qty' => wc_format_decimal($qty, 0),
                'unit_html' => wc_price($per),
                'line_html' => wc_price($line),
                'attrs_html' => eppdp__format_variation_attrs(is_array($row['variation'] ?? null) ? $row['variation'] : []),
            ];
        }

        if (!$render)
            continue;

        $open_emps[$emp_id] = [
            'id' => $emp_id,
            'name' => $emp_name,
            'code' => $emp_code,
            'count' => (int) $count,
        ];
        $dataset[$emp_id] = [
            'items' => $render,
            'subtotal_html' => wc_price($subtotal),
            'count' => (int) $count,
            'name' => $emp_name,
            'code' => $emp_code,
        ];
    }

    // Determine initial selected (first employee with items)
    $initial_emp_id = '';
    if (!empty($dataset)) {
        if (function_exists('array_key_first')) {
            $initial_emp_id = (string) array_key_first($dataset);
        } else {
            foreach ($dataset as $k => $_) {
                $initial_emp_id = (string) $k;
                break;
            }
        }
    }

    ob_start();

    // Styles (scoped)
    static $did_css = false;
    if (!$did_css) {
        $did_css = true; ?>
        <style>
            .eppdp-openorders-wrap {
                width: 100%;
                max-width: none;
                margin: 24px 0;
                padding: 0
            }

            /* full width inside Oxygen area */
            .eppdp-openorders-head {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                margin: 0 0 16px
            }

            .eppdp-openorders-head select {
                min-width: 260px
            }

            .eppdp-openorders-head .button {
                white-space: nowrap
            }

            .eppdp-openorders-body {
                margin-top: 12px
            }

            .eppdp-selected-head {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                background: #fff;
                margin-bottom: 12px
            }

            /* GRID: 1 col mobile, 2 cols >=640px (tablets +) */
            .eppdp-list {
                display: grid;
                grid-template-columns: 1fr;
                gap: 14px;
                margin: 0;
                padding: 0;
                background: transparent;
                border: 0;
                list-style: none;
            }

            @media (min-width:640px) {
                .eppdp-list {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (min-width:991px) {
                .eppdp-list {
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                }
            }

            .eppdp-row {
                display: flex;
                gap: 12px;
                padding: 12px;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                background: #fff
            }

            .eppdp-thumb img {
                width: 64px;
                height: 64px;
                object-fit: cover;
                border-radius: 8px
            }

            .eppdp-meta {
                flex: 1;
                min-width: 0
            }

            .eppdp-title {
                display: inline-block;
                font-weight: 600;
                color: #111827;
                text-decoration: none
            }

            .eppdp-title:hover {
                text-decoration: underline
            }

            .eppdp-attrs {
                font-size: 12px;
                color: #6b7280;
                margin-top: 2px
            }

            .eppdp-qtyline {
                display: flex;
                gap: 8px;
                align-items: center;
                margin-top: 6px
            }

            .eppdp-qty {
                font-weight: 600
            }

            .eppdp-unit {
                opacity: .85
            }

            .eppdp-line {
                margin-left: auto;
                font-weight: 700
            }

            .eppdp-foot {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 12px 16px;
                margin-top: 14px
            }

            .eppdp-subtotal-label {
                font-weight: 600;
                color: #374151
            }

            .eppdp-subtotal {
                font-weight: 700
            }

            /* EXACT selector option markup */
            .eppdp-optwrap {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 15px
            }

            .eppdp-optlabel {
                font-weight: 700
            }

            .eppdp-code {
                opacity: .75
            }

            .eppdp-badge {
                display: inline-block;
                min-width: 20px;
                padding: 0 6px;
                line-height: 20px;
                border-radius: 999px;
                background: #111827;
                color: #fff;
                text-align: center;
                font-size: 12px;
                vertical-align: middle
            }
        </style>
    <?php } ?>

    <div class="eppdp-openorders-wrap woocommerce" data-eppdp-openorders="1">
        <h1 style="margin:0 0 12px;">Open Orders</h1>
        <p class="woocommerce-info" style="margin-bottom:12px;">Choose an employee to view their items, then continue to
            checkout.</p>

        <div class="eppdp-openorders-head">
            <label for="eppdp-open-employee-select" class="screen-reader-text">Employee</label>
            <select id="eppdp-open-employee-select">
                <option value=""><?php echo esc_html('— Choose Employee —'); ?></option>
                <?php foreach ($open_emps as $emp): ?>
                    <option value="<?php echo esc_attr($emp['id']); ?>" data-code="<?php echo esc_attr($emp['code']); ?>"
                        data-count="<?php echo (int) $emp['count']; ?>" <?php selected($emp['id'], $initial_emp_id); ?>>
                        <?php echo esc_html($emp['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <button id="eppdp-open-continue" class="button button-primary" disabled>Continue checkout</button>
        </div>

        <div class="eppdp-openorders-body" id="eppdp-open-results" aria-live="polite"></div>
    </div>

    <script>
        (function () {
            var DATASET = <?php echo wp_json_encode($dataset); ?>;
            var initial = <?php echo wp_json_encode($initial_emp_id); ?>;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var toCk = <?php echo wp_json_encode($checkout_url); ?>;

            var root = document.querySelector('[data-eppdp-openorders="1"]');
            var select = root.querySelector('#eppdp-open-employee-select');
            var results = root.querySelector('#eppdp-open-results');
            var btnGo = root.querySelector('#eppdp-open-continue');

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
                });
            }

            function render(empId) {
                results.innerHTML = '';
                btnGo.disabled = true;

                if (!empId || !DATASET[empId]) {
                    results.innerHTML = '<p class="woocommerce-message">No items to show. Pick an employee.</p>';
                    return;
                }

                var pack = DATASET[empId];

                var head = document.createElement('div');
                head.className = 'eppdp-selected-head';
                head.innerHTML =
                    '<span class="eppdp-optwrap">'
                    + '<span class="eppdp-optlabel">' + escapeHtml(pack.name) + '</span>'
                    + (pack.code ? '<em class="eppdp-code"> (' + escapeHtml(pack.code) + ')</em>' : '')
                    + (pack.count > 0 ? '<sup class="eppdp-badge">' + String(pack.count) + '</sup>' : '')
                    + '</span>';
                results.appendChild(head);

                var ul = document.createElement('ul');
                ul.className = 'eppdp-list';

                pack.items.forEach(function (it) {
                    var li = document.createElement('li');
                    li.className = 'eppdp-row';
                    li.innerHTML =
                        '<div class="eppdp-thumb">' + (it.thumb_html || '') + '</div>'
                        + '<div class="eppdp-meta">'
                        + '<a class="eppdp-title" href="' + (it.url || '#') + '">' + escapeHtml(it.title || '') + '</a>'
                        + (it.attrs_html || '')
                        + '<div class="eppdp-qtyline">'
                        + '<span class="eppdp-qty">' + escapeHtml(String(it.qty || '1')) + ' ×</span>'
                        + '<span class="eppdp-unit">' + (it.unit_html || '') + '</span>'
                        + '<span class="eppdp-line">' + (it.line_html || '') + '</span>'
                        + '</div>'
                        + '</div>';
                    ul.appendChild(li);
                });
                results.appendChild(ul);

                var foot = document.createElement('div');
                foot.className = 'eppdp-foot';
                foot.innerHTML =
                    '<span class="eppdp-subtotal-label">Subtotal</span>'
                    + '<span class="eppdp-subtotal">' + (pack.subtotal_html || '') + '</span>';
                results.appendChild(foot);

                btnGo.disabled = false;
                btnGo.onclick = function (e) {
                    e.preventDefault();
                    btnGo.disabled = true;
                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({
                            action: 'save_employee_po',
                            nonce: nonce,
                            employee: empId,
                            po: ''
                        })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res && res.success) {
                                window.location.href = toCk;
                            } else {
                                alert((res && res.data) ? res.data : 'Could not switch employee.');
                                btnGo.disabled = false;
                            }
                        })
                        .catch(function () {
                            alert('Network error.');
                            btnGo.disabled = false;
                        });
                };
            }

            // Init Select2 if available, using your footer helpers if present
            (function initSelect2() {
                var $ = window.jQuery;
                if (!$ || !$.fn || !$.fn.select2) return;
                var matcher = (window.eppdpHelpers && eppdpHelpers.empMatcher) ? eppdpHelpers.empMatcher : function (params, data) {
                    if (!params.term) return data;
                    var term = String(params.term).toLowerCase();
                    var text = (data.text || '').toLowerCase();
                    var code = ($(data.element).attr('data-code') || '').toLowerCase().trim();
                    return (text.indexOf(term) > -1 || (code && code.indexOf(term) > -1)) ? data : null;
                };
                var templater = (window.eppdpHelpers && eppdpHelpers.withCodeAndCountMarkup) ? eppdpHelpers.withCodeAndCountMarkup : function (data) {
                    if (!data.element) return data.text;
                    var $el = jQuery(data.element);
                    var code = ($el.attr('data-code') || '').trim();
                    var count = parseInt($el.attr('data-count') || '0', 10);
                    var $wrap = jQuery('<span class="eppdp-optwrap"></span>');
                    var $lab = jQuery('<span class="eppdp-optlabel"></span>').text(data.text);
                    $wrap.append($lab);
                    if (code) $wrap.append(jQuery('<em class="eppdp-code"></em>').text(' (' + code + ')'));
                    if (count > 0) $wrap.append(jQuery('<sup class="eppdp-badge"></sup>').text(count));
                    return $wrap;
                };
                jQuery(select).select2({
                    placeholder: "Search employee…",
                    allowClear: true,
                    width: 'resolve',
                    matcher: matcher,
                    templateResult: templater,
                    templateSelection: templater,
                    dropdownAutoWidth: true
                });
            })();

            // Bind change AFTER possible Select2 init
            select.addEventListener('change', function () {
                render(select.value);
            });

            // Auto-select first employee (if any) and render immediately
            if (initial && DATASET[initial]) {
                select.value = initial; // HTML already marked selected, this keeps native in sync
                render(initial);
            } else {
                // No data case
                results.innerHTML = '<p class="woocommerce-message">No open orders yet.</p>';
                btnGo.disabled = true;
            }
        })();
    </script>
    <?php

    return ob_get_clean();
}
