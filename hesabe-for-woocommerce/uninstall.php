<?php
/**
 * Uninstall — remove plugin options.
 *
 * @package Hesabe_WC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_hesabe_settings' );
delete_option( 'woocommerce_hesabe_knet_settings' );
delete_option( 'woocommerce_hesabe_visa_settings' );
delete_option( 'woocommerce_hesabe_apple_settings' );
