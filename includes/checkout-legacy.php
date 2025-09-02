<?php
defined('ABSPATH') || exit;

/** 1) Hide shipping fields by default */
add_action('wp_head', function () {
    if (function_exists('is_checkout') && is_checkout()) {
        echo '<style>
          .woocommerce-shipping-fields { display: none; }
          .woocommerce-shipping-fields.visible { display: block; }
          .woocommerce-shipping-fields.visible #ship-to-different-address { display: none; }
        </style>';
    }
});

/** 2) Toggle shipping visibility + method when dropdown changes */
add_action('wp_footer', function () {
    if (function_exists('is_checkout') && is_checkout()): ?>
        <script>
            jQuery(function ($) {
                function toggleShip() {
                    var isDelivery = $('#delivery_collection_option').val() === 'delivery';
                    $('#ship-to-different-address-checkbox').prop('checked', isDelivery).trigger('change');
                    if (isDelivery) {
                        $('.woocommerce-shipping-fields').addClass('visible');
                        var $first = $('input[name^="shipping_method"]').first();
                        if ($first.length && !$first.is(':checked')) {
                            $first.prop('checked', true).trigger('change');
                        }
                    } else {
                        $('.woocommerce-shipping-fields').removeClass('visible');
                        $('input[name^="shipping_method"]').prop('checked', false).trigger('change');
                    }
                }
                toggleShip();
                $('#delivery_collection_option').on('change', toggleShip);
            });
        </script>
        <?php
    endif;
});

/** 3) No payment required UI */
add_filter('woocommerce_cart_needs_payment', '__return_false');
add_filter('woocommerce_checkout_payment', function () {
    echo '<p class="woocommerce-info">' .
        esc_html__('No payment required; your order will be processed automatically.', 'eppdp') .
        '</p>';
}, 10);

/** 4) Auto-advance pending order to processing for this flow */
add_action('woocommerce_thankyou', function ($id) {
    if (!$id)
        return;
    $o = wc_get_order($id);
    if ($o && $o->has_status('pending')) {
        $o->update_status('processing');
        $o->payment_complete();
    }
});

/**
 * 5) Trim checkout fields but keep billing_employee (runs very late)
 *    NOTE: You already use class methods to add/lock the employee field;
 *    this preserves it while removing other billing fields.
 */
add_filter('woocommerce_checkout_fields', function ($fields) {

    if (isset($fields['billing'])) {
        foreach (array_keys($fields['billing']) as $key) {
            if ('billing_employee' !== $key) {
                unset($fields['billing'][$key]);
            }
        }
        if (isset($fields['billing']['billing_employee'])) {
            $fields['billing']['billing_employee']['priority'] = 1;
        }
    }

    if (isset($fields['account'])) {
        unset($fields['account']);
    }

    return $fields;
}, 999);

/** 6) Remove order notes (keeps your separate PO field) */
add_filter('woocommerce_checkout_fields', function ($fields) {
    if (isset($fields['order']['order_comments'])) {
        unset($fields['order']['order_comments']);
    }
    return $fields;
}, 20);
