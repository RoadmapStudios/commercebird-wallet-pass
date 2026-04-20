<?php

declare(strict_types=1);

namespace CommerceBird\WalletPass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API integration for generating and rendering Apple Wallet passes.
 */
final class Api {

	private const CONNECTOR_ENDPOINT = 'customs/wallet/pass';
	private const PROXY_ACTION       = 'commercebird_wallet_pass';
	public const  PASS_URL_META_KEY  = '_tc_wallet_pass_url';

	/**
	 * Tickera passes the field_name string to callable-array callbacks, not the post-meta value.
	 * URLs are queued here by generatePassesForOrder / preloadPassUrlsForOrder before Tickera
	 * renders the column, then consumed one-by-one by renderWalletButton.
	 *
	 * @var string[]
	 */
	private static array $pass_url_queue = array();

	public static function register(): void {
		add_filter( 'tc_owner_info_orders_table_fields_front', array( self::class, 'addWalletColumn' ) );
		// Priority 1 ensures passes are generated and queued before Tickera renders the column.
		add_action( 'woocommerce_thankyou', array( self::class, 'generatePassesForOrder' ), 1 );
		// Preload cached URLs into the queue when a customer views an order in My Account.
		add_action( 'woocommerce_account_view_order', array( self::class, 'preloadPassUrlsForOrder' ) );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'addEmailWalletPass' ), 10, 4 );
		// Proxy endpoint: fetches the .pkpass and re-serves it with the headers iOS Wallet requires.
		add_action( 'admin_post_' . self::PROXY_ACTION, array( self::class, 'serveWalletPassProxy' ) );
		add_action( 'admin_post_nopriv_' . self::PROXY_ACTION, array( self::class, 'serveWalletPassProxy' ) );
	}

	public static function addWalletColumn( array $fields ): array {
		$fields[] = array(
			'id'                => 'ticket_apple_wallet_pass',
			'field_name'        => self::PASS_URL_META_KEY,
			'field_title'       => __( 'Wallet Pass', 'commercebird-wallet-pass' ),
			'field_type'        => 'function',
			'function'          => array( self::class, 'renderWalletButton' ),
			'field_description' => '',
			'post_field_type'   => 'post_meta',
		);

		return $fields;
	}

	/**
	 * Generates and caches wallet pass URLs for every ticket in the order,
	 * then queues them so renderWalletButton can consume them in order.
	 * Runs at priority 1 on woocommerce_thankyou, before Tickera renders the column.
	 */
	public static function generatePassesForOrder( int $order_id ): void {
		if ( ! class_exists( '\Tickera\TC_Orders' ) ) {
			return;
		}
		foreach ( (array) \Tickera\TC_Orders::get_tickets_ids( $order_id ) as $ticket_id ) {
			$url = self::generateURLforWallet( (int) $ticket_id );
			if ( $url ) {
				self::$pass_url_queue[] = $url;
			}
		}
	}

	/**
	 * Reads already-cached pass URLs from post-meta into the queue.
	 * Hooked to woocommerce_account_view_order so the column works on the
	 * My Account → Order detail page without making a new API call.
	 */
	public static function preloadPassUrlsForOrder( int $order_id ): void {
		if ( ! class_exists( '\Tickera\TC_Orders' ) ) {
			return;
		}
		foreach ( (array) \Tickera\TC_Orders::get_tickets_ids( $order_id ) as $ticket_id ) {
			$url = (string) get_post_meta( (int) $ticket_id, self::PASS_URL_META_KEY, true );
			if ( '' !== $url ) {
				self::$pass_url_queue[] = $url;
			}
		}
	}

	/**
	 * Generates the wallet pass via the CommerceBird API, caches the resulting
	 * URL in post-meta, and returns it. The URL is served with the correct
	 * Content-Type for iOS Wallet via the wp_headers filter below.
	 */
	private static function generateURLforWallet( int $ticket_id ): ?string {
		$cached = get_post_meta( $ticket_id, self::PASS_URL_META_KEY, true );
		if ( ! empty( $cached ) && is_string( $cached ) ) {
			return $cached;
		}

		$ticket_meta = get_post_meta( $ticket_id, '', false );
		$event_id    = $ticket_meta['event_id'][0] ?? null;
		$ticket_code = $ticket_meta['ticket_code'][0] ?? '';
		$first_name  = isset( $ticket_meta['first_name'] ) ? reset( $ticket_meta['first_name'] ) : '';
		$last_name   = isset( $ticket_meta['last_name'] ) ? reset( $ticket_meta['last_name'] ) : '';

		if ( empty( $event_id ) || empty( $ticket_code )
			|| ! class_exists( 'Tickera\TC_Event' )
			|| ! class_exists( 'Tickera\TC_Ticket' ) ) {
			return null;
		}

		$event_obj    = new \Tickera\TC_Event( (int) $event_id );
		$location_obj = get_post_meta( (int) $event_id, '', false );
		$ticket       = new \Tickera\TC_Ticket( $ticket_id );

		$pass_url = self::callWalletPassApi(
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

		return $pass_url ?: null;
	}

	/**
	 * Builds a signed WordPress proxy URL for a raw .pkpass URL.
	 * Routing through WordPress lets us set the full set of headers iOS Wallet requires.
	 */
	private static function buildProxyUrl( string $pass_url ): string {
		$signature = hash_hmac( 'sha256', $pass_url, wp_salt( 'auth' ) );
		return add_query_arg(
			array(
				'action' => self::PROXY_ACTION,
				'pass'   => rawurlencode( $pass_url ),
				'sig'    => $signature,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Fetches the .pkpass from the remote URL and re-serves it with the headers
	 * iOS Wallet requires. Accessible to unauthenticated users via admin_post_nopriv_.
	 */
	public static function serveWalletPassProxy(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Admin page detection.
		$encoded_pass = isset( $_GET['pass'] ) ? (string) wp_unslash( $_GET['pass'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Admin page detection.
		$signature    = isset( $_GET['sig'] ) ? (string) wp_unslash( $_GET['sig'] ) : '';
		$pass_url     = esc_url_raw( rawurldecode( $encoded_pass ) );
		$expected_sig = hash_hmac( 'sha256', $pass_url, wp_salt( 'auth' ) );

		if ( '' === $pass_url || '' === $signature || ! hash_equals( $expected_sig, $signature ) ) {
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

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
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
			$pass_url = self::generateURLforWallet( (int) $order_attendee_id );
			if ( ! $pass_url ) {
				continue;
			}
			$ticket_type_id = isset( $ticket_meta['ticket_type_id'] ) ? reset( $ticket_meta['ticket_type_id'] ) : '';
			$passes[]       = array(
				'title' => get_the_title( $ticket_type_id ),
				'url'   => $pass_url,
			);
		}

		if ( empty( $passes ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Wallet Passes', 'commercebird-wallet-pass' ) . "\n";
			foreach ( $passes as $pass ) {
				echo esc_html( $pass['title'] ) . ': ' . esc_url( $pass['url'] ) . "\n";
			}
			return;
		}

		$apple_badge = plugins_url( 'includes/add-to-apple-wallet.jpg', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
		echo '<h2 style="color:#333;font-family:inherit;">' . esc_html__( 'Your Wallet Passes', 'commercebird-wallet-pass' ) . '</h2>';
		foreach ( $passes as $pass ) {
			echo '<p>';
			echo '<strong>' . esc_html( $pass['title'] ) . '</strong><br>';
			echo '<a href="' . esc_url( self::buildProxyUrl( $pass['url'] ) ) . '"><img src="' . esc_url( $apple_badge ) . '" width="100" alt="' . esc_attr__( 'Add to Apple Wallet', 'commercebird-wallet-pass' ) . '" style="display:block;margin-top:8px;" /></a>';
			echo '</p>';
		}
	}

	private static function iconToBase64( string $abs_path ): string {
		if ( '' === $abs_path || ! file_exists( $abs_path ) ) {
			return '';
		}
		$bytes = file_get_contents( $abs_path );
		return ( false === $bytes ) ? '' : base64_encode( $bytes );
	}

	public static function invalidatePassCache( int $ticket_id ): void {
		delete_post_meta( $ticket_id, self::PASS_URL_META_KEY );
	}

	private static function callWalletPassApi(
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

		$payload = array(
			'event_title'       => $event_title,
			'location'          => $location,
			'datetime'          => $datetime,
			'ticket_title'      => $ticket_title,
			'ticket_id'         => $ticket_id,
			'ticket_code'       => $ticket_code,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'icon_data'         => self::iconToBase64( (string) \get_attached_file( (int) ( $settings['icon_file_id'] ?? 0 ) ) ),
			'logo_text'         => (string) ( $settings['logo_text'] ?? '' ),
			'background_color'  => (string) ( $settings['background_color'] ?? '' ),
			'organisation_name' => (string) ( $settings['organisation_name'] ?? '' ),
		);

		if ( ! class_exists( 'CommerceBird\\Admin\\Connectors\\Connector' ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Connector class not found. Ensure the CommerceBird plugin is active.', array( 'source' => 'tickera-wallet-pass' ) );
			}
			return null;
		}

		$connector = new \CommerceBird\Admin\Connectors\Connector();
		$response  = $connector->request( self::CONNECTOR_ENDPOINT, 'POST', $payload );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Wallet Pass API request failed: ' . $response->get_error_message(), array( 'source' => 'tickera-wallet-pass' ) );
			}
			return null;
		}

		if ( ! is_array( $response ) || ( $response['code'] ?? null ) !== 200 ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Wallet Pass API error: ' . ( $response['message'] ?? 'unexpected response' ), array( 'source' => 'tickera-wallet-pass' ) );
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

	public static function renderWalletButton( string $pass_url = '' ): void {
		// Tickera passes the field_name string, not the meta value, to callable-array callbacks.
		// Consume the next pre-generated URL from the queue instead.
		if ( '' === $pass_url || ! str_starts_with( $pass_url, 'http' ) ) {
			$pass_url = array_shift( self::$pass_url_queue ) ?? '';
		}

		if ( '' === $pass_url ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- User agent is only used for basic string checks, not output or API calls.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? \wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		$android    = '' !== $user_agent && stripos( $user_agent, 'Android' ) !== false;

		if ( $android ) {
			$android_badge = plugins_url( 'includes/badge_web_generic.png', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
			echo '<a href="https://www.walletpasses.io?u=' . rawurlencode( $pass_url ) . '" target="_system" rel="noopener noreferrer">'
				. '<img src="' . esc_url( $android_badge ) . '" alt="' . esc_attr__( 'Add to Wallet', 'commercebird-wallet-pass' ) . '" />'
				. '</a>';
			return;
		}

		$apple_badge = plugins_url( 'includes/add-to-apple-wallet.png', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
		echo '<a href="' . esc_url( self::buildProxyUrl( $pass_url ) ) . '" rel="noopener noreferrer">'
			. '<img src="' . esc_url( $apple_badge ) . '" width="100" alt="' . esc_attr__( 'Add to Apple Wallet', 'commercebird-wallet-pass' ) . '" />'
			. '</a>';
	}
}
