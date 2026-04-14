<?php
/*
 * Plugin Name: CommerceBird - Wallet Pass for Tickera
 * Plugin URI: https://tickera.com/
 * Description: Adds Apple & Android Wallet Pass for Tickera Event Plugin for WordPress / WooCommerce.
 * Author: CommerceBird
 * Author URI:  https://commercebird.com
 * Requires PHP: 8.2
 * Requires Plugins: commercebird, woocommerce, tickera
 * Requires at least: 6.5
 * Version: 1.0.0
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

require __DIR__ . '/vendor/autoload.php';

use Tickera\WalletPass\Api;
use Tickera\WalletPass\Plugin;

if ( class_exists( Plugin::class ) ) {
	Plugin::bootstrap();
}

if ( ! function_exists( 'tc_get_wallet_pass_for_ticket' ) ) {
	function tc_get_wallet_pass_for_ticket( $order_id ) {
		if ( ! class_exists( Api::class ) || ! class_exists( '\Tickera\TC_Orders' ) ) {
			echo esc_html__( 'Wallet pass unavailable.', 'tcawp' );
			return;
		}

		$order_attendees = \Tickera\TC_Orders::get_tickets_ids( (int) $order_id );
		foreach ( (array) $order_attendees as $order_attendee_id ) {
			Api::renderWalletPassForTicket( (int) $order_attendee_id );
		}
	}
}

if ( ! function_exists( 'setAppleMimeType' ) ) {
	function setAppleMimeType() {
		if ( class_exists( Plugin::class ) ) {
			Plugin::setAppleMimeType();
		}
	}
}

register_activation_hook( __FILE__, 'setAppleMimeType' );

if ( class_exists( Plugin::class ) && method_exists( Plugin::class, 'scheduleCleanup' ) ) {
	register_activation_hook( __FILE__, array( Plugin::class, 'scheduleCleanup' ) );
}

if ( class_exists( Plugin::class ) && method_exists( Plugin::class, 'clearCleanupSchedule' ) ) {
	register_deactivation_hook( __FILE__, array( Plugin::class, 'clearCleanupSchedule' ) );
}
