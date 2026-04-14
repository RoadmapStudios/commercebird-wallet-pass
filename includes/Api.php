<?php

declare(strict_types=1);

namespace Tickera\WalletPass;

final class Api {

	private const CONNECTOR_ENDPOINT = 'customs/wallet/pass';

	public static function register(): void {
		add_filter( 'tc_owner_info_orders_table_fields_front', array( self::class, 'addWalletColumn' ) );
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

	public static function renderWalletPassForTicket( $fieldName, $postFieldType, $ticketsId ): void {
		// Serve from cache if available — avoids hitting the Node API on every page load.
		$cached = get_post_meta( (int) $ticketsId, self::PASS_URL_META_KEY, true );
		if ( ! empty( $cached ) && is_string( $cached ) ) {
			self::renderWalletButton( $cached );
			return;
		}

		$events = get_post_meta( $ticketsId, '', false );

		$eventId    = $events['event_id'][0] ?? null;
		$ticketCode = $events['ticket_code'][0] ?? '';
		$firstName  = $events['first_name'][0] ?? '';
		$lastName   = $events['last_name'][0] ?? '';

		if ( empty( $eventId ) || empty( $ticketCode ) || ! class_exists( 'TC_Event' ) || ! class_exists( 'TC_Ticket' ) ) {
			echo esc_html__( 'Wallet pass unavailable.', 'tcawp' );
			return;
		}

		$eventObj    = new \TC_Event( $eventId );
		$locationObj = get_post_meta( (int) $eventId, '', false );
		$ticket      = new \TC_Ticket( $ticketsId );

		$passUrl = self::appleWalletPass(
			(string) ( $eventObj->details->post_title ?? '' ),
			(string) ( $locationObj['event_location'][0] ?? '' ),
			(string) ( $locationObj['event_date_time'][0] ?? '' ),
			(string) ( $ticket->details->post_title ?? '' ),
			(int) $ticketsId,
			(string) $ticketCode,
			(string) $firstName,
			(string) $lastName
		);

		if ( ! empty( $passUrl ) ) {
			update_post_meta( (int) $ticketsId, self::PASS_URL_META_KEY, $passUrl );
		}

		self::renderWalletButton( $passUrl );
	}

	/**
	 * Deletes the cached pass URL for a single ticket so that it is
	 * regenerated the next time the customer views their orders page.
	 */
	public static function invalidatePassCache( int $ticketId ): void {
		delete_post_meta( $ticketId, self::PASS_URL_META_KEY );
	}

	public static function appleWalletPass(
		string $eventTitle,
		string $location,
		string $datetime,
		string $ticketTitle,
		int $ticketId,
		string $ticketCode,
		string $firstName,
		string $lastName
	): ?string {
		$settings = Admin::getSettings();
		$endpoint = self::CONNECTOR_ENDPOINT;

		$payload = array(
			'event_title'       => $eventTitle,
			'location'          => $location,
			'datetime'          => $datetime,
			'ticket_title'      => $ticketTitle,
			'ticket_id'         => $ticketId,
			'ticket_code'       => $ticketCode,
			'first_name'        => $firstName,
			'last_name'         => $lastName,
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

		$passUrl = $decoded['pass_url'] ?? $decoded['url'] ?? null;

		if ( ! is_string( $passUrl ) || $passUrl === '' ) {
			return null;
		}

		return esc_url_raw( $passUrl );
	}

	private static function renderWalletButton( ?string $passUrl ): void {
		if ( empty( $passUrl ) ) {
			echo esc_html__( 'Wallet pass unavailable.', 'tcawp' );
			return;
		}

		$android = isset( $_SERVER['HTTP_USER_AGENT'] ) ? stripos( (string) $_SERVER['HTTP_USER_AGENT'], 'Android' ) : false;

		if ( $android !== false ) {
			echo '<a href="https://www.walletpasses.io?u=' . rawurlencode( $passUrl ) . '" target="_system" rel="noopener noreferrer"><img src="https://www.walletpasses.io/badges/badge_web_generic_en@2x.png" alt="Wallet Pass" /></a>';
			return;
		}

		$appleBadge = plugins_url( 'includes/add-to-apple-wallet.jpg', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
		echo '<a href="' . esc_url( $passUrl ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $appleBadge ) . '" width="100" alt="Add to Apple Wallet" /></a>';
	}
}
