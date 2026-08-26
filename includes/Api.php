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

	private const CONNECTOR_ENDPOINT       = 'customs/wallet/pass';
	private const PROXY_ACTION             = 'commercebird_wallet_pass';
	public const  PASS_URL_META_KEY        = '_tc_wallet_pass_url';
	public const  GOOGLE_PASS_URL_META_KEY = '_tc_wallet_google_pass_url';

	/**
	 * Tickera passes the field_name string to callable-array callbacks, not the post-meta value.
	 * URL pairs are queued here by generatePassesForOrder / preloadPassUrlsForOrder before Tickera
	 * renders the column, then consumed one-by-one by renderWalletButton.
	 *
	 * @var array<int, array{apple: string, google: string}>
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
			$urls = self::generateURLforWallet( (int) $ticket_id, $order_id );
			if ( '' !== $urls['apple'] ) {
				self::$pass_url_queue[] = $urls;
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
			$apple = (string) get_post_meta( (int) $ticket_id, self::PASS_URL_META_KEY, true );
			if ( '' !== $apple ) {
				self::$pass_url_queue[] = array(
					'apple'  => $apple,
					'google' => (string) get_post_meta(
						(int) $ticket_id,
						self::GOOGLE_PASS_URL_META_KEY,
						true
					),
				);
			}
		}
	}

	/**
	 * Generates the wallet pass via the CommerceBird API, caches the resulting
	 * URLs in post-meta, and returns them. The Apple URL is served with the
	 * correct Content-Type for iOS Wallet via serveWalletPassProxy below; the
	 * Google URL is an ordinary HTTPS save link and is not proxied.
	 *
	 * The cache check below tests the Apple URL alone: once a ticket has a
	 * cached Apple pass, this method returns early and does not retry Google,
	 * even if the Google URL is still blank. A merchant who configures Google
	 * Wallet after tickets already exist should re-save the Wallet settings
	 * page in WP admin: saving invalidates every cached pass URL (it calls
	 * Api::invalidatePassCache() for each ticket), so the next render
	 * regenerates both URLs.
	 *
	 * @param int $ticket_id Tickera ticket (tc_tickets_instances) post id.
	 * @param int $order_id  WooCommerce order id, when known. Threaded through to
	 *                       the API as groupingInfo.groupingId so every ticket in
	 *                       the same order stacks together in Google Wallet. 0 when
	 *                       the caller has no order context; sent to the API as ''.
	 * @return array{apple: string, google: string}
	 */
	private static function generateURLforWallet( int $ticket_id, int $order_id = 0 ): array {
		$cached_apple  = (string) get_post_meta( $ticket_id, self::PASS_URL_META_KEY, true );
		$cached_google = (string) get_post_meta( $ticket_id, self::GOOGLE_PASS_URL_META_KEY, true );
		if ( '' !== $cached_apple ) {
			return array(
				'apple'  => $cached_apple,
				'google' => $cached_google,
			);
		}

		$ticket_meta = get_post_meta( $ticket_id, '', false );
		$event_id    = $ticket_meta['event_id'][0] ?? null;
		$ticket_code = $ticket_meta['ticket_code'][0] ?? '';
		$first_name  = isset( $ticket_meta['first_name'] ) ? reset( $ticket_meta['first_name'] ) : '';
		$last_name   = isset( $ticket_meta['last_name'] ) ? reset( $ticket_meta['last_name'] ) : '';

		if ( empty( $event_id ) || empty( $ticket_code )
			|| ! class_exists( 'Tickera\TC_Event' )
			|| ! class_exists( 'Tickera\TC_Ticket' ) ) {
			return array(
				'apple'  => '',
				'google' => '',
			);
		}

		$event_obj    = new \Tickera\TC_Event( (int) $event_id );
		$location_obj = get_post_meta( (int) $event_id, '', false );
		$ticket       = new \Tickera\TC_Ticket( $ticket_id );

		// Tickera core exposes no meta key for a venue address or an event end
		// time (confirmed in Task 9), so those are sent as ''; the Node API falls
		// back venue_address -> venue_name and omits dateTime.end when empty.
		$urls = self::callWalletPassApi(
			(string) ( $event_obj->details->post_title ?? '' ),
			(string) ( $location_obj['event_location'][0] ?? '' ),
			(string) ( $location_obj['event_date_time'][0] ?? '' ),
			(string) ( $ticket->details->post_title ?? '' ),
			$ticket_id,
			(string) $ticket_code,
			(string) $first_name,
			(string) $last_name,
			(string) $event_id,
			self::toIso8601( (string) ( $location_obj['event_date_time'][0] ?? '' ) ),
			'',
			(string) ( $location_obj['event_location'][0] ?? '' ),
			'',
			$order_id > 0 ? (string) $order_id : ''
		);

		if ( '' !== $urls['apple'] ) {
			update_post_meta( $ticket_id, self::PASS_URL_META_KEY, $urls['apple'] );
		}
		// A Google URL is only cached when one actually comes back from the API. But
		// the cache check above short-circuits on the Apple URL alone, so once this
		// ticket's Apple pass is cached, this line is not reached again on later
		// renders -- a still-blank Google URL is never retried once Apple is cached.
		// See generateURLforWallet()'s docblock for how to force a retry.
		if ( '' !== $urls['google'] ) {
			update_post_meta( $ticket_id, self::GOOGLE_PASS_URL_META_KEY, $urls['google'] );
		}

		return $urls;
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

		// if class TC_Orders doesn't exist, it means Tickera isn't active, so skip.
		if ( ! class_exists( '\Tickera\TC_Orders' ) ) {
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
			$urls = self::generateURLforWallet( (int) $order_attendee_id, $order->get_id() );
			if ( '' === $urls['apple'] && '' === $urls['google'] ) {
				continue;
			}
			$ticket_type_id = isset( $ticket_meta['ticket_type_id'] ) ? reset( $ticket_meta['ticket_type_id'] ) : '';
			$passes[]       = array(
				'title'  => get_the_title( $ticket_type_id ),
				'apple'  => $urls['apple'],
				'google' => $urls['google'],
			);
		}

		if ( empty( $passes ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Wallet Passes', 'commercebird-wallet-pass' ) . "\n";
			foreach ( $passes as $pass ) {
				if ( '' !== $pass['apple'] ) {
					echo esc_html( $pass['title'] ) . ': ' . esc_url( $pass['apple'] ) . "\n";
				}
				if ( '' !== $pass['google'] ) {
					echo esc_html( $pass['title'] ) . ' (Google Wallet): ' . esc_url( $pass['google'] ) . "\n";
				}
			}
			return;
		}

		// Email HTML is rendered at send time, so there is no recipient user agent
		// to branch on. Both badges are shown and the reader picks their own.
		echo '<h2 style="color:#333;font-family:inherit;">' . esc_html__( 'Your Wallet Passes', 'commercebird-wallet-pass' ) . '</h2>';
		foreach ( $passes as $pass ) {
			echo '<p>';
			echo '<strong>' . esc_html( $pass['title'] ) . '</strong><br>';
			if ( '' !== $pass['apple'] ) {
				self::renderAppleBadge( $pass['apple'] );
			}
			if ( '' !== $pass['google'] ) {
				self::renderGoogleBadge( $pass['google'] );
			}
			echo '</p>';
		}
	}

	/**
	 * Largest icon payload the API accepts, in base64 characters.
	 * Base64 inflates by 4/3, so this is roughly 500 KB of image data.
	 */
	private const MAX_ICON_BASE64_LENGTH = 700000;

	private static function iconToBase64( string $abs_path ): string {
		if ( '' === $abs_path || ! file_exists( $abs_path ) ) {
			return '';
		}
		$bytes = file_get_contents( $abs_path );
		return ( false === $bytes ) ? '' : base64_encode( $bytes );
	}

	/**
	 * Resolves the pass artwork to a PNG small enough for the API.
	 *
	 * get_attached_file() hands back the full-size original, which for a typical
	 * logo upload is far larger than the request budget and gets the whole pass
	 * rejected. Registered intermediate sizes are tried first, largest to
	 * smallest, and only a PNG is returned — Apple will not render anything else
	 * as icon.png.
	 *
	 * @param int $attachment_id Media library ID of the configured icon.
	 * @return string Base64-encoded PNG, or '' to let the API use its default.
	 */
	private static function resolveIconData( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		if ( 'image/png' !== \get_post_mime_type( $attachment_id ) ) {
			return '';
		}

		$upload_dir = \wp_get_upload_dir();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
		$candidates = array();

		// Wallet icons top out at 87x87 (29pt @3x); anything larger is wasted bytes.
		foreach ( array( 'medium', 'thumbnail' ) as $size ) {
			$intermediate = \image_get_intermediate_size( $attachment_id, $size );
			if ( is_array( $intermediate ) && ! empty( $intermediate['path'] ) && '' !== $base_dir ) {
				$candidates[] = $base_dir . '/' . $intermediate['path'];
			}
		}

		$candidates[] = (string) \get_attached_file( $attachment_id );

		foreach ( $candidates as $candidate ) {
			$encoded = self::iconToBase64( $candidate );
			if ( '' !== $encoded && strlen( $encoded ) <= self::MAX_ICON_BASE64_LENGTH ) {
				return $encoded;
			}
		}

		if ( class_exists( 'WC_Logger' ) ) {
			wc_get_logger()->warning(
				'Wallet Pass icon is too large to send; the default icon will be used. Upload a PNG of about 87x87 pixels.',
				array( 'source' => 'commercebird-wallet-pass' )
			);
		}

		return '';
	}

	/**
	 * Public URL of the configured icon.
	 *
	 * Google Wallet fetches class images server-side and rejects base64, so the
	 * URL is sent alongside the base64 Apple still needs.
	 *
	 * @param int $attachment_id Media library ID of the configured icon.
	 * @return string Public URL, or '' when there is nothing usable.
	 */
	private static function resolveIconUrl( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$url = \wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		// Google fetches the image itself, so an http:// URL fails class insertion.
		return str_starts_with( $url, 'https://' ) ? $url : '';
	}

	/**
	 * Normalises an event date string to ISO 8601 in the site's timezone.
	 *
	 * WordPress forces PHP's default timezone to UTC, so strtotime() on
	 * Tickera's local wall-clock event_date_time (e.g. "2026-09-01 20:00:00")
	 * would produce the epoch for that time treated as UTC. Handing that
	 * epoch to wp_date() then converts it into the site timezone, applying
	 * the offset a second time (20:00 local becomes 22:00 on a UTC+2 site).
	 * date_create_immutable() is given the site timezone directly so the raw
	 * string is interpreted as local wall-clock time exactly once.
	 *
	 * The API omits the pass dateTime block for an empty string, so an
	 * unparseable date degrades the pass instead of failing it.
	 *
	 * @param string $raw Raw value from the event post meta.
	 * @return string ISO 8601 timestamp, or ''.
	 */
	private static function toIso8601( string $raw ): string {
		$value = trim( $raw );
		if ( '' === $value ) {
			return '';
		}

		$dt = \date_create_immutable( $value, \wp_timezone() );
		return $dt ? $dt->format( 'c' ) : '';
	}

	/**
	 * Normalises a colour-picker value to #rrggbb.
	 *
	 * Anything the API cannot parse silently degrades the pass to the default
	 * grey, so shorthand and unhashed values are expanded here.
	 *
	 * @param string $color    Raw value from the colour picker.
	 * @param string $fallback Colour to use when $color cannot be parsed.
	 * @return string Colour as #rrggbb.
	 */
	private static function normalizeHexColor( string $color, string $fallback = '#aaaaaa' ): string {
		$value = ltrim( trim( $color ), '#' );

		if ( 3 === strlen( $value ) && ctype_xdigit( $value ) ) {
			$value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
		}

		if ( 6 !== strlen( $value ) || ! ctype_xdigit( $value ) ) {
			return $fallback;
		}

		return '#' . strtolower( $value );
	}

	public static function invalidatePassCache( int $ticket_id ): void {
		delete_post_meta( $ticket_id, self::PASS_URL_META_KEY );
		delete_post_meta( $ticket_id, self::GOOGLE_PASS_URL_META_KEY );
	}

	private static function callWalletPassApi(
		string $event_title,
		string $location,
		string $datetime,
		string $ticket_title,
		int $ticket_id,
		string $ticket_code,
		string $first_name,
		string $last_name,
		string $event_id = '',
		string $event_start = '',
		string $event_end = '',
		string $venue_name = '',
		string $venue_address = '',
		string $order_id = ''
	): array {
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
			'icon_data'         => self::resolveIconData( (int) ( $settings['icon_file_id'] ?? 0 ) ),
			'logo_text'         => (string) ( $settings['logo_text'] ?? '' ),
			'background_color'  => self::normalizeHexColor( (string) ( $settings['background_color'] ?? '' ) ),
			'organisation_name' => (string) ( $settings['organisation_name'] ?? '' ),
			// Google Wallet needs a fetchable image, a real timestamp and a split venue.
			'icon_url'          => self::resolveIconUrl( (int) ( $settings['icon_file_id'] ?? 0 ) ),
			'event_id'          => $event_id,
			'event_start'       => $event_start,
			'event_end'         => $event_end,
			'venue_name'        => $venue_name,
			'venue_address'     => $venue_address,
			'homepage_uri'      => \home_url(),
			'order_id'          => $order_id,
		);

		if ( ! class_exists( 'CommerceBird\\Admin\\Connectors\\Connector' ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Connector class not found. Ensure the CommerceBird plugin is active.', array( 'source' => 'tickera-wallet-pass' ) );
			}
			return array(
				'apple'  => '',
				'google' => '',
			);
		}

		$connector = new \CommerceBird\Admin\Connectors\Connector();
		$response  = $connector->request( self::CONNECTOR_ENDPOINT, 'POST', $payload );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Wallet Pass API request failed: ' . $response->get_error_message(), array( 'source' => 'tickera-wallet-pass' ) );
			}
			return array(
				'apple'  => '',
				'google' => '',
			);
		}

		if ( ! is_array( $response ) || ( $response['code'] ?? null ) !== 200 ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Wallet Pass API error: ' . ( $response['message'] ?? 'unexpected response' ), array( 'source' => 'tickera-wallet-pass' ) );
			}
			return array(
				'apple'  => '',
				'google' => '',
			);
		}

		$data       = $response['data'] ?? array();
		$pass_url   = $data['pass_url'] ?? $data['url'] ?? null;
		$google_url = $data['google_pass_url'] ?? null;

		return array(
			'apple'  => is_string( $pass_url ) ? \esc_url_raw( $pass_url ) : '',
			'google' => is_string( $google_url ) ? \esc_url_raw( $google_url ) : '',
		);
	}

	/**
	 * Renders the Apple and/or Google wallet badge(s) for a ticket.
	 *
	 * Tickera normally passes the field_name string, not the meta value, to
	 * callable-array callbacks -- in that case the next pre-generated pair is
	 * consumed from the queue instead. But some Tickera versions and
	 * third-party callers pass the already-resolved URL directly; a non-empty
	 * string starting with "http" is treated as the Apple URL and rendered as
	 * such rather than discarded.
	 *
	 * @param string|array{apple: string, google: string} $pass_url Pre-generated pair from the queue, a direct Apple URL, or '' to pull the next queued entry.
	 */
	public static function renderWalletButton( $pass_url = '' ): void {
		if ( is_string( $pass_url ) && '' !== $pass_url && str_starts_with( $pass_url, 'http' ) ) {
			$urls = array(
				'apple'  => $pass_url,
				'google' => '',
			);
		} else {
			$urls = is_array( $pass_url ) ? $pass_url : array(
				'apple'  => '',
				'google' => '',
			);
			if ( '' === $urls['apple'] ) {
				$urls = array_shift( self::$pass_url_queue ) ?? array(
					'apple'  => '',
					'google' => '',
				);
			}
		}

		if ( '' === $urls['apple'] && '' === $urls['google'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- User agent is only used for basic string checks, not output or API calls.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? \wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		$android    = '' !== $user_agent && stripos( $user_agent, 'Android' ) !== false;

		if ( $android && '' !== $urls['google'] ) {
			self::renderGoogleBadge( $urls['google'] );
			return;
		}

		if ( '' !== $urls['apple'] ) {
			self::renderAppleBadge( $urls['apple'] );
		}
	}

	private static function renderAppleBadge( string $apple_url ): void {
		$badge = plugins_url( 'includes/add-to-apple-wallet.png', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
		echo '<a href="' . esc_url( self::buildProxyUrl( $apple_url ) ) . '" rel="noopener noreferrer">'
			. '<img src="' . esc_url( $badge ) . '" width="100" alt="' . esc_attr__( 'Add to Apple Wallet', 'commercebird-wallet-pass' ) . '" />'
			. '</a>';
	}

	/**
	 * The save link is an ordinary HTTPS page, so it is not routed through
	 * serveWalletPassProxy - that exists only to set the .pkpass headers iOS needs.
	 *
	 * The official "Add to Google Wallet" badge is trademark-protected artwork that
	 * Google requires be used unmodified, so it ships as a separate binary asset
	 * (includes/add-to-google-wallet.png) rather than being generated here. Until
	 * that file is present, a plain accessible text link is rendered instead of an
	 * <img> that would 404.
	 *
	 * @param string $google_url Google Wallet save link.
	 */
	private static function renderGoogleBadge( string $google_url ): void {
		$badge_path = __DIR__ . '/add-to-google-wallet.png';

		if ( file_exists( $badge_path ) ) {
			$badge = plugins_url( 'includes/add-to-google-wallet.png', dirname( __DIR__ ) . '/tickera-wallet-pass.php' );
			echo '<a href="' . esc_url( $google_url ) . '" rel="noopener noreferrer">'
				. '<img src="' . esc_url( $badge ) . '" width="100" alt="' . esc_attr__( 'Add to Google Wallet', 'commercebird-wallet-pass' ) . '" />'
				. '</a>';
			return;
		}

		echo '<a href="' . esc_url( $google_url ) . '" rel="noopener noreferrer">'
			. esc_html__( 'Add to Google Wallet', 'commercebird-wallet-pass' )
			. '</a>';
	}
}
