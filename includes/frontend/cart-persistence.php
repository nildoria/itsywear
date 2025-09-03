<?php
defined('ABSPATH') || exit;

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

/**
 * ==========================================================
 *  Per-Employee Cart Persistence (7 days, per logged-in user)
 * ==========================================================
 * Keys & constants
 */
const EPPDP_USER_CARTS_META = '_eppdp_employee_carts_v1'; // user_meta array store
const EPPDP_EMP_COOKIE = 'eppdp_emp';                 // last selected employee id
const EPPDP_CART_COOKIE_DAYS = 7;                           // cookie lifetime (UI convenience)
const EPPDP_TTL_SECONDS = 7 * DAY_IN_SECONDS;          // expiration window for saved carts

// Prevent saving while we empty/restore internally
function eppdp_suspend_save(bool $on = true): void
{
    if (WC()->session) {
        WC()->session->set('eppdp_no_save', $on ? 1 : 0);
    }
}
function eppdp_is_save_suspended(): bool
{
    return WC()->session ? (bool) WC()->session->get('eppdp_no_save', 0) : false;
}

/**
 * Employee from session ONLY (authoritative for cart behavior).
 */
function eppdp_get_employee_from_session(): string
{
    if (WC()->session) {
        $emp = (string) WC()->session->get('selected_employee', '');
        if ($emp)
            return $emp;
        $emp = (string) WC()->session->get('eppdp_employee_id', '');
        if ($emp)
            return $emp;
    }
    return '';
}

/**
 * Employee from cookie (UI only). Do NOT use for cart restore.
 */
function eppdp_get_employee_from_cookie(): string
{
    return isset($_COOKIE[EPPDP_EMP_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[EPPDP_EMP_COOKIE])) : '';
}

/**
 * ----------------------------------------------------------
 * Utilities: get/set selected employee id (session → cookie)
 * ----------------------------------------------------------
 */
function eppdp_get_selected_employee_id(): string
{
    $emp = eppdp_get_employee_from_session();
    if ($emp !== '')
        return $emp;
    return eppdp_get_employee_from_cookie(); // UI convenience only
}

/**
 * If no employee selected in SESSION, block WC persistent cart
 * from showing items on login/new session (keeps cart empty until user chooses).
 */
function eppdp_gate_cart_until_employee(): void
{
    if (!WC()->cart)
        return;
    $emp = eppdp_get_employee_from_session();
    if ($emp === '') {
        eppdp_suspend_save(true);       // ⬅️ block saver
        WC()->cart->empty_cart();       // keeps cart empty until user selects
        eppdp_suspend_save(false);      // ⬅️ unblock saver
    }
}
add_action('woocommerce_cart_loaded_from_session', 'eppdp_gate_cart_until_employee', 5);


function eppdp_set_selected_employee_id(string $emp_id): void
{
    if (WC()->session) {
        // Keep legacy keys for compat with your UI
        WC()->session->set('selected_employee', $emp_id);
        WC()->session->set('eppdp_employee_id', $emp_id);
    }
    // Convenience cookie so the footer/banner knows the last selection across visits
    setcookie(EPPDP_EMP_COOKIE, $emp_id, time() + (EPPDP_CART_COOKIE_DAYS * DAY_IN_SECONDS), COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
}


/**
 * ----------------------------------------------------------
 * User-meta payload structure
 * [
 *   'carts' => [
 *       'EMP123' => [
 *           'items'     => [ [ product_id, variation_id, qty, variation, meta ] ... ],
 *           'modified'  => 1693580000,   // unix timestamp, rolling update
 *       ],
 *       'EMP456' => [ ... ],
 *   ],
 *   'version' => 1
 * ]
 * ----------------------------------------------------------
 */
function eppdp_get_all_saved_carts(int $user_id): array
{
    $data = get_user_meta($user_id, EPPDP_USER_CARTS_META, true);
    return is_array($data) ? $data : ['carts' => [], 'version' => 1];
}
function eppdp_put_all_saved_carts(int $user_id, array $data): void
{
    if (!isset($data['carts']) || !is_array($data['carts']))
        $data['carts'] = [];
    $data['version'] = 1;
    update_user_meta($user_id, EPPDP_USER_CARTS_META, $data);
}
function eppdp_prune_expired_carts(array $data): array
{
    $now = time();
    foreach (($data['carts'] ?? []) as $emp => $entry) {
        $modified = (int) ($entry['modified'] ?? 0);
        if (!$modified || ($now - $modified) > EPPDP_TTL_SECONDS) {
            unset($data['carts'][$emp]);
        }
    }
    return $data;
}

/**
 * ----------------------------------------------------------
 * Export current WC cart to a portable snapshot
 * ----------------------------------------------------------
 */
function eppdp_export_wc_cart_snapshot(): array
{
    $items = [];
    $cart = WC()->cart ? WC()->cart->get_cart() : [];
    foreach ($cart as $key => $ci) {
        $product_id = (int) ($ci['product_id'] ?? 0);
        $variation_id = (int) ($ci['variation_id'] ?? 0);
        $qty = (float) ($ci['quantity'] ?? 1);
        $variation = is_array($ci['variation'] ?? null) ? $ci['variation'] : [];
        $item_data = is_array($ci['item_meta'] ?? null) ? $ci['item_meta'] : []; // your custom flags if any

        // Keep only safe meta you actually need to restore grouping/labels.
        // If you stamp employee/po per item in add_cart_item_data, WC already carries it.

        $items[] = [
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'qty' => $qty,
            'variation' => $variation,
            'meta' => $item_data,
        ];
    }
    return $items;
}

/**
 * ----------------------------------------------------------
 * Load a snapshot into the live WC cart (replaces content)
 * ----------------------------------------------------------
 */
function eppdp_load_snapshot_into_cart(array $snapshot): void
{
    if (!WC()->cart)
        return;

    eppdp_suspend_save(true);          // ⬅️ block saver during our mutation

    WC()->cart->empty_cart();
    foreach (($snapshot['items'] ?? []) as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        $vid = (int) ($row['variation_id'] ?? 0);
        $qty = max(0.001, (float) ($row['qty'] ?? 1));
        $var = is_array($row['variation'] ?? null) ? $row['variation'] : [];
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        if ($pid > 0) {
            WC()->cart->add_to_cart($pid, $qty, $vid, $var, $meta);
        }
    }
    WC()->cart->calculate_totals();

    eppdp_suspend_save(false);         // ⬅️ re-enable saver
    eppdp_save_current_employee_cart();// ⬅️ persist the restored snapshot to meta
}


/**
 * ----------------------------------------------------------
 * Save the live cart into user_meta under the current employee
 * ----------------------------------------------------------
 */
function eppdp_save_current_employee_cart(): void
{
    if (!is_user_logged_in() || !WC()->cart)
        return;
    if (eppdp_is_save_suspended())
        return;   // ⬅️ don't overwrite during restore/gate

    $emp = eppdp_get_employee_from_session(); // session-only
    if ($emp === '')
        return;

    $uid = get_current_user_id();
    $data = eppdp_prune_expired_carts(eppdp_get_all_saved_carts($uid));

    $data['carts'][$emp] = [
        'items' => eppdp_export_wc_cart_snapshot(),
        'modified' => time(),
    ];
    eppdp_put_all_saved_carts($uid, $data);
}


/**
 * ----------------------------------------------------------
 * Restore cart when employee is known (fresh session / login)
 * ----------------------------------------------------------
 */
function eppdp_maybe_restore_employee_cart(): void
{
    if (!is_user_logged_in() || !WC()->cart)
        return;

    // Only trust SESSION for cart restore (cookie is UI-only)
    $emp = eppdp_get_employee_from_session();
    if ($emp === '')
        return;

    $uid = get_current_user_id();
    $data = eppdp_prune_expired_carts(eppdp_get_all_saved_carts($uid));
    if (empty($data['carts'][$emp]))
        return;

    eppdp_load_snapshot_into_cart((array) $data['carts'][$emp]);
}


/**
 * ----------------------------------------------------------
 * Clear employee’s saved cart after successful checkout
 * ----------------------------------------------------------
 */
function eppdp_clear_employee_after_checkout($order_id): void
{
    if (!is_user_logged_in())
        return;
    $emp = eppdp_get_selected_employee_id();
    if ($emp === '')
        return;

    $uid = get_current_user_id();
    $data = eppdp_get_all_saved_carts($uid);
    if (isset($data['carts'][$emp])) {
        unset($data['carts'][$emp]);
        eppdp_put_all_saved_carts($uid, $data);
    }
}

/**
 * ----------------------------------------------------------
 * Hooks
 * ----------------------------------------------------------
 */

// Additionally catch common change points
add_action('woocommerce_add_to_cart', 'eppdp_save_current_employee_cart', 20);
add_action('woocommerce_cart_item_removed', 'eppdp_save_current_employee_cart', 20);
add_action('woocommerce_after_cart_item_quantity_update', 'eppdp_save_current_employee_cart', 20, 2);
add_action('woocommerce_applied_coupon', 'eppdp_save_current_employee_cart', 20);
add_action('woocommerce_removed_coupon', 'eppdp_save_current_employee_cart', 20);

// 2) On session bootstrap, if a selected employee exists for a logged-in user, restore.
add_action('woocommerce_cart_loaded_from_session', 'eppdp_maybe_restore_employee_cart', 1000);

// 3) After a successful order, clear that employee’s stored snapshot.
add_action('woocommerce_thankyou', 'eppdp_clear_employee_after_checkout', 10);

// 4) Helper: if you set selected employee via your AJAX, also call eppdp_maybe_restore_employee_cart() post-set.
//    (Do this inside your existing `save_employee_po` AJAX handler AFTER eppdp_set_selected_employee_id($emp).)
