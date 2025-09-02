<?php
defined('ABSPATH') || exit;

/**
 * Legacy AJAX (not hooked; exists for BC only)
 */
if (!function_exists('eppdp_save_employee_po')) {
    function eppdp_save_employee_po()
    {
        check_ajax_referer('eppdp_nonce', 'nonce');

        if (isset($_POST['employee'])) {
            WC()->session->set('selected_employee', sanitize_text_field(wp_unslash($_POST['employee'])));
        }
        if (isset($_POST['po'])) {
            WC()->session->set('entered_po', sanitize_text_field(wp_unslash($_POST['po'])));
        }
        wp_send_json_success();
    }
}
