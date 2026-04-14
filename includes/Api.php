<?php

declare(strict_types=1);

namespace Tickera\WalletPass;

final class Api {

	private const CONNECTOR_ENDPOINT = 'customs/wallet/pass';

	public static function register(): void {
		add_filter( 'tc_owner_info_orders_table_fields_front', array( self::class, 'addWalletColumn' ) );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'addEmailWalletPass' ), 10, 4 );
	}

	public static function addWalletColumn( array $fields ): array {
		$fields[] = array(
			'id'                => 'ticket_apple_wallet_pass',
			'field_name'        => 'ticket_apple_wallet_pass_column',
			'field_title'       => __( 'Wallet Pass', 'tc' ),
			'field_type'        => 'function',
			'function'          => 'tc_get_wallet_pass_for_ticket',
			'field_description' => '',
			'post_field_type'   => 'post_meta',
		);

		return $fields;
	}

	public const PASS_URL_META_KEY = '_tc_wallet_pass_url';

	public static function renderWalletPassForTicket( $field_name, $post_field_type, $tickets_id ): void {
		self::renderWalletButton( self::getOrGeneratePassUrl( (int) $tickets_id ) );
	}

	private static function getOrGeneratePassUrl( int $ticket_id ): ?string {
		// Serve from cache if available — avoids hitting the Node API on every page load.
		$cached = get_post_meta( $ticket_id, self::PASS_URL_META_KEY, true );
		if ( ! empty( $cached ) && is_string( $cached ) ) {
			return $cached;
		}

		$events = get_post_meta( $ticket_id, '', false );

		$event_id    = $events['event_id'][0] ?? null;
		$ticket_code = $events['ticket_code'][0] ?? '';
		$first_name  = $events['first_name'][0] ?? '';
		$last_name   = $events['last_name'][0] ?? '';

		if ( empty( $event_id ) || empty( $ticket_code ) || ! class_exists( 'TC_Event' ) || ! class_exists( 'TC_Ticket' ) ) {
			return null;
		}

		$event_obj    = new \TC_Event( $event_id );
		$location_obj = get_post_meta( (int) $event_id, '', false );
		$ticket       = new \TC_Ticket( $ticket_id );

		$pass_url = self::appleWalletPass(
			(string) ( $event_obj->details->post_title ?? '' ),
			(string) ( $location_obj['event_location'][0] ?? '' ),
			(string) ( $location_obj['event_date_time'][0] ?? '' ),
			(string) ( $ticket->details->post_title ?? '' ),
			$ticket_id,
			(string) $ticket_code,
			(string) $first_name,
			(string) $last_name
		);

		if ( ! empty( $pass_url ) ) {
			update_post_meta( $ticket_id, self::PASS_URL_META_KEY, $pass_url );
		}

		return $pass_url;
	}

	public static function addEmailWalletPass( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin ) {
			return;
		}

		if ( ! in_array( $email->id, array( 'customer_processing_order', 'customer_completed_order' ), true ) ) {
			return;
		}

		$ticket_ids = get_posts(
			array(
				'post_type'      => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'order_id',
						'value' => $order->get_id(),
					),
				),
			)
		);

		if ( empty( $ticket_ids ) ) {
			return;
		}

		$passes = array();
		foreach ( $ticket_ids as $ticket_id ) {
			$pass_url = self::getOrGeneratePassUrl( (int) $ticket_id );
			if ( ! empty( $pass_url ) ) {
				$passes[] = array(
					'title' => get_the_title( $ticket_id ),
					'url'   => $pass_url,
				);
			}
		}

		if ( empty( $passes ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Wallet Passes', 'tcawp' ) . "\n";
			foreach ( $passes as $pass ) {
				echo esc_html( $pass['title'] ) . ': ' . $pass['url'] . "\n";
			}
			return;
		}

		$apple_badge = plugins_url( 'includes/add-to-apple-wallet.jpg', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
		echo '<h2 style="color:#333;font-family:inherit;">' . esc_html__( 'Your Wallet Passes', 'tcawp' ) . '</h2>';
		foreach ( $passes as $pass ) {
			echo '<p>';
			echo '<strong>' . esc_html( $pass['title'] ) . '</strong><br>';
			echo '<a href="' . esc_url( $pass['url'] ) . '"><img src="' . esc_url( $apple_badge ) . '" width="100" alt="' . esc_attr__( 'Add to Apple Wallet', 'tcawp' ) . '" style="display:block;margin-top:8px;" /></a>';
			echo '</p>';
		}
	}

	/**
	 * Deletes the cached pass URL for a single ticket so that it is
	 * regenerated the next time the customer views their orders page.
	 */
	public static function invalidatePassCache( int $ticket_id ): void {
		delete_post_meta( $ticket_id, self::PASS_URL_META_KEY );
	}

	public static function appleWalletPass(
		string $event_title,
		string $location,
		string $datetime,
		string $ticket_title,
		int $ticket_id,
		string $ticket_code,
		string $first_name,
		string $last_name
	): ?string {
		$settings = Admin::getSettings();
		$endpoint = self::CONNECTOR_ENDPOINT;

		$payload = array(
			'event_title'       => $event_title,
			'location'          => $location,
			'datetime'          => $datetime,
			'ticket_title'      => $ticket_title,
			'ticket_id'         => $ticket_id,
			'ticket_code'       => $ticket_code,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'icon_url'          => (string) ( $settings['icon_file'] ?? '' ),
			'qr_code_type'      => (string) ( $settings['qr_code_type'] ?? '' ),
			'logo_text'         => (string) ( $settings['logo_text'] ?? '' ),
			'background_color'  => (string) ( $settings['background_color'] ?? '' ),
			'organisation_name' => (string) ( $settings['organisation_name'] ?? '' ),
		);

		if ( ! class_exists( 'CommerceBird\\Admin\\Connectors\\Connector' ) ) {
			return null;
		}

		$connector = new \CommerceBird\Admin\Connectors\Connector();
		$response  = $connector->request( $endpoint, 'POST', $payload );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( ! is_array( $response ) ) {
			return null;
		}

		$decoded = $response['data'] ?? $response;

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$pass_url = $decoded['pass_url'] ?? $decoded['url'] ?? null;

		if ( ! is_string( $pass_url ) || $pass_url === '' ) {
			return null;
		}

		return esc_url_raw( $pass_url );
	}

	private static function renderWalletButton( ?string $pass_url ): void {
		if ( empty( $pass_url ) ) {
			echo esc_html__( 'Wallet pass unavailable.', 'tcawp' );
			return;
		}

		$android = isset( $_SERVER['HTTP_USER_AGENT'] ) ? stripos( (string) $_SERVER['HTTP_USER_AGENT'], 'Android' ) : false;

		if ( $android !== false ) {
			echo '<a href="https://www.walletpasses.io?u=' . rawurlencode( $pass_url ) . '" target="_system" rel="noopener noreferrer"><img src="https://www.walletpasses.io/badges/badge_web_generic_en@2x.png" alt="Wallet Pass" /></a>';
			return;
		}

		$apple_badge = plugins_url( 'includes/add-to-apple-wallet.jpg', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
		echo '<a href="' . esc_url( $pass_url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $apple_badge ) . '" width="100" alt="Add to Apple Wallet" /></a>';
	}
}
