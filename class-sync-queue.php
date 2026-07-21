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

			$table_name = 'nvp_sync_queue';

			// Prepare optional values
			$ytid_val_sql   = ( null === $ytid ) ? 'NULL' : $wpdb->prepare( '%s', $ytid );
			$source_val_sql = ( null === $source ) ? 'NULL' : $wpdb->prepare( '%s', $source );

			// We use DUAL table for the INSERT ... SELECT pattern to handle duplicate checks
			// created_at is handled by the DB default CURRENT_TIMESTAMP usually, but we set explicit NOW() to match Python logic
			$sql = "INSERT INTO $table_name
                (post_id, ytid, target, priority, status, source, created_at)
                SELECT %d, $ytid_val_sql, %s, %d, 'pending', $source_val_sql, NOW()
                FROM DUAL
                WHERE NOT EXISTS (
                    SELECT 1 FROM $table_name
                    WHERE post_id = %d
                      AND target = %s
                      AND ytid <=> $ytid_val_sql
                      AND status IN ('pending', 'processing')
                )";

			// Prepare the final query
			// Arguments order:
			// 1. post_id (SELECT)
			// 2. target (SELECT)
			// 3. priority (SELECT)
			// 4. post_id (WHERE)
			// 5. target (WHERE)
			// Note: ytid_val_sql is already interpolated into the string safely

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$prepared_sql = $wpdb->prepare(
				$sql,
				$post_id,
				$target,
				$priority,
				$post_id,
				$target
			);

			$result = $wpdb->query( $prepared_sql );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

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
