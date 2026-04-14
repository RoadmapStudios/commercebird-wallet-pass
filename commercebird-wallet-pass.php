<?php
/*
 * Plugin Name: CommerceBird - Wallet Pass for Tickera
 * Plugin URI: https://tickera.com/
 * Description: Adds Apple & Android Wallet Pass for Tickera Event Plugin for WordPress / WooCommerce.
 * Author: CommerceBird
 * Author URI:  https://commercebird.com
 * Requires PHP: 8.2
 * Requires Plugins: commercebird, woocommerce, tickera
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

if ( ! function_exists( 'appleWalletPass' ) ) {
	function appleWalletPass( $event_title, $location, $datetime, $ticket_title, $ticket_id, $ticket_code, $first_name, $last_name ) {
		if ( ! class_exists( Api::class ) ) {
			return null;
		}

		return Api::appleWalletPass(
			(string) $event_title,
			(string) $location,
			(string) $datetime,
			(string) $ticket_title,
			(int) $ticket_id,
			(string) $ticket_code,
			(string) $first_name,
			(string) $last_name
		);
	}
}

if ( ! function_exists( 'tc_get_wallet_pass_for_ticket' ) ) {
	function tc_get_wallet_pass_for_ticket( $field_name, $post_field_type, $tickets_id ) {
		if ( ! class_exists( Api::class ) ) {
			echo esc_html__( 'Wallet pass unavailable.', 'tcawp' );
			return;
		}

		Api::renderWalletPassForTicket( $field_name, $post_field_type, $tickets_id );
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
