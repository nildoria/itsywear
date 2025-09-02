<?php
/**
 * My Account → Orders: Employee & Parent-Product filter + date range + summary
 */

defined('ABSPATH') || exit;


/**
 * Filter bar & summary above the Orders table.
 */
add_action('woocommerce_before_account_orders', 'eppdp_render_orders_filter_bar', 5);
function eppdp_render_orders_filter_bar($has_orders)
{
    if (!is_user_logged_in() || !current_user_can('read')) {
        return;
    }

    $user_id = get_current_user_id();

    $employees = function_exists('eppdp_get_employees_for_user')
        ? eppdp_get_employees_for_user($user_id)
        : array();

    $products = eppdp_get_user_purchased_parent_products($user_id);

    $emp = isset($_GET['eppdp_emp']) ? sanitize_text_field(wp_unslash($_GET['eppdp_emp'])) : '';
    $prod = isset($_GET['eppdp_prod']) ? absint($_GET['eppdp_prod']) : 0;
    $raw_range = isset( $_GET['eppdp_range'] ) ? wp_unslash( $_GET['eppdp_range'] ) : '';
    $range     = is_array( $raw_range ) ? reset( $raw_range ) : $raw_range;
    $range     = sanitize_text_field( $range );


    list($from, $to) = eppdp_parse_date_range($range);

    $action_url = wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'));
    ?>
    <style>
    .eppdp-orders-filter {
        --eppdp-bg: #ffffff;
        --eppdp-text: #111827;          /* slate-900 */
        --eppdp-muted: #6b7280;         /* slate-500 */
        --eppdp-border: #e5e7eb;        /* slate-200 */
        --eppdp-ring: rgba(59,130,246,.15); /* blue-500 @ 15% */
        --eppdp-accent: #3b82f6;        /* blue-500 */
        --eppdp-radius: 10px;

        margin: 0 0 16px;
        padding: 14px;
        background: var(--eppdp-bg);
        border: 1px solid var(--eppdp-border);
        border-radius: var(--eppdp-radius);
        box-shadow: 0 4px 14px rgba(0,0,0,.03);
        color: var(--eppdp-text);
    }

    @media (prefers-color-scheme: dark) {
        .eppdp-orders-filter {
        --eppdp-bg: #1a2c38;
        --eppdp-text: #e5e7eb;
        --eppdp-muted: #9ca3af;
        --eppdp-border: #e5e7eb;
        --eppdp-ring: rgba(59,130,246,.3);
        }
    }

    .eppdp-of-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 20px;
    }
    @media (max-width:1024px){ .eppdp-of-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width:640px){ .eppdp-of-grid { grid-template-columns: 1fr; } }

    .eppdp-orders-filter .eppdp-field label {
        display: block;
        font-weight: 600;
        margin: 0 0 6px;
        color: var(--eppdp-text);
        font-size: 13px;
        letter-spacing: .2px;
    }

    /* Inputs (select + date) */
    .eppdp-orders-filter .input-select,
    .eppdp-orders-filter .input-text {
        width: 100%;
        height: 40px;
        padding: 8px 12px;
        border: 1px solid var(--eppdp-border);
        border-radius: 8px;
        background: var(--eppdp-bg);
        color: var(--eppdp-text);
        outline: none;
        transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
        box-shadow: 0 1px 0 rgba(0,0,0,.02) inset;
        -webkit-appearance: none;
        appearance: none;
    }
    .eppdp-orders-filter .input-select:focus,
    .eppdp-orders-filter .input-text:focus {
        border-color: var(--eppdp-accent);
        box-shadow: 0 0 0 4px var(--eppdp-ring);
    }

    /* Select arrow */
    .eppdp-orders-filter .input-select {
        padding-right: 36px;
        background-image:
        url("data:image/svg+xml;utf8,<svg viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg' fill='%236b7280'><path d='M5.23 7.21a.75.75 0 011.06.02L10 10.06l3.71-2.83a.75.75 0 01.92 1.19l-4.14 3.16a.75.75 0 01-.92 0L5.25 8.42a.75.75 0 01-.02-1.06z'/></svg>");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 16px 16px;
    }

    /* Date inputs: show calendar icon subtly (WebKit) */
    .eppdp-orders-filter input[type="date"]::-webkit-calendar-picker-indicator {
        opacity: .8;
        cursor: pointer;
        margin-left: 4px;
        filter: grayscale(1);
    }

    .eppdp-date-pickers {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-top: 6px;
    }
    .eppdp-date-pickers small {
        display: block;
        margin-bottom: 4px;
        color: var(--eppdp-muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
    }

    .eppdp-orders-filter .eppdp-actions {
        display: flex;
        gap: 27px;
        align-items: center;
        flex-direction: column;
        justify-content: flex-end;
        margin-bottom: 3px;
    }
    .eppdp-orders-filter .eppdp-actions .button {
        height: 40px;
        border-radius: 8px;
        padding: 0 14px;
        width: 120px;
    }

    .eppdp-summary {
        margin: 10px 2px 0;
        padding-top: 8px;
        border-top: 1px dashed var(--eppdp-border);
        font-size: 13px;
        color: var(--eppdp-muted);
    }

    .eppdp-date-pickers input[type="date"] {
    --eppdp-accent: #ffffff;
    padding-right: 2.25rem;
    background-repeat: no-repeat;
    background-position: right .625rem center;
    background-size: 18px 18px;
    background-image: url("data:image/svg+xml,%3Csvg width='18' height='18' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2' stroke='%23ffffff' stroke-width='2'/%3E%3Cpath d='M8 2v4M16 2v4M3 10h18' stroke='%23ffffff' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
    border: 1px solid #d1d5db;
    border-radius: 8px;
    height: 38px;
    }

    .eppdp-date-pickers input[type="date"]::-webkit-calendar-picker-indicator {
    opacity: 0;
    display: block;
    width: 18px; height: 18px;
    }

    .eppdp-date-pickers input[type="date"]:focus {
    outline: none;
    border-color: var(--eppdp-accent, #ffffff);
    box-shadow: 0 0 0 3px rgba(37,99,235,.15);
    }
    input[type="date"]::-webkit-calendar-picker-indicator { filter: hue-rotate(200deg) saturate(4); }

    </style>


    <form class="eppdp-orders-filter" method="get" action="<?php echo esc_url($action_url); ?>">
        <div class="eppdp-of-grid">
            <div class="eppdp-field">
                <div style="margin-top:17px;">
                    <label for="eppdp_emp"><?php esc_html_e('Employee', 'eppdp'); ?></label>
                    <select id="eppdp_emp" name="eppdp_emp" class="input-select">
                        <option value=""><?php esc_html_e('All employees', 'eppdp'); ?></option>
                        <?php foreach ((array) $employees as $row): ?>
                            <option value="<?php echo esc_attr((string) ($row['id'] ?? '')); ?>" <?php selected($emp, (string) ($row['id'] ?? '')); ?>>
                                <?php echo esc_html((string) ($row['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top:0px;">
                    <label for="eppdp_prod"><?php esc_html_e('Product', 'eppdp'); ?></label>
                    <select id="eppdp_prod" name="eppdp_prod" class="input-select">
                        <option value="0"><?php esc_html_e('Any product', 'eppdp'); ?></option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo esc_attr($p['id']); ?>" <?php selected($prod, $p['id']); ?>>
                                <?php echo esc_html($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="eppdp-field">
                <label><?php esc_html_e('Date range', 'eppdp'); ?></label>

                <!-- hidden field actually submitted; PHP already parses it -->
                <input id="eppdp_range" name="eppdp_range" type="hidden" value="<?php echo esc_attr($range); ?>">

                <div class="eppdp-date-pickers">
                    <div>
                        <small style="display:block;margin-bottom:2px;"><?php esc_html_e('From', 'eppdp'); ?></small>
                        <input id="eppdp_from" type="date" class="input-text" value="<?php echo esc_attr($from); ?>">
                    </div>
                    <div>
                        <small style="display:block;margin-bottom:2px;"><?php esc_html_e('To', 'eppdp'); ?></small>
                        <input id="eppdp_to" type="date" class="input-text" value="<?php echo esc_attr($to); ?>">
                    </div>
                </div>
            </div>

            <div class="eppdp-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'eppdp'); ?></button>
                <a class="button" href="<?php echo esc_url($action_url); ?>"><?php esc_html_e('Reset', 'eppdp'); ?></a>
            </div>
        </div>

        <?php
        // Preserve other WC query args (pagination, etc.).
        foreach ($_GET as $key => $val) {
            if (in_array($key, array('eppdp_emp', 'eppdp_prod', 'eppdp_range'), true)) {
                continue;
            }
            if (is_array($val)) {
                foreach ($val as $k2 => $v2) {
                    printf(
                        '<input type="hidden" name="%s[%s]" value="%s" />',
                        esc_attr($key),
                        esc_attr($k2),
                        esc_attr(wp_unslash($v2))
                    );
                }
            } else {
                printf(
                    '<input type="hidden" name="%s" value="%s" />',
                    esc_attr($key),
                    esc_attr(wp_unslash($val))
                );
            }
        }
        ?>
        <script>
            jQuery(function ($) {

                ['eppdp_from','eppdp_to'].forEach(function(id){
                var el = document.getElementById(id);
                if (!el) return;

                function openPicker() {
                    if (typeof el.showPicker === 'function') {
                    el.showPicker();
                    }
                }

                el.addEventListener('click', openPicker);
                el.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    openPicker();
                    }
                });
                });

                var $range = $('#eppdp_range');
                var $from = $('#eppdp_from');
                var $to = $('#eppdp_to');

                function joinRange(f, t) {
                    if (f && t) return f + ' \u2013 ' + t; // en dash
                    if (f) return f;
                    if (t) return t;
                    return '';
                }

                function syncHidden() {
                    $range.val(joinRange($from.val(), $to.val()));
                }

                // On load and on change, keep hidden field updated
                syncHidden();
                $from.on('change', syncHidden);
                $to.on('change', syncHidden);

                // Safety: before submit re-sync
                $('.eppdp-orders-filter').on('submit', syncHidden);

                $from.on('change', function(){
                if ($to.val() && $to.val() < $from.val()) { $to.val($from.val()); }
                $to.attr('min', $from.val() || null);
                });
                $to.on('change', function(){
                if ($from.val() && $to.val() < $from.val()) { $from.val($to.val()); }
                $from.attr('max', $to.val() || null);
                });
                if ($from.val()) $to.attr('min', $from.val());
                if ($to.val())   $from.attr('max', $to.val());
            });
        </script>

    </form>
    <?php

    // Summary when any filter is used.
    if ( $emp || $prod || ( $from && $to ) ) {
        $summary = eppdp_count_product_parent_purchases_for_user(
            $user_id,
            array(
                'employee_id' => $emp,
                'product_id'  => $prod,
                'from'        => $from,
                'to'          => $to,
            )
        );

        echo '<div class="eppdp-summary">';
        if ( $summary['checked_orders'] > 0 ) {
            printf(
                /* translators: 1: qty, 2: product label, 3: orders count */
                esc_html__( 'Result: %1$s units of %2$s across %3$s orders.', 'eppdp' ) . ' ',
                '<strong>' . esc_html( $summary['total_qty'] ) . '</strong>',
                '<strong>' . esc_html( $summary['product_label'] ) . '</strong>',
                '<strong>' . esc_html( $summary['matched_orders'] ) . '</strong>'
            );

            // New: total monetary amount for the filtered set (allow price HTML)
            echo ' ' . esc_html__( 'Total amount:', 'eppdp' ) . ' ' . wc_price( $summary['total_amount'] ) . '.';

            if ( ! empty( $summary['last_date'] ) ) {
                printf( ' ' . esc_html__( 'Last purchased: %s.', 'eppdp' ), esc_html( $summary['last_date'] ) );
            }
        } else {
            esc_html_e( 'No matching orders in the selected range.', 'eppdp' );
        }
        echo '</div>';
    }

}

/**
 * Automatically limit the Orders table to the selected employee.
 */
add_filter('woocommerce_my_account_my_orders_query', 'eppdp_filter_my_account_orders_query');
function eppdp_filter_my_account_orders_query($query_args)
{
    if (!is_user_logged_in()) {
        return $query_args;
    }

    $emp = isset($_GET['eppdp_emp']) ? sanitize_text_field(wp_unslash($_GET['eppdp_emp'])) : '';
    $prod = isset($_GET['eppdp_prod']) ? absint($_GET['eppdp_prod']) : 0;
    $raw_range = isset( $_GET['eppdp_range'] ) ? wp_unslash( $_GET['eppdp_range'] ) : '';
    $range     = is_array( $raw_range ) ? reset( $raw_range ) : $raw_range;
    $range     = sanitize_text_field( $range );


    list($from, $to) = eppdp_parse_date_range($range);

    // Employee (optional)
    if ($emp) {
        $query_args['meta_query'] = isset($query_args['meta_query']) && is_array($query_args['meta_query']) ? $query_args['meta_query'] : array();
        $query_args['meta_query'][] = array(
            'key' => '_billing_employee_id',
            'value' => $emp,
            'compare' => '=',
        );
    }

    // Date range (optional) — use strings (works in CPT + HPOS)
    if ( $from ) {
        $query_args['date_after']  = $from . ' 00:00:00';
    }
    if ( $to ) {
        $query_args['date_before'] = $to   . ' 23:59:59';
    }


    // Product (optional) — restrict table to orders that actually contain the selected PARENT product.
    if ($prod) {
        // Build a clean prequery with the same employee/date constraints, but no pagination.
        $pre = array(
            'customer_id' => get_current_user_id(),
            'status'      => isset( $query_args['status'] ) ? $query_args['status'] : array_keys( wc_get_order_statuses() ),
            'limit'       => -1,
            'paginate'    => false,
            'return'      => 'objects',
            'meta_query'  => isset( $query_args['meta_query'] ) ? $query_args['meta_query'] : array(),
        );

        // carry date args forward if set
        if ( ! empty( $query_args['date_after'] ) ) {
            $pre['date_after'] = $query_args['date_after'];
        }
        if ( ! empty( $query_args['date_before'] ) ) {
            $pre['date_before'] = $query_args['date_before'];
        }


        $orders = wc_get_orders($pre);
        $include = array();

        foreach ($orders as $order) {
            foreach ($order->get_items('line_item') as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }
                $parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
                if ($parent_id === $prod) {
                    $include[] = $order->get_id();
                    break; // this order qualifies
                }
            }
        }

        // Be absolutely strict: only these orders. Support both HPOS and CPT by setting both args.
        $only_these = !empty($include) ? array_values(array_unique($include)) : array(0); // 0 => no rows
        $query_args['include'] = $only_these;  // WC_Order_Query arg
        $query_args['post__in'] = $only_these;  // WP_Query arg (classic CPT)
        $query_args['limit'] = -1;           // avoid stale pagination mixing in old results
        $query_args['paged'] = 1;            // reset page to the first
    }

    return $query_args;
}


/**
 * Build a cached list of PARENT products the user bought (no variations).
 *
 * @param int $user_id User ID.
 * @return array[] { id:int, name:string }
 */
function eppdp_get_user_purchased_parent_products($user_id)
{
    $cache_key = 'eppdp_user_parent_products_' . (int) $user_id;
    $cached = get_transient($cache_key);
    if (false !== $cached) {
        return $cached;
    }

    $orders = wc_get_orders(
        array(
            'customer_id' => $user_id,
            'status' => array_keys(wc_get_order_statuses()),
            'limit' => -1,
            'paginate' => false,
            'return' => 'objects',
        )
    );

    $map = array();

    foreach ($orders as $order) {
        foreach ($order->get_items('line_item') as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $pid = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
            if (!$pid) {
                continue;
            }

            if (!isset($map[$pid])) {
                $parent = wc_get_product($pid);
                if (!$parent) {
                    continue;
                }
                $map[$pid] = array(
                    'id' => $pid,
                    'name' => $parent->get_name(),
                );
            }
        }
    }

    $list = array_values($map);
    // Cache for 12 hours.
    set_transient($cache_key, $list, HOUR_IN_SECONDS * 12);

    return $list;
}

/**
 * Count total quantity of a PARENT product (all variations included) for a user,
 * optionally filtered by employee and date range.
 *
 * @param int   $user_id User ID.
 * @param array $args    { employee_id, product_id, from, to }.
 * @return array { total_qty, matched_orders, checked_orders, last_date, product_label }.
 */
function eppdp_count_product_parent_purchases_for_user($user_id, $args)
{
    $employee_id = isset($args['employee_id']) ? (string) $args['employee_id'] : '';
    $product_id = !empty($args['product_id']) ? (int) $args['product_id'] : 0;
    $from = isset($args['from']) ? (string) $args['from'] : '';
    $to = isset($args['to']) ? (string) $args['to'] : '';

    $q = array(
        'customer_id' => $user_id,
        'status' => array_keys(wc_get_order_statuses()),
        'limit' => -1,
        'paginate' => false,
        'return' => 'objects',
    );

    if ($employee_id) {
        $q['meta_query'] = array(
            array(
                'key' => '_billing_employee_id',
                'value' => $employee_id,
                'compare' => '=',
            ),
        );
    }

    // Date range (optional)
    if ( $from ) {
        $q['date_after']  = $from . ' 00:00:00';
    }
    if ( $to ) {
        $q['date_before'] = $to   . ' 23:59:59';
    }


    $orders         = wc_get_orders( $q );
    $total_qty      = 0;
    $matched_orders = 0;
    $checked_orders = count( $orders );
    $last_date      = '';
    $last_ts        = 0;
    $total_amount   = 0.0;
    $product_label  = $product_id ? get_the_title( $product_id ) : __( 'any product', 'eppdp' );

    foreach ( $orders as $order ) {
        $found                   = false;
        $order_amount_for_match  = 0.0;

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();

            if ( $product_id && $parent_id !== $product_id ) {
                continue;
            }

            $total_qty += (int) $item->get_quantity();

            $line_total            = (float) $item->get_total() + (float) $item->get_total_tax();
            $order_amount_for_match += $line_total;

            $found = true;
        }

        if ( $found ) {
            $matched_orders++;
            $total_amount += $order_amount_for_match;

            $dt = $order->get_date_created();
            if ( $dt instanceof WC_DateTime ) {
                $ts = $dt->getTimestamp();
                if ( empty( $last_ts ) || $ts > $last_ts ) {
                    $last_ts   = $ts;
                    $last_date = wc_format_datetime( $dt );
                }
            }
        }
    }

    return array(
        'total_qty'      => $total_qty,
        'matched_orders' => $matched_orders,
        'checked_orders' => $checked_orders,
        'last_date'      => $last_date,
        'product_label'  => $product_label,
        'total_amount'   => $total_amount, // <— NEW
    );

}


/**
 * Parse a free-text date range "YYYY-MM-DD – YYYY-MM-DD" (or single date).
 *
 * @param string $range Raw input.
 * @return array { from:string, to:string }
 */
if ( ! function_exists( 'eppdp_parse_date_range' ) ) {
    function eppdp_parse_date_range($range)
    {
        $range = trim((string) $range);
        if ('' === $range) {
            return array('', '');
        }

        // Normalize whitespace/dashes
        $norm = preg_replace('/\s+/', ' ', $range);

        // Accept YYYY-MM-DD or YYYY/MM/DD; separators can be -, –, —, "to"
        if (preg_match('/(\d{4}[-\/]\d{2}[-\/]\d{2}).*?(\d{4}[-\/]\d{2}[-\/]\d{2})/u', $norm, $m)) {
            $from = date('Y-m-d', strtotime(str_replace('/', '-', $m[1])));
            $to = date('Y-m-d', strtotime(str_replace('/', '-', $m[2])));
            // swap if reversed
            if ($to < $from) {
                list($from, $to) = array($to, $from);
            }
            return array($from, $to);
        }

        // Single date → same-day range
        if (preg_match('/^(\d{4}[-\/]\d{2}[-\/]\d{2})$/', $norm, $m)) {
            $d = date('Y-m-d', strtotime(str_replace('/', '-', $m[1])));
            return array($d, $d);
        }

        // Unrecognized → no date filter
        return array('', '');
    }
}