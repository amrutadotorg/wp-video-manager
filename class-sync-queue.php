<?php

/**
 * Class Sync_Queue
 *
 * Manages insertions into the nvp_sync_queue table.
 */
if ( ! class_exists( 'Sync_Queue' ) ) {
	class Sync_Queue {

		/**
		 * Queue a task with duplicate prevention.
		 *
		 * @param int    $post_id   WordPress post ID.
		 * @param string $target    Target sync handler (e.g., 'youtube', 'vimeo').
		 * @param int    $priority  Priority (higher executes first). Default 10.
		 * @param string|null $ytid Optional YouTube ID.
		 * @param string|null $source Optional source identifier (e.g. 'geo-sync').
		 *
		 * @return bool|int False on failure or duplicate, number of rows affected (1) on success.
		 */
		public static function queue_task( $post_id, $target, $priority = 10, $ytid = null, $source = null ) {
			global $wpdb;

			// This queue is shared with external sync workers, so it intentionally has no WordPress table prefix.
			if ( null === $ytid && null === $source ) {
				$prepared_sql = $wpdb->prepare(
					"INSERT INTO nvp_sync_queue
						(post_id, ytid, target, priority, status, source, created_at)
						SELECT %d, NULL, %s, %d, 'pending', NULL, NOW()
						FROM DUAL
						WHERE NOT EXISTS (
							SELECT 1 FROM nvp_sync_queue
							WHERE post_id = %d
								AND target = %s
								AND ytid <=> NULL
								AND status IN ('pending', 'processing')
						)",
					$post_id,
					$target,
					$priority,
					$post_id,
					$target
				);
			} elseif ( null === $source ) {
				$prepared_sql = $wpdb->prepare(
					"INSERT INTO nvp_sync_queue
						(post_id, ytid, target, priority, status, source, created_at)
						SELECT %d, %s, %s, %d, 'pending', NULL, NOW()
						FROM DUAL
						WHERE NOT EXISTS (
							SELECT 1 FROM nvp_sync_queue
							WHERE post_id = %d
								AND target = %s
								AND ytid <=> %s
								AND status IN ('pending', 'processing')
						)",
					$post_id,
					$ytid,
					$target,
					$priority,
					$post_id,
					$target,
					$ytid
				);
			} elseif ( null === $ytid ) {
				$prepared_sql = $wpdb->prepare(
					"INSERT INTO nvp_sync_queue
						(post_id, ytid, target, priority, status, source, created_at)
						SELECT %d, NULL, %s, %d, 'pending', %s, NOW()
						FROM DUAL
						WHERE NOT EXISTS (
							SELECT 1 FROM nvp_sync_queue
							WHERE post_id = %d
								AND target = %s
								AND ytid <=> NULL
								AND status IN ('pending', 'processing')
						)",
					$post_id,
					$target,
					$priority,
					$source,
					$post_id,
					$target
				);
			} else {
				$prepared_sql = $wpdb->prepare(
					"INSERT INTO nvp_sync_queue
						(post_id, ytid, target, priority, status, source, created_at)
						SELECT %d, %s, %s, %d, 'pending', %s, NOW()
						FROM DUAL
						WHERE NOT EXISTS (
							SELECT 1 FROM nvp_sync_queue
							WHERE post_id = %d
								AND target = %s
								AND ytid <=> %s
								AND status IN ('pending', 'processing')
						)",
					$post_id,
					$ytid,
					$target,
					$priority,
					$source,
					$post_id,
					$target,
					$ytid
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared_sql is prepared in every branch above.
			$result = $wpdb->query( $prepared_sql );

			if ( $result ) {
				$log_parts = array( "post_id=$post_id", "target=$target", "priority=$priority" );
				if ( null !== $ytid ) {
					$log_parts[] = "ytid=$ytid";
				}
				// Look up vimeo id for this post
				$vimeo_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT video_id FROM {$wpdb->prefix}post_videos WHERE post_id = %d AND platform = 'vimeo' AND is_old = 0 LIMIT 1",
						$post_id
					)
				);
				if ( $vimeo_id ) {
					$log_parts[] = "vimeoid=$vimeo_id";
				}
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					WP_CLI::log( 'Queued sync task: ' . implode( ', ', $log_parts ) );
				}
			}

			return $result;
		}
	}
}
