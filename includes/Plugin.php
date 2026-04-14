<?php

declare(strict_types=1);

namespace Tickera\WalletPass;

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
		$ticketIds = \get_posts(
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

		if ( empty( $ticketIds ) ) {
			return;
		}

		$now = \current_time( 'timestamp' );

		foreach ( $ticketIds as $ticketId ) {
			$eventId = \get_post_meta( (int) $ticketId, 'event_id', true );

			$shouldDelete = false;

			if ( empty( $eventId ) ) {
				// Ticket has no linked event — clean up.
				$shouldDelete = true;
			} else {
				$event = \get_post( (int) $eventId );

				if ( ! $event || $event->post_status === 'trash' ) {
					// Event deleted or trashed.
					$shouldDelete = true;
				} else {
					// Check whether the event date has passed.
					$eventDatetime = \get_post_meta( (int) $eventId, 'event_date_time', true );
					if ( ! empty( $eventDatetime ) ) {
						$eventTimestamp = \strtotime( (string) $eventDatetime );
						if ( $eventTimestamp !== false && $eventTimestamp < $now ) {
							$shouldDelete = true;
						}
					}
				}
			}

			if ( ! $shouldDelete ) {
				continue;
			}

			// Delete the .pkpass attachment from the media library.
			$passUrl = \get_post_meta( (int) $ticketId, Api::PASS_URL_META_KEY, true );
			if ( ! empty( $passUrl ) && is_string( $passUrl ) ) {
				$attachmentId = \attachment_url_to_postid( $passUrl );
				if ( $attachmentId > 0 ) {
					\wp_delete_attachment( $attachmentId, true );
				}
			}

			// Remove the cached meta so the next view regenerates if needed.
			\delete_post_meta( (int) $ticketId, Api::PASS_URL_META_KEY );
		}
	}

	public static function onEventSaved( int $eventId ): void {
		// Skip autosaves and revisions.
		if ( \wp_is_post_autosave( $eventId ) || \wp_is_post_revision( $eventId ) ) {
			return;
		}

		$ticketIds = \get_posts(
			array(
				'post_type'      => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'event_id',
						'value' => $eventId,
					),
				),
			)
		);

		foreach ( $ticketIds as $ticketId ) {
			Api::invalidatePassCache( (int) $ticketId );
		}
	}

	public static function setAppleMimeType(): void {
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
		$lines    = array( 'AddType application/vnd.apple.pkpass    pkpass' );

		\insert_with_markers( $htaccess, 'Apple Wallet Pass', $lines );
	}
}
