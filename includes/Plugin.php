<?php

declare(strict_types=1);

namespace CommerceBird\WalletPass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private const CRON_HOOK = 'tc_wallet_cleanup_expired_passes';

	public static function bootstrap(): void {
		Admin::register();
		Api::register();

		// Option B update flow: when a tc_events post is saved/updated,
		// delete cached pass URLs for every ticket belonging to that event
		// so the next orders-page view regenerates with fresh event data.
		\add_action( 'save_post_tc_events', array( self::class, 'onEventSaved' ), 10, 1 );

		// Wire up the daily cleanup cron callback.
		\add_action( self::CRON_HOOK, array( self::class, 'cleanupExpiredPasses' ) );
	}

	/**
	 * Called on plugin activation. Schedules the daily cleanup if not already scheduled.
	 */
	public static function scheduleCleanup(): void {
		if ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( \time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Called on plugin deactivation. Removes the scheduled cron event.
	 */
	public static function clearCleanupSchedule(): void {
		$timestamp = \wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			\wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Daily cron job. Deletes .pkpass media files and cached pass URLs for:
	 *   - tickets whose event date is in the past, or
	 *   - tickets whose event post has been trashed or does not exist.
	 */
	public static function cleanupExpiredPasses(): void {
		// Fetch all ticket posts that have a cached pass URL.
		$ticket_ids = \get_posts(
			array(
				'post_type'      => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => Api::PASS_URL_META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( empty( $ticket_ids ) ) {
			return;
		}

		$now = \current_time( 'timestamp' );

		foreach ( $ticket_ids as $ticket_id ) {
			$event_id = \get_post_meta( (int) $ticket_id, 'event_id', true );

			$should_delete = false;

			if ( empty( $event_id ) ) {
				// Ticket has no linked event — clean up.
				$should_delete = true;
			} else {
				$event = \get_post( (int) $event_id );

				if ( ! $event || $event->post_status === 'trash' ) {
					// Event deleted or trashed.
					$should_delete = true;
				} else {
					// Check whether the event date has passed.
					$event_datetime = \get_post_meta( (int) $event_id, 'event_date_time', true );
					if ( ! empty( $event_datetime ) ) {
						$event_timestamp = \strtotime( (string) $event_datetime );
						if ( $event_timestamp !== false && $event_timestamp < $now ) {
							$should_delete = true;
						}
					}
				}
			}

			if ( ! $should_delete ) {
				continue;
			}

			// Delete the .pkpass attachment from the media library.
			$pass_url = \get_post_meta( (int) $ticket_id, Api::PASS_URL_META_KEY, true );
			if ( ! empty( $pass_url ) && is_string( $pass_url ) ) {
				$attachment_id = \attachment_url_to_postid( $pass_url );
				if ( $attachment_id > 0 ) {
					\wp_delete_attachment( $attachment_id, true );
				}
			}

			// Remove the cached meta so the next view regenerates if needed.
			\delete_post_meta( (int) $ticket_id, Api::PASS_URL_META_KEY );
		}
	}

	public static function onEventSaved( int $event_id ): void {
		// Skip autosaves and revisions.
		if ( \wp_is_post_autosave( $event_id ) || \wp_is_post_revision( $event_id ) ) {
			return;
		}

		$ticket_ids = \get_posts(
			array(
				'post_type'      => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'event_id',
						'value' => $event_id,
					),
				),
			)
		);

		foreach ( $ticket_ids as $ticket_id ) {
			Api::invalidatePassCache( (int) $ticket_id );
		}
	}

	public static function wpass_set_apple_mime_type(): void {
		if ( ! \function_exists( 'insert_with_markers' ) && \defined( 'ABSPATH' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! \function_exists( 'get_home_path' ) && \defined( 'ABSPATH' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! \function_exists( 'get_home_path' ) || ! \function_exists( 'insert_with_markers' ) ) {
			return;
		}

		$htaccess = \get_home_path() . '.htaccess';
		$lines    = array(
			'AddType application/vnd.apple.pkpass pkpass',
			'<FilesMatch "\\.pkpass$">',
			'<IfModule mod_headers.c>',
			'Header set Content-Disposition "inline"',
			'Header set X-Content-Type-Options "nosniff"',
			'</IfModule>',
			'</FilesMatch>',
		);

		\insert_with_markers( $htaccess, 'Apple Wallet Pass', $lines );
	}
}
