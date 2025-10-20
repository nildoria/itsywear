<?php
defined('ABSPATH') || exit;

/**
 * ==========================================================
 * Per-Employee Cart Persistence (365 days, per logged-in user)
 * ==========================================================
 * This module:
 * - Stores a per-employee cart snapshot in usermeta.
 * - Keeps a 365-day TTL for both cookie + snapshots.
 * - On employee switch, saves current employee's cart, then
 *   restores ONLY the new employee's items (strict replace).
 * - Gates the live cart until an employee is selected.
 */

/** ---- Constants ---- */
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('EPPDP_USER_CARTS_META')) {
    define('EPPDP_USER_CARTS_META', '_eppdp_employee_carts_v1');
}
if (!defined('EPPDP_EMP_COOKIE')) {
    define('EPPDP_EMP_COOKIE', 'eppdp_emp');
}
if (!defined('EPPDP_CART_COOKIE_DAYS')) {
    define('EPPDP_CART_COOKIE_DAYS', 365);
}
if (!defined('EPPDP_TTL_SECONDS')) {
    define('EPPDP_TTL_SECONDS', 365 * DAY_IN_SECONDS);
}

/** ---- Selected employee helpers (canonical) ---- */
function eppdp_get_employee_from_session(): string
{
    if (function_exists('WC') && WC()->session) {
        $v = (string) WC()->session->get('eppdp_employee_id', '');
        if ($v !== '') return $v;
        // legacy compat (older code paths)
        $v = (string) WC()->session->get('selected_employee', '');
        if ($v !== '') return $v;
    }
    return '';
}

function eppdp_get_employee_from_cookie(): string
{
    return isset($_COOKIE[EPPDP_EMP_COOKIE])
        ? sanitize_text_field(wp_unslash($_COOKIE[EPPDP_EMP_COOKIE]))
        : '';
}

/** UI can read cookie, logic uses session; cookie is convenience only */
function eppdp_get_selected_employee_id(): string
{
    $emp = eppdp_get_employee_from_session();
    return ($emp !== '') ? $emp : eppdp_get_employee_from_cookie();
}

function eppdp_set_selected_employee_id(string $emp_id): void
{
    $emp_id = sanitize_text_field($emp_id);

    if (function_exists('WC') && WC()->session) {
        // Keep both keys for compatibility
        WC()->session->set('eppdp_employee_id', $emp_id);
        WC()->session->set('selected_employee', $emp_id);
    }
    if (!headers_sent()) {
        setcookie(
            EPPDP_EMP_COOKIE,
            $emp_id,
            time() + (EPPDP_CART_COOKIE_DAYS * DAY_IN_SECONDS),
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    }
}

function eppdp_clear_selected_employee_id(): void
{
    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('eppdp_employee_id');
        WC()->session->__unset('selected_employee');
    }
    if (!headers_sent() && isset($_COOKIE[EPPDP_EMP_COOKIE])) {
        setcookie(
            EPPDP_EMP_COOKIE,
            '',
            time() - 3600,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        unset($_COOKIE[EPPDP_EMP_COOKIE]);
    }
}

/** Optional: refresh cookie TTL (keeps it alive for 365d) */
add_action('init', function () {
    if (!headers_sent() && isset($_COOKIE[EPPDP_EMP_COOKIE])) {
        setcookie(
            EPPDP_EMP_COOKIE,
            sanitize_text_field(wp_unslash($_COOKIE[EPPDP_EMP_COOKIE])),
            time() + (EPPDP_CART_COOKIE_DAYS * DAY_IN_SECONDS),
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    }
});

/** ---- Save/restore reentrancy guard ---- */
function eppdp_suspend_save(bool $on = true): void
{
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('eppdp_no_save', $on ? 1 : 0);
    }
}
function eppdp_is_save_suspended(): bool
{
    return (function_exists('WC') && WC()->session)
        ? (bool) WC()->session->get('eppdp_no_save', 0)
        : false;
}

/** ---- Hash helpers (idempotence) ---- */
function eppdp_calc_items_hash(array $items): string
{
    $norm = [];
    foreach ($items as $r) {
        $va = is_array($r['variation'] ?? null) ? $r['variation'] : [];
        ksort($va);
        $norm[] = [
            'p' => (int) ($r['product_id'] ?? 0),
            'v' => (int) ($r['variation_id'] ?? 0),
            'q' => (float) ($r['qty'] ?? 1),
            'a' => $va,
        ];
    }
    usort($norm, function ($a, $b) {
        if ($a['p'] !== $b['p']) return $a['p'] <=> $b['p'];
        if ($a['v'] !== $b['v']) return $a['v'] <=> $b['v'];
        if ($a['q'] !== $b['q']) return $a['q'] <=> $b['q'];
        return strcmp(wp_json_encode($a['a']), wp_json_encode($b['a']));
    });
    return md5(wp_json_encode($norm));
}

/** Current cart hash for THE SELECTED EMPLOYEE items only */
function eppdp_current_cart_hash_for_employee(string $emp_id): string
{
    return eppdp_calc_items_hash(eppdp_export_wc_cart_snapshot_for_employee($emp_id));
}

/** ---- Usermeta payload helpers ---- */
function eppdp_get_all_saved_carts(int $user_id): array
{
    $data = get_user_meta($user_id, EPPDP_USER_CARTS_META, true);
    return is_array($data) ? $data : ['carts' => [], 'version' => 1];
}
function eppdp_put_all_saved_carts(int $user_id, array $data): void
{
    if (!isset($data['carts']) || !is_array($data['carts'])) {
        $data['carts'] = [];
    }
    $data['version'] = 1;
    update_user_meta($user_id, EPPDP_USER_CARTS_META, $data);
}
function eppdp_prune_expired_carts(array $data): array
{
    $now = time();
    if (!isset($data['carts']) || !is_array($data['carts'])) {
        $data['carts'] = [];
        return $data;
    }
    foreach ($data['carts'] as $emp => $entry) {
        $modified = (int) ($entry['modified'] ?? 0);
        if (!$modified || ($now - $modified) > EPPDP_TTL_SECONDS) {
            unset($data['carts'][$emp]);
        }
    }
    return $data;
}

/** ---- Export ONLY items stamped for a given employee ---- */
function eppdp_export_wc_cart_snapshot_for_employee(string $emp_id): array
{
    $items = [];
    $cart  = (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart() : [];

    foreach ($cart as $ci) {
        $stamped = (string) ($ci['eppdp_employee_id'] ?? '');
        if ($stamped !== $emp_id) continue; // only items for this employee

        $items[] = [
            'product_id'   => (int) ($ci['product_id'] ?? 0),
            'variation_id' => (int) ($ci['variation_id'] ?? 0),
            'qty'          => (float) ($ci['quantity'] ?? 1),
            'variation'    => is_array($ci['variation'] ?? null) ? $ci['variation'] : [],
        ];
    }
    return $items;
}

/** ---- Gate cart until an employee is selected ---- */
function eppdp_gate_cart_until_employee(): void
{
    if (!function_exists('WC') || !WC()->cart) return;

    $emp = eppdp_get_employee_from_session();
    if ($emp === '') {
        eppdp_suspend_save(true);
        WC()->cart->empty_cart();
        WC()->cart->calculate_totals();
        eppdp_suspend_save(false);
    }
}
add_action('woocommerce_cart_loaded_from_session', 'eppdp_gate_cart_until_employee', 5);

/** ---- Save the live cart snapshot for the current employee ---- */
function eppdp_save_current_employee_cart(): void
{
    if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart) return;
    if (eppdp_is_save_suspended()) return;

    $emp = eppdp_get_employee_from_session();
    if ($emp === '') return;

    $uid   = get_current_user_id();
    $data  = eppdp_prune_expired_carts(eppdp_get_all_saved_carts($uid));
    $items = eppdp_export_wc_cart_snapshot_for_employee($emp);

    $data['carts'][$emp] = [
        'items'    => $items,
        'hash'     => eppdp_calc_items_hash($items),
        'modified' => time(),
        'ttl'      => EPPDP_TTL_SECONDS,
    ];

    eppdp_put_all_saved_carts($uid, $data);
}

/**
 * Restore snapshot STRICTLY for the selected employee:
 * - If no selection: empty cart (forces modal)
 * - If snapshot exists: clear cart, add only snapshot items, stamp meta
 * - Idempotent via hash check
 */
function eppdp_maybe_restore_employee_cart(): void
{
    if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart) return;

    $emp = eppdp_get_employee_from_session();

    // If no selection, keep cart empty
    if ($emp === '') {
        eppdp_suspend_save(true);
        WC()->cart->empty_cart();
        WC()->cart->calculate_totals();
        eppdp_suspend_save(false);
        return;
    }

    $uid  = get_current_user_id();
    $data = eppdp_prune_expired_carts(eppdp_get_all_saved_carts($uid));
    $snap = isset($data['carts'][$emp]) ? (array) $data['carts'][$emp] : [];

    // If no snapshot yet, also keep the cart clean (prevents strays)
    if (empty($snap)) {
        eppdp_suspend_save(true);
        WC()->cart->empty_cart();
        WC()->cart->calculate_totals();
        eppdp_suspend_save(false);
        return;
    }

    $saved_items = isset($snap['items']) && is_array($snap['items']) ? $snap['items'] : [];
    $saved_hash  = (string) ($snap['hash'] ?? eppdp_calc_items_hash($saved_items));
    $curr_hash   = eppdp_current_cart_hash_for_employee($emp);

    // Already in desired state
    if ($curr_hash === $saved_hash) {
        return;
    }

    // Fetch display name for stamping
    $emp_name = '';
    $emps = function_exists('eppdp_get_employees_for_user')
        ? eppdp_get_employees_for_user($uid)
        : apply_filters('eppdp_employees_for_user', [], $uid);
    foreach ((array)$emps as $row) {
        if (($row['id'] ?? '') === $emp) {
            $emp_name = (string) ($row['name'] ?? '');
            break;
        }
    }

    // Keep PO (if you use it)
    $po = '';
    if (function_exists('WC') && WC()->session) {
        $po = (string) WC()->session->get('eppdp_po', '');
    }

    // STRICT restore: replace live cart
    eppdp_suspend_save(true);

    WC()->cart->empty_cart();

    foreach ($saved_items as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid <= 0) continue;

        $vid = (int) ($row['variation_id'] ?? 0);
        $qty = max(1, (int) ($row['qty'] ?? $row['quantity'] ?? 1));
        $var = is_array($row['variation'] ?? null) ? $row['variation'] : [];

        // Stamp meta consistently
        $meta = array_filter([
            'eppdp_employee_id'   => $emp,
            'eppdp_employee_name' => $emp_name,
            'eppdp_po'            => $po ?: null,
        ]);

        WC()->cart->add_to_cart($pid, $qty, $vid, $var, $meta);
    }

    WC()->cart->calculate_totals();
    eppdp_suspend_save(false);

    // Save back the exact state we just restored (stabilizes future loads)
    eppdp_save_current_employee_cart();
}

/** ---- Clear saved snapshot for current employee after checkout ---- */
function eppdp_clear_employee_after_checkout($order_id): void
{
    if (!is_user_logged_in()) return;

    $emp = eppdp_get_selected_employee_id();
    if ($emp === '') return;

    $uid  = get_current_user_id();
    $data = eppdp_get_all_saved_carts($uid);

    if (isset($data['carts'][$emp])) {
        unset($data['carts'][$emp]);
        eppdp_put_all_saved_carts($uid, $data);
    }
}

/**
 * Ensure the live cart only contains items stamped for the currently-selected employee.
 * Anything without a stamp or stamped for another employee is removed.
 * This keeps the mini-cart (side cart) accurate after any switch/restore.
 */
function eppdp_filter_cart_to_selected_employee(): void
{
    if (!WC()->cart) {
        return;
    }

    $emp = eppdp_get_employee_from_session();
    if ($emp === '') {
        return; // no employee chosen yet; gating happens elsewhere
    }

    // Prevent this mutation from overwriting the saved snapshots during cleanup
    eppdp_suspend_save(true);

    $changed = false;
    foreach (WC()->cart->get_cart() as $cart_key => $item) {
        $item_emp = isset($item['eppdp_employee_id']) ? (string) $item['eppdp_employee_id'] : '';

        // remove items that are not for the current employee
        if ($item_emp === '' || $item_emp !== $emp) {
            WC()->cart->remove_cart_item($cart_key);
            $changed = true;
        }
    }

    if ($changed) {
        WC()->cart->calculate_totals();
    }

    eppdp_suspend_save(false);
}

/** ---- Hooks ---- */
/**
 * Hook the filter so it runs when the cart is hydrated and before totals.
 * - After session load (covers hard refresh and normal navigation)
 * - Before totals (covers AJAX fragment refreshes some side-cart plugins use)
 */
add_action('woocommerce_cart_loaded_from_session', 'eppdp_filter_cart_to_selected_employee', 60);
add_action('woocommerce_before_calculate_totals', 'eppdp_filter_cart_to_selected_employee', 5);
// Save snapshot when common cart actions happen
add_action('woocommerce_add_to_cart',                         'eppdp_save_current_employee_cart', 20);
add_action('woocommerce_cart_item_removed',                   'eppdp_save_current_employee_cart', 20);
add_action('woocommerce_after_cart_item_quantity_update',     'eppdp_save_current_employee_cart', 20, 2);
add_action('woocommerce_applied_coupon',                      'eppdp_save_current_employee_cart', 20);
add_action('woocommerce_removed_coupon',                      'eppdp_save_current_employee_cart', 20);

// Gate first, then (if a selection exists) restore strictly from snapshot
add_action('woocommerce_cart_loaded_from_session',            'eppdp_gate_cart_until_employee',   5);
add_action('woocommerce_cart_loaded_from_session',            'eppdp_maybe_restore_employee_cart', 1000);

// After a successful order, clear that employee’s snapshot
add_action('woocommerce_thankyou',                            'eppdp_clear_employee_after_checkout', 10);
