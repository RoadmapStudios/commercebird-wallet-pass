<?php
/*
 * Plugin Name: CommerceBird - Wallet Pass for Tickera
 * Plugin URI: https://commercebird.com
 * Description: Adds Apple & Android Wallet Pass for Tickera Event Tickets for WooCommerce WordPress.
 * Author: CommerceBird
 * Requires PHP: 8.2
 * Requires Plugins: commercebird, tickera-event-ticketing-system
 * Requires at least: 7.0
 * Version: 1.0.6
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/vendor/autoload.php';

use CommerceBird\WalletPass\Plugin;

if ( class_exists( Plugin::class ) ) {
	Plugin::bootstrap();
}

if ( ! function_exists( 'cmbird_wallet_pass_set_apple_mime_type' ) ) {
	function cmbird_wallet_pass_set_apple_mime_type() {
		if ( class_exists( Plugin::class ) ) {
			Plugin::cmbird_set_apple_mime_type();
		}
	}
}

register_activation_hook( __FILE__, 'cmbird_wallet_pass_set_apple_mime_type' );

if ( class_exists( Plugin::class ) && method_exists( Plugin::class, 'scheduleCleanup' ) ) {
	register_activation_hook( __FILE__, array( Plugin::class, 'scheduleCleanup' ) );
}

if ( class_exists( Plugin::class ) && method_exists( Plugin::class, 'clearCleanupSchedule' ) ) {
	register_deactivation_hook( __FILE__, array( Plugin::class, 'clearCleanupSchedule' ) );
}

/**
 * Set pkpass mime type using WP Filter.
 *
 * @since 1.0.0
 */
add_filter( 'mime_types', 'cmbird_wallet_pass_add_pkpass_mime_type' );
function cmbird_wallet_pass_add_pkpass_mime_type( $wp_get_mime_types ) {
	$wp_get_mime_types['pkpass'] = 'application/vnd.apple.pkpass';
	return $wp_get_mime_types;
}
