<?php
/**
 * Plugin Name: Itsy Tolkit
 * Description: Merged: preserves legacy behavior and adds Employee/PO gate, per-employee saved lists, and load & checkout.
 * Version:     1.1.3
 * Author:      KickAss Online
 * Text Domain: eppdp
 */

if (!defined('ABSPATH'))
    exit;

define('EPPDP_VERSION', '1.1.3');
define('EPPDP_PATH', plugin_dir_path(__FILE__));
define('EPPDP_URL', plugin_dir_url(__FILE__));

/**
 * 2) New feature pack class
 */
final class EPPDP_Plugin
{
    const TABLE = 'eppdp_employee_lists';
    const OPTION = 'eppdp_db_version';

    public static function init()
    {
        static $inst = null;
        if (null === $inst)
            $inst = new self();
        return $inst;
    }

    private function __construct()
    {
        // Activation
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Frontend UX
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('wp_footer', [$this, 'render_modal']);
        add_action('woocommerce_before_main_content', [$this, 'shop_banner'], 15);

        // Checkout field
        add_filter('woocommerce_checkout_fields', [$this, 'add_employee_checkout_field'], 10);
        // Write early (on object) and late (after others) so the NAME always wins
        add_action('woocommerce_checkout_create_order', [$this, 'save_employee_checkout_field'], 999, 2);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_employee_checkout_field_late'], 999, 2);

        // Show on frontend “Thank you” & “View order”
        add_action('woocommerce_order_details_after_order_table', [$this, 'display_employee_on_thankyou_page']);
        // Show in admin Order edit screen
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_employee_in_admin_order'], 10, 1);
        // Add to order emails
        add_action('woocommerce_email_order_meta', [$this, 'add_employee_to_order_email'], 10, 3);

        // Lock Employee Field Checkout
        add_filter('woocommerce_checkout_fields', [$this, 'lock_employee_field'], 20);


        // ← NEW: fallback for themes that don’t use WC hooks
        add_filter('the_content', [$this, 'prepend_banner_to_content'], 5);
        // Always print a footer banner on product archives if an employee is selected
        add_action('wp_footer', [$this, 'shop_banner_footer'], 20);


        // Cart control + stamping
        add_filter('woocommerce_add_to_cart_validation', [$this, 'block_add_to_cart_until_selected'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_meta'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'show_item_meta_in_cart'], 10, 2);

        // Persist to saved lists
        add_action('wp_ajax_eppdp_save_list', [$this, 'ajax_save_list']);
        add_action('wp_ajax_nopriv_eppdp_save_list', [$this, 'ajax_noauth']);
        add_action('wp_ajax_eppdp_load_list', [$this, 'ajax_load_list']);
        add_action('wp_ajax_nopriv_eppdp_load_list', [$this, 'ajax_noauth']);

        // Store selection (AJAX)
        add_action('wp_ajax_save_employee_po', [$this, 'ajax_save_employee_po']);
        add_action('wp_ajax_nopriv_save_employee_po', [$this, 'ajax_noauth']);

        // Clear selection (AJAX)
        add_action('wp_ajax_eppdp_clear_selection', [$this, 'ajax_clear_selection']);
        add_action('wp_ajax_nopriv_eppdp_clear_selection', [$this, 'ajax_noauth']);

        // Copy cart meta → order items
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'order_item_meta'], 10, 4);

        add_filter('woocommerce_checkout_get_value', [$this, 'force_checkout_values'], 20, 2);
        add_filter('woocommerce_checkout_posted_data', [$this, 'force_posted_values'], 20);

        // Update Billing Details Title
        add_filter('gettext', [$this, 'filter_wc_checkout_headings'], 20, 3);

        // Allow legacy to hook after we’re ready
        do_action('eppdp/bootstrapped', $this);
    }

    /* ---------- Activation: create table ---------- */
    public function activate()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            employee_id VARCHAR(191) NOT NULL,
            employee_name VARCHAR(191) NOT NULL,
            po_number VARCHAR(191) DEFAULT '' NOT NULL,
            items LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_emp (user_id, employee_id)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::OPTION, EPPDP_VERSION);
    }

    /* ---------- Employee source ---------- */
    protected function get_employees_for_user($user_id): array
    {
        $list = apply_filters('eppdp_employees_for_user', [], $user_id);
        return is_array($list) ? $list : [];
    }

    /* ---------- Session helpers ---------- */
    protected function set_selection($employee_id, $employee_name, $po)
    {
        WC()->session->set('eppdp_employee_id', $employee_id);
        WC()->session->set('eppdp_employee_name', $employee_name);
        WC()->session->set('eppdp_po', $po);
    }
    protected function get_selection(): array
    {
        return [
            'id' => WC()->session->get('eppdp_employee_id'),
            'name' => WC()->session->get('eppdp_employee_name'),
            'po' => WC()->session->get('eppdp_po'),
        ];
    }
    protected function has_selection(): bool
    {
        return (bool) WC()->session->get('eppdp_employee_id');
    }

    /* ---------- Assets & UI ---------- */
    public function assets()
    {
        if (!(is_shop() || is_post_type_archive('product') || is_product_taxonomy() || (function_exists('is_checkout') && is_checkout() && !is_order_received_page()))) {
            return;
        }

        wp_enqueue_style('eppdp-modal', EPPDP_URL . 'assets/css/modal.css', [], EPPDP_VERSION);

        // ✅ Select2 CSS/JS (prefer local, fallback to CDN)
        $local_select2_js = EPPDP_PATH . 'assets/vendor/select2/select2.min.js';
        $local_select2_css = EPPDP_PATH . 'assets/vendor/select2/select2.min.css';

        if (file_exists($local_select2_js) && file_exists($local_select2_css)) {
            wp_enqueue_style('select2', EPPDP_URL . 'assets/vendor/select2/select2.min.css', [], '4.1.0');
            wp_enqueue_script('select2', EPPDP_URL . 'assets/vendor/select2/select2.min.js', ['jquery'], '4.1.0', true);
        } else {
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        }

        // 🔁 Make modal.js depend on select2 so init runs in order
        wp_enqueue_script('eppdp-modal', EPPDP_URL . 'assets/js/modal.js', ['jquery', 'select2'], EPPDP_VERSION, true);

        wp_localize_script('eppdp-modal', 'eppdpData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eppdp_nonce'),
            'hasEmployee' => $this->has_selection(),
        ]);

        // Minor styles for the banner and button
        $css = '.eppdp-banner{margin:10px 0;padding:10px;background:#f6f7f7;border:1px solid #e2e2e2;border-radius:6px;font-size:14px;display:flex;gap:15px;align-items:center}.eppdp-save-list{margin-left:auto}';
        wp_add_inline_style('eppdp-modal', $css);

        // ✅ Initialize Select2 on the modal select
        $js = <<<'JS'
        jQuery(function($){

            $(document).on('click','.eppdp-save-list',function(e){
                e.preventDefault();
                var $b=$(this).prop('disabled',true);
                $.post(eppdpData.ajaxUrl,{action:'eppdp_save_list',nonce:eppdpData.nonce},function(res){
                if(res.success){ alert('Saved to lists for this employee.'); }
                else { alert(res.data||'Failed to save.'); }
                }).always(function(){ $b.prop('disabled',false); });
            });

            $(document).on('click', '.eppdp-clear-selection', function(e) {
                e.preventDefault();
                var $b = $(this).prop('disabled', true);
                $.post(eppdpData.ajaxUrl, {
                    action: 'eppdp_clear_selection',
                    nonce: eppdpData.nonce
                }, function(res) {
                    if (res.success) {
                        $('#eppdp-modal').show();
                        $('.eppdp-banner').remove();
                    } else {
                        alert(res.data || 'Failed to clear selection.');
                    }
                }).always(function() {
                    $b.prop('disabled', false);
                });
            });
        
        });
        JS;

        wp_add_inline_script('eppdp-modal', $js);
    }


    public function render_modal()
    {
        if (
            !(is_shop() || is_post_type_archive('product') || is_product_taxonomy())
            || !is_user_logged_in()
        ) {
            return;
        }
        $employees = $this->get_employees_for_user(get_current_user_id());
        $has = $this->has_selection();
        ?>
        <div id="eppdp-modal" class="employee-popup-overlay" style="<?php echo $has ? 'display:none' : 'display:flex'; ?>">
            <div class="employee-popup-dialog">
                <h2><?php esc_html_e('Select Employee', 'eppdp'); ?></h2>

                <label for="eppdp-employee-select"><?php esc_html_e('Employee', 'eppdp'); ?></label>
                <select id="eppdp-employee-select">
                    <option value=""><?php esc_html_e('— Choose —', 'eppdp'); ?></option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo esc_attr($emp['id']); ?>">
                            <?php //echo esc_html($emp['name'] . ' (' . $emp['id'] . ')'); ?>
                            <?php echo esc_html($emp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="eppdp-po-input"
                    style="margin-top:.5rem"><?php esc_html_e('PO Number (optional)', 'eppdp'); ?></label>
                <input id="eppdp-po-input" type="text" value="" />

                <button id="eppdp-submit-btn" class="button button-primary" style="margin-top:1rem">
                    <?php esc_html_e('Save & Continue', 'eppdp'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    public function shop_banner()
    {
        if (
            !is_user_logged_in()
            || !$this->has_selection()
            || !(is_shop()
                || is_post_type_archive('product')
                || is_product_taxonomy()
            )
        ) {
            return;
        }

        $sel = $this->get_selection();
        ?>
        <div class="eppdp-banner">
            <span><strong><?php esc_html_e('Employee:', 'eppdp'); ?></strong>
                <?php // echo esc_html($sel['name'] . ' (' . $sel['id'] . ')'); ?>
                <?php echo esc_html($sel['name']); ?>
            </span>

            <?php if (!empty($sel['po'])): ?>
                <span><strong><?php esc_html_e('PO:', 'eppdp'); ?></strong>
                    <?php echo esc_html($sel['po']); ?>
                </span>
            <?php endif; ?>

            <a href="#" class="button eppdp-clear-selection" title="<?php esc_attr_e('Change employee', 'eppdp'); ?>">×</a>
            <a href="#" class="button eppdp-save-list"><?php esc_html_e('Save this list', 'eppdp'); ?></a>
        </div>
        <?php
    }

    /**
     * Fallback: inject banner at the top of the Shop page content
     */
    public function prepend_banner_to_content($content)
    {
        if (
            !is_user_logged_in()
            || !$this->has_selection()
            // only on Shop page OR any product archive or taxonomy
            || !(
                is_page(wc_get_page_id('shop'))
                || is_post_type_archive('product')
                || is_product_taxonomy()
            )
            || !is_main_query()
            || !in_the_loop()
        ) {
            return $content;
        }

        ob_start();
        $this->shop_banner();
        $banner = ob_get_clean();

        return $banner . $content;
    }

    /**
     * Floating footer banner with Employee + PO selector
     */
    public function shop_banner_footer()
    {
        if (
            !is_user_logged_in()
            || !$this->has_selection()
            || !(
                is_shop()
                || is_post_type_archive('product')
                || is_product_taxonomy()
                || (function_exists('is_checkout') && is_checkout() && !is_order_received_page())
            )
        ) {
            return;
        }

        // grab current selection + full employee list
        $sel = $this->get_selection();
        $employees = $this->get_employees_for_user(get_current_user_id());
        ?>
        <div class="eppdp-footer-banner">
            <div class="eppdp-footer-inner">
                <label for="eppdp-footer-employee-select"
                    class="screen-reader-text"><?php esc_html_e('Employee', 'eppdp'); ?></label>
                <select id="eppdp-footer-employee-select" style="min-width:200px;">
                    <option value=""><?php esc_html_e('— Choose Employee —', 'eppdp'); ?></option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo esc_attr($emp['id']); ?>" <?php selected($sel['id'], $emp['id']); ?>>
                            <?php echo esc_html($emp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="eppdp-footer-po-input" class="screen-reader-text"><?php esc_html_e('PO Number', 'eppdp'); ?></label>
                <input id="eppdp-footer-po-input" type="text" placeholder="<?php esc_attr_e('PO # (optional)', 'eppdp'); ?>"
                    value="<?php echo esc_attr($sel['po']); ?>" style="width:150px; line-height: 1.6; margin-left:.5rem;" />

                <button id="eppdp-footer-save-btn" class="button"
                    style="margin-left:.5rem;"><?php esc_html_e('Save', 'eppdp'); ?></button>

                <button class="eppdp-clear-selection-footer" title="<?php esc_attr_e('Change employee', 'eppdp'); ?>"
                    style="margin-left: 1rem;font-size: 24px;line-height: 1.5;background: none;border: none;cursor: pointer;">
                    &times;
                </button>
            </div>
        </div>

        <script>
            jQuery(function ($) {
                // init Select2 on the footer select
                var $fsel = $('#eppdp-footer-employee-select');

                if ($.fn.select2 && !$fsel.hasClass('select2-hidden-accessible')) {
                    $fsel.select2({
                        placeholder: 'Search employee…',
                        allowClear: true,
                        width: 'resolve',
                        dropdownAutoWidth: true
                    });
                }

                function positionFooterSelect2() {
                    var $dd = $('.select2-container--open .select2-dropdown');
                    if (!$dd.length) return;

                    var rect = $fsel[0].getBoundingClientRect();
                    var ddH = $dd.outerHeight();
                    var spaceBelow = window.innerHeight - rect.bottom;
                    var GAP = 20; // <- adjust this to taste

                    $dd.css({
                        position: 'fixed',
                        left: Math.round(rect.left) + 'px',
                        width: Math.round(rect.width) + 'px',
                        zIndex: 100001
                    });

                    if (spaceBelow < ddH) {
                        // open upward, leave a little gap above the select
                        $dd.css({
                            top: '',
                            bottom: Math.round(window.innerHeight - rect.top) + GAP + 'px'
                        }).addClass('eppdp-open-above');
                    } else {
                        // open downward, leave a little gap below the select
                        $dd.css({
                            top: Math.round(rect.bottom) + GAP + 'px',
                            bottom: ''
                        }).removeClass('eppdp-open-above');
                    }
                }


                $fsel.on('select2:open', function () {
                    positionFooterSelect2();
                    $(window).on('scroll.eppdp resize.eppdp', positionFooterSelect2);
                }).on('select2:close', function () {
                    $(window).off('scroll.eppdp resize.eppdp');
                });


                // Save new selection
                $('#eppdp-footer-save-btn').on('click', function (e) {
                    e.preventDefault();
                    var emp = $fsel.val(),
                        po = $('#eppdp-footer-po-input').val().trim();
                    if (!emp) {
                        alert('Please choose an employee.');
                        return;
                    }
                    $.post(eppdpData.ajaxUrl, {
                        action: 'save_employee_po',
                        nonce: eppdpData.nonce,
                        employee: emp,
                        po: po
                    }, function (res) {
                        if (res.success) {
                            if ($('form.checkout').length) {
                                var empId = res.data && res.data.employee ? res.data.employee : $('#eppdp-footer-employee-select').val();
                                var empText = $('#eppdp-footer-employee-select option:selected').text();

                                // Update the checkout field to reflect the new selection
                                var $be = $('#billing_employee');
                                if ($be.length) {
                                    $be.prop('disabled', false);               // momentarily enable so we can set it
                                    $be.empty().append(new Option(empText, empId, true, true)).trigger('change');
                                    $be.prop('disabled', true);                // re-lock if you want it locked
                                }

                                // Keep PO in sync if you show it on checkout
                                $('#po_number_option').val($('#eppdp-footer-po-input').val());

                                // Recalc totals/shipping just in case
                                $(document.body).trigger('update_checkout');
                            }

                            location.reload();
                        } else {
                            alert(res.data || 'Error saving selection.');
                        }
                    });
                });

                // Clear selection
                $('.eppdp-clear-selection-footer').on('click', function (e) {
                    e.preventDefault();
                    $.post(eppdpData.ajaxUrl, {
                        action: 'eppdp_clear_selection',
                        nonce: eppdpData.nonce
                    }, function () {
                        location.reload();
                    });
                });
            });
        </script>
        <?php
    }



    /* ---------- Clear selection (AJAX) ---------- */
    public function ajax_clear_selection()
    {
        check_ajax_referer('eppdp_nonce', 'nonce');
        WC()->session->__unset('eppdp_employee_id');
        WC()->session->__unset('eppdp_employee_name');
        WC()->session->__unset('eppdp_po');
        wp_send_json_success();
    }


    /* ---------- Cart control & stamping ---------- */
    public function block_add_to_cart_until_selected($passed, $product_id, $qty)
    {
        if (!$this->has_selection()) {
            wc_add_notice(__('Please select an employee before adding items.', 'eppdp'), 'error');
            return false;
        }
        return $passed;
    }

    public function add_cart_item_meta($cart_item_data, $product_id, $variation_id)
    {
        $sel = $this->get_selection();
        if (!empty($sel['id'])) {
            $cart_item_data['eppdp_employee_id'] = $sel['id'];
            $cart_item_data['eppdp_employee_name'] = $sel['name'];
            if (!empty($sel['po']))
                $cart_item_data['eppdp_po'] = $sel['po'];
        }
        return $cart_item_data;
    }

    public function show_item_meta_in_cart($item_data, $cart_item)
    {
        if (!empty($cart_item['eppdp_employee_name'])) {
            $item_data[] = [
                'name' => __('Employee', 'eppdp'),
                // 'value' => esc_html($cart_item['eppdp_employee_name'] . ' (' . $cart_item['eppdp_employee_id'] . ')'),
                'value' => esc_html($cart_item['eppdp_employee_name']),
            ];
        }

        // TODO: REMOVE this whole PO block:
        // if (!empty($cart_item['eppdp_po'])) {
        //     $item_data[] = [
        //         'name' => __('PO', 'eppdp'),
        //         'value' => esc_html($cart_item['eppdp_po']),
        //     ];
        // }
        return $item_data;
    }


    /**
     * 1) Add & prefill the Employee select to checkout fields
     */
    public function add_employee_checkout_field($fields)
    {
        if (!is_user_logged_in()) {
            return $fields;
        }

        // get saved session selection
        $sel = $this->get_selection(); // [ 'id' => 'EMP123', 'name' => 'Alice', 'po' => '' ]

        // build options map [ id => "Name (ID)" ]
        $user_id = get_current_user_id();
        $employees = $this->get_employees_for_user($user_id);
        $options = ['' => __('Select an employee…', 'eppdp')];
        foreach ($employees as $emp) {
            // $options[ $emp['id'] ] = sprintf( '%s (%s)', $emp['name'], $emp['id'] );
            $options[$emp['id']] = $emp['name'];
        }

        $fields['billing']['billing_employee'] = [
            'type' => 'select',
            'label' => __('Employee?', 'eppdp'),
            'required' => true,
            'class' => ['form-row-wide'],
            'options' => $options,
            // **here** we prefill from session
            'default' => $sel['id'] ?: '',
        ];

        return $fields;
    }


    /**
     * 2) Save the posted Employee into order meta
     */
    // Runs while the order object is being created
    public function save_employee_checkout_field($order, $data)
    {
        $sel = $this->get_selection();
        if (!empty($sel['id'])) {
            $order->update_meta_data('_billing_employee_id', $sel['id']);
            $order->update_meta_data('_billing_employee_name', $sel['name']);
            // canonical meta shows the NAME everywhere
            $order->update_meta_data('_billing_employee', $sel['name']);
        }
    }

    // Runs after other plugins may have touched meta; ensure NAME wins
    public function save_employee_checkout_field_late($order_id)
    {
        $name = get_post_meta($order_id, '_billing_employee_name', true);
        if ($name) {
            update_post_meta($order_id, '_billing_employee', $name);
        }
    }

    private function resolve_employee_name_for_order(WC_Order $order): string
    {
        // prefer explicit name meta
        $name = (string) $order->get_meta('_billing_employee_name');
        if ($name)
            return $name;

        // fallback: if _billing_employee contains an ID, try map to name
        $val = (string) $order->get_meta('_billing_employee');
        if (!$val)
            return '';

        $user_id = (int) $order->get_user_id();
        if ($user_id) {
            foreach ($this->get_employees_for_user($user_id) as $emp) {
                if (isset($emp['id']) && $emp['id'] === $val) {
                    return (string) $emp['name'];
                }
            }
        }
        // if mapping fails, return whatever is stored
        return $val;
    }

    public function display_employee_on_thankyou_page($order)
    {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order)
            return;

        $name = $this->resolve_employee_name_for_order($order);
        if ($name) {
            echo '<p><strong>' . esc_html__('Employee Name:', 'eppdp') . '</strong> ' . esc_html($name) . '</p>';
        }
    }

    public function display_employee_in_admin_order($order)
    {
        $name = $this->resolve_employee_name_for_order($order);
        if ($name) {
            echo '<p><strong>' . esc_html__('Employee Name:', 'eppdp') . '</strong> ' . esc_html($name) . '</p>';
        }
    }
    public function add_employee_to_order_email($order, $sent_to_admin, $plain_text)
    {
        $name = $this->resolve_employee_name_for_order($order);
        if (!$name)
            return;

        if ($plain_text) {
            echo "\n" . __('Employee Name:', 'eppdp') . ' ' . $name . "\n";
        } else {
            echo '<p><strong>' . esc_html__('Employee Name:', 'eppdp') . '</strong> ' . esc_html($name) . '</p>';
        }
    }


    /**
     * Prefill & lock the Employee select in Billing
     */
    public function lock_employee_field($fields)
    {
        if (!is_user_logged_in() || !$this->has_selection()) {
            return $fields;
        }

        $sel = $this->get_selection();

        // we simply override the one select option to only the chosen employee
        $fields['billing']['billing_employee']['options'] = [
            // $sel['id'] => sprintf( '%s (%s)', $sel['name'], $sel['id'] )
            $sel['id'] => $sel['name']
        ];
        $fields['billing']['billing_employee']['default'] = $sel['id'];
        $fields['billing']['billing_employee']['custom_attributes'] = [
            'disabled' => 'disabled',
        ];

        // Append a hidden fallback field *with the same name*
        // so the browser still POSTs the value.
        $fields['billing']['billing_employee_hidden'] = [
            'type' => 'hidden',
            'default' => $sel['id'],
            // crucially: must be named the same key
            'name' => 'billing_employee',
        ];

        return $fields;
    }



    /* ---------- AJAX ---------- */
    public function ajax_noauth()
    {
        wp_send_json_error('Login required.', 401);
    }

    public function ajax_save_employee_po()
    {
        check_ajax_referer('eppdp_nonce', 'nonce');
        if (!is_user_logged_in()) {
            return $this->ajax_noauth();
        }

        // 1) Capture old selection & its cart
        $old_emp_id = WC()->session->get('eppdp_employee_id');
        $all_by_emp = WC()->session->get('eppdp_cart_by_employee', []);

        if ($old_emp_id) {
            // grab *all* current cart line items
            $all_by_emp[$old_emp_id] = WC()->cart->get_cart();
            // store back to session
            WC()->session->set('eppdp_cart_by_employee', $all_by_emp);
        }

        // 2) Process new selection
        $user_id = get_current_user_id();
        $new_emp = sanitize_text_field($_POST['employee'] ?? '');
        $po = sanitize_text_field($_POST['po'] ?? '');

        // validate employee
        $valid = false;
        $emp_name = '';
        foreach ($this->get_employees_for_user($user_id) as $emp) {
            if ($emp['id'] === $new_emp) {
                $valid = true;
                $emp_name = $emp['name'];
                break;
            }
        }
        if (!$valid) {
            wp_send_json_error('Invalid employee.', 400);
        }

        $this->set_selection($new_emp, $emp_name, $po);

        WC()->cart->empty_cart();
        $all_by_emp = WC()->session->get('eppdp_cart_by_employee', []);
        if (!empty($all_by_emp[$new_emp]) && is_array($all_by_emp[$new_emp])) {
            foreach ($all_by_emp[$new_emp] as $item_key => $ci) {
                
                $cart_item_data = array_filter([
                    'eppdp_employee_id' => $ci['eppdp_employee_id'] ?? $new_emp,
                    'eppdp_employee_name' => $ci['eppdp_employee_name'] ?? $emp_name,
                    'eppdp_po' => $ci['eppdp_po'] ?? $po,
                ]);
                WC()->cart->add_to_cart(
                    $ci['product_id'],
                    $ci['quantity'],
                    $ci['variation_id'],
                    [],
                    $cart_item_data
                );
            }
        }

        wp_send_json_success(['employee' => $new_emp, 'po' => $po]);
    }



    public function ajax_save_list()
    {
        check_ajax_referer('eppdp_nonce', 'nonce');
        if (!is_user_logged_in())
            $this->ajax_noauth();
        if (WC()->cart->is_empty())
            wp_send_json_error('Cart is empty', 400);

        $sel = $this->get_selection();
        if (empty($sel['id']))
            wp_send_json_error('No employee selected', 400);

        $items = [];
        foreach (WC()->cart->get_cart() as $ci) {
            if (($ci['eppdp_employee_id'] ?? '') !== $sel['id'])
                continue;
            $items[] = [
                'product_id' => (int) ($ci['product_id'] ?? 0),
                'variation_id' => (int) ($ci['variation_id'] ?? 0),
                'quantity' => (int) ($ci['quantity'] ?? 1),
                'meta' => [
                    'employee_id' => $ci['eppdp_employee_id'] ?? '',
                    'employee_name' => $ci['eppdp_employee_name'] ?? '',
                    'po' => $ci['eppdp_po'] ?? '',
                ],
            ];
        }
        if (empty($items))
            wp_send_json_error('Nothing to save for this employee.', 400);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $ok = $wpdb->insert($table, [
            'user_id' => get_current_user_id(),
            'employee_id' => $sel['id'],
            'employee_name' => $sel['name'],
            'po_number' => $sel['po'] ?? '',
            'items' => wp_json_encode($items),
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%s']);

        if (false === $ok)
            wp_send_json_error('DB error saving list.');
        wp_send_json_success(['id' => $wpdb->insert_id]);
    }

    public function ajax_load_list()
    {
        check_ajax_referer('eppdp_nonce', 'nonce');
        if (!is_user_logged_in())
            $this->ajax_noauth();

        $list_id = absint($_POST['list_id'] ?? 0);
        if (!$list_id)
            wp_send_json_error('Missing list id', 400);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id=%d AND user_id=%d",
            $list_id,
            get_current_user_id()
        ), ARRAY_A);
        if (!$row)
            wp_send_json_error('List not found', 404);

        // Set session from list
        $this->set_selection($row['employee_id'], $row['employee_name'], $row['po_number']);

        // Reset cart then re-add
        WC()->cart->empty_cart();
        $items = json_decode($row['items'], true) ?: [];
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            $vid = (int) ($it['variation_id'] ?? 0);
            $qty = max(1, (int) $it['quantity']);
            $meta = [
                'eppdp_employee_id' => $row['employee_id'],
                'eppdp_employee_name' => $row['employee_name'],
            ];
            if (!empty($row['po_number']))
                $meta['eppdp_po'] = $row['po_number'];
            WC()->cart->add_to_cart($pid, $qty, $vid, [], $meta);
        }

        wp_send_json_success(['redirect' => wc_get_checkout_url()]);
    }

    /* ---------- Order item meta ---------- */
    public function order_item_meta($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['eppdp_employee_name'])) {
            // $item->add_meta_data(__('Employee', 'eppdp'), $values['eppdp_employee_name'] . ' (' . $values['eppdp_employee_id'] . ')', true);
            $item->add_meta_data(__('Employee', 'eppdp'), $values['eppdp_employee_name'], true);
        }
        // TODO:REMOVE this whole PO block:
        // if (!empty($values['eppdp_po'])) {
        //     $item->add_meta_data(__('PO', 'eppdp'), $values['eppdp_po'], true);
        // }
    }

    public function force_checkout_values($value, $key)
    {
        $sel = $this->get_selection();

        if ('billing_employee' === $key && !empty($sel['id'])) {
            return $sel['id']; // always show the current employee in the field
        }
        if ('po_number_option' === $key && isset($sel['po'])) {
            return $sel['po']; // keep PO synced too (if you use that field)
        }
        return $value;
    }

    public function force_posted_values($data)
    {
        $sel = $this->get_selection();

        if (!empty($sel['id'])) {
            $data['billing_employee'] = $sel['id']; // always post the current employee
        }
        if (isset($sel['po'])) {
            $data['po_number_option'] = $sel['po']; // optional: keep PO in step
        }
        return $data;
    }



    /**
     * Replace WooCommerce checkout billing heading.
     */
    public function filter_wc_checkout_headings($translated, $text, $domain)
    {
        if ('woocommerce' === $domain && function_exists('is_checkout') && is_checkout()) {
            if ('Billing details' === $text || 'Billing & Shipping' === $text) {
                return __('Employee Name', 'eppdp');
            }
        }
        return $translated;
    }

}

// 1) Track the Delivery/Collection choice during checkout refreshes
add_action('woocommerce_checkout_update_order_review', function ($post_data) {
    parse_str($post_data, $data);
    if (isset($data['delivery_collection_option'])) {
        WC()->session->set(
            'eppdp_delivery_choice',
            wc_clean($data['delivery_collection_option'])
        );
    }
});

// Helper: are we doing delivery right now?
function eppdp_is_delivery_mode(): bool
{
    $choice = WC()->session->get('eppdp_delivery_choice');
    // Treat anything other than 'delivery' as collection (no address needed)
    return ($choice === 'delivery');
}

// 3) If any validation errors sneak in, drop the shipping ones in collection mode
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (eppdp_is_delivery_mode()) {
        return;
    }
    // Remove the generic "Please enter an address" error
    if (isset($errors->errors['shipping'])) {
        unset($errors->errors['shipping']);
    }
    // Remove individual shipping_* field errors
    foreach ($errors->get_error_codes() as $code) {
        if (strpos($code, 'shipping_') === 0) {
            $errors->remove($code);
        }
    }
}, 10, 2);

// Load employees data helpers early (filter + helper)
require_once EPPDP_PATH . 'includes/employees-data.php';

// Load UI and legacy pieces after WooCommerce is available
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        require_once EPPDP_PATH . 'includes/frontend/orders-filters.php';
        require_once EPPDP_PATH . 'includes/employees-manager.php';
        require_once EPPDP_PATH . 'includes/legacy-compat.php';
        require_once EPPDP_PATH . 'includes/checkout-legacy.php';
    }
}, 20);

register_activation_hook(__FILE__, function () {
    // make sure endpoint is recognized immediately
    add_rewrite_endpoint('employee', EP_ROOT | EP_PAGES);
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

EPPDP_Plugin::init();
