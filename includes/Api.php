<?php

declare(strict_types=1);

namespace Tickera\WalletPass;

final class Api {

	private const CONNECTOR_ENDPOINT = 'customs/wallet/pass';
	private const PROXY_ACTION       = 'tcawp_wallet_pass';

	public static function register(): void {
		add_filter( 'tc_owner_info_orders_table_fields_front', array( self::class, 'addWalletColumn' ) );
		add_action( 'woocommerce_thankyou', array( self::class, 'tc_get_wallet_pass_for_ticket' ) );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'addEmailWalletPass' ), 10, 4 );
		add_action( 'admin_post_' . self::PROXY_ACTION, array( self::class, 'serveWalletPassProxy' ) );
		add_action( 'admin_post_nopriv_' . self::PROXY_ACTION, array( self::class, 'serveWalletPassProxy' ) );
	}

	public static function addWalletColumn( array $fields ): array {
		$fields[] = array(
			'id'                => 'ticket_apple_wallet_pass',
			'field_name'        => 'ticket_apple_wallet_pass_column',
			'field_title'       => __( 'Wallet Pass', 'tc' ),
			'field_type'        => 'function',
			'function'          => array( self::class, 'renderWalletButton' ),
			'field_description' => '',
			'post_field_type'   => 'post_meta',
		);

		return $fields;
	}

	public const PASS_URL_META_KEY = '_tc_wallet_pass_url';

	public static function tc_get_wallet_pass_for_ticket( int $order_id ): void {
		if ( ! class_exists( '\Tickera\TC_Orders' ) ) {
			return;
		}
		$order_attendees = \Tickera\TC_Orders::get_tickets_ids( (int) $order_id );
		foreach ( (array) $order_attendees as $order_attendee_id ) {
			self::getOrGeneratePassUrl( (int) $order_attendee_id );
		}
	}

	private static function getOrGeneratePassUrl( int $order_attendee_id ): ?string {
		// Serve from cache if available — avoids hitting the Node API on every page load.
		$cached = get_post_meta( $order_attendee_id, self::PASS_URL_META_KEY, true );
		if ( ! empty( $cached ) && is_string( $cached ) ) {
			return $cached;
		}

		$ticket_meta = get_post_meta( $order_attendee_id, '', false );

		$event_id    = $ticket_meta['event_id'][0] ?? null;
		$ticket_code = $ticket_meta['ticket_code'][0] ?? '';
		$first_name  = isset( $ticket_meta['first_name'] ) ? reset( $ticket_meta['first_name'] ) : '';
		$last_name   = isset( $ticket_meta['last_name'] ) ? reset( $ticket_meta['last_name'] ) : '';

		if ( empty( $event_id ) || empty( $ticket_code ) || ! class_exists( 'Tickera\TC_Event' ) || ! class_exists( 'Tickera\TC_Ticket' ) ) {
			return null;
		}

		$event_obj    = new \Tickera\TC_Event( (int) $event_id );
		$location_obj = get_post_meta( (int) $event_id, '', false );
		$ticket       = new \Tickera\TC_Ticket( $order_attendee_id );

		$pass_url = self::appleWalletPass(
			(string) ( $event_obj->details->post_title ?? '' ),
			(string) ( $location_obj['event_location'][0] ?? '' ),
			(string) ( $location_obj['event_date_time'][0] ?? '' ),
			(string) ( $ticket->details->post_title ?? '' ),
			$order_attendee_id,
			(string) $ticket_code,
			(string) $first_name,
			(string) $last_name
		);

		if ( ! empty( $pass_url ) ) {
			update_post_meta( $order_attendee_id, self::PASS_URL_META_KEY, $pass_url );
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

		$order_attendees = \Tickera\TC_Orders::get_tickets_ids( $order->get_id() );

		if ( empty( $order_attendees ) ) {
			return;
		}

		$passes = array();
		foreach ( $order_attendees as $order_attendee_id ) {
			$ticket_meta = get_post_meta( $order_attendee_id );
			$ticket_code = isset( $ticket_meta['ticket_code'] ) ? reset( $ticket_meta['ticket_code'] ) : '';
			if ( '' === $ticket_code ) {
				continue;
			}
			$pass_url         = self::getOrGeneratePassUrl( (int) $order_attendee_id );
			$wallet_url       = self::buildAppleWalletUrl( $pass_url );
			$ticket_type_id   = isset( $ticket_meta['ticket_type_id'] ) ? reset( $ticket_meta['ticket_type_id'] ) : '';
			$ticket_type_name = get_the_title( $ticket_type_id );
			if ( ! empty( $wallet_url ) ) {
				$passes[] = array(
					'title' => $ticket_type_name,
					'url'   => $wallet_url,
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
			// log the error for debugging purposes.
			if ( class_exists( 'WC_Logger' ) ) {
				$logger = wc_get_logger();
				$logger->error( 'Connector class not found . Ensure the CommerceBird plugin is active . ', array( 'source' => 'tickera - wallet - pass' ) );
			}
			return null;
		}

		$connector = new \CommerceBird\Admin\Connectors\Connector();
		$response  = $connector->request( $endpoint, 'POST', $payload );
		// Log the response for debugging purposes.
		if ( class_exists( 'WC_Logger' ) ) {
			$logger = wc_get_logger();
			$logger->info( 'Wallet Pass API response: ' . print_r( $response, true ), array( 'source' => 'tickera - wallet - pass' ) );
		}

		if ( is_wp_error( $response ) ) {
			// Save the error via WC Logger for debugging purposes.
			if ( class_exists( 'WC_Logger' ) ) {
				$logger = wc_get_logger();
				$logger->error( 'Wallet Pass API request failed: ' . $response->get_error_message(), array( 'source' => 'tickera - wallet - pass' ) );
			}
			return null;
		}

		if ( ! is_array( $response ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				$logger = wc_get_logger();
				$logger->error( 'Wallet Pass API response is not an array . ', array( 'source' => 'tickera - wallet - pass' ) );
			}
			return null;
		}

		if ( ( $response['code'] ?? null ) !== 200 ) {
			if ( class_exists( 'WC_Logger' ) ) {
				$logger = wc_get_logger();
				$logger->error( 'Wallet Pass API returned non - 200: ' . ( $response['message'] ?? 'unknown error' ), array( 'source' => 'tickera - wallet - pass' ) );
			}
			return null;
		}

		$data     = $response['data'] ?? array();
		$pass_url = $data['pass_url'] ?? $data['url'] ?? null;

		if ( ! is_string( $pass_url ) || '' === $pass_url ) {
			return null;
		}

		return esc_url_raw( $pass_url );
	}

	private static function buildAppleWalletUrl( ?string $pass_url ): ?string {
		if ( empty( $pass_url ) || ! is_string( $pass_url ) ) {
			return null;
		}

		$signature = hash_hmac( 'sha256', $pass_url, wp_salt( 'auth' ) );

		return add_query_arg(
			array(
				'action' => self::PROXY_ACTION,
				'pass'   => rawurlencode( $pass_url ),
				'sig'    => $signature,
			),
			admin_url( 'admin - post . php' )
		);
	}

	public static function serveWalletPassProxy(): void {
		$encoded_pass = isset( $_GET['pass'] ) ? (string) wp_unslash( $_GET['pass'] ) : '';
		$signature    = isset( $_GET['sig'] ) ? (string) wp_unslash( $_GET['sig'] ) : '';

		$pass_url = rawurldecode( $encoded_pass );
		$pass_url = esc_url_raw( $pass_url );

		$expected_signature = hash_hmac( 'sha256', $pass_url, wp_salt( 'auth' ) );

		if ( '' === $pass_url || '' === $signature || ! hash_equals( $expected_signature, $signature ) ) {
			status_header( 403 );
			exit;
		}

		$response = wp_remote_get(
			$pass_url,
			array(
				'timeout'            => 20,
				'redirection'        => 5,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			status_header( 502 );
			exit;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			status_header( 502 );
			exit;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			status_header( 502 );
			exit;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/vnd.apple.pkpass' );
		header( 'Content-Disposition: inline; filename="ticket.pkpass"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Content-Length: ' . strlen( $body ) );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function renderWalletButton( ?string $pass_url ): void {
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
		$wallet_url  = self::buildAppleWalletUrl( $pass_url );

		if ( empty( $wallet_url ) ) {
			echo esc_html__( 'Wallet pass unavailable.', 'tcawp' );
			return;
		}

		echo '<a href="' . esc_url( $wallet_url ) . '" rel="noopener noreferrer"><img src="' . esc_url( $apple_badge ) . '" width="100" alt="Add to Apple Wallet" /></a>';
	}
}
