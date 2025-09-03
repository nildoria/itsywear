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
            $emp = sanitize_text_field(wp_unslash($_POST['employee']));

            // Persist & swap using the new helpers.
            eppdp_switch_cart_to_employee($emp); // sets session+cookie+user_meta and loads that employee's saved cart
        }

        if (isset($_POST['po'])) {
            WC()->session->set('entered_po', sanitize_text_field(wp_unslash($_POST['po'])));
        }

        // One more save to be safe.
        eppdp_save_cart_for_current_employee();

        wp_send_json_success();
    }
}
