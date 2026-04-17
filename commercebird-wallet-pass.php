<?php
/*
 * Plugin Name: CommerceBird - Wallet Pass for Tickera
 * Plugin URI: https://commercebird.com
 * Description: Adds Apple & Android Wallet Pass for Tickera Event Tickets for WooCommerce WordPress.
 * Author: CommerceBird
 * Author URI:  https://commercebird.com
 * Requires PHP: 8.2
 * Requires Plugins: commercebird, woocommerce
 * Requires at least: 6.5
 * Version: 1.0.0
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

if ( ! function_exists( 'commercebird_wallet_pass_set_apple_mime_type' ) ) {
	function commercebird_wallet_pass_set_apple_mime_type() {
		if ( class_exists( Plugin::class ) ) {
			Plugin::wpass_set_apple_mime_type();
		}
	}
}

register_activation_hook( __FILE__, 'commercebird_wallet_pass_set_apple_mime_type' );

if ( class_exists( Plugin::class ) && method_exists( Plugin::class, 'scheduleCleanup' ) ) {
	register_activation_hook( __FILE__, array( Plugin::class, 'scheduleCleanup' ) );
}

if ( class_exists( Plugin::class ) && method_exists( Plugin::class, 'clearCleanupSchedule' ) ) {
	register_deactivation_hook( __FILE__, array( Plugin::class, 'clearCleanupSchedule' ) );
}
