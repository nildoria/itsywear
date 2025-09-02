<?php
defined('ABSPATH') || exit;

/**
 * Normalized employees for a user (public helper).
 * Keeps BC with any external code that calls this.
 */
if (!function_exists('eppdp_get_employees_for_user')) {
    function eppdp_get_employees_for_user($user_id): array
    {
        $list = apply_filters('eppdp_employees_for_user', [], $user_id);
        if (empty($list)) {
            $list = get_user_meta($user_id, 'employees', true) ?: [];
        }
        $out = [];
        foreach ((array) $list as $row) {
            if (is_array($row)) {
                $id = $row['id'] ?? $row['ID'] ?? '';
                $name = $row['name'] ?? $row['display_name'] ?? '';
            } else {
                $id = (string) $row;
                $name = (string) $row;
            }
            if ($id) {
                $out[] = ['id' => (string) $id, 'name' => $name ?: (string) $id];
            }
        }
        return $out;
    }
}

/**
 * Default data source: pull from user meta and normalize
 * to [ ['id' => 'EMP...', 'name' => 'Alice'], ... ].
 */
add_filter('eppdp_employees_for_user', function ($_, $user_id) {
    $employees = get_user_meta($user_id, 'employees', true);
    $employees = is_array($employees) ? $employees : [];

    $out = [];
    foreach ($employees as $idx => $emp) {
        if (is_array($emp)) {
            // Prefer existing IDs, else make a deterministic one
            $id = $emp['id'] ?? $emp['ID'] ?? substr(md5(
                trim(($emp['name'] ?? '')) . '|' .
                trim(($emp['department'] ?? '')) . '|' .
                trim(($emp['branch'] ?? ''))
            ), 0, 12);
            $name = $emp['name'] ?? $emp['display_name'] ?? $id;
        } else {
            $name = (string) $emp;
            $id = sanitize_title($name) ?: ('emp_' . ($idx + 1));
        }

        if ($id) {
            $out[] = ['id' => (string) $id, 'name' => (string) $name];
        }
    }

    return $out;
}, 10, 2);
