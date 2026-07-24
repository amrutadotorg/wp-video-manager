<?php
/**
 * Database access layer for Video Chapters Manager.
 *
 * All $wpdb interactions are centralized here.
 */

if ( ! class_exists( 'Video_Chapters_DB' ) ) {

	class Video_Chapters_DB {

		private static $instance = null;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Find a video by YouTube ID.
		 */
		public function get_video_by_youtube_id( $youtube_id ) {
			global $wpdb;

			return $wpdb->get_row(
				$wpdb->prepare(
					"
					SELECT v.id as internal_id, v.post_id, p.post_title, v.video_id as ytid
					FROM {$wpdb->prefix}post_videos v
					JOIN {$wpdb->posts} p ON v.post_id = p.ID
					WHERE v.platform = 'youtube' AND v.video_id = %s
					LIMIT 1
					",
					$youtube_id
				)
			);
		}

		/**
		 * Get chapters for a video (by internal video ID).
		 */
		public function get_chapters( $internal_id ) {
			global $wpdb;

			return $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT start_time as startChapter, title
					FROM {$wpdb->prefix}post_video_chapters
					WHERE video_id = %d
					ORDER BY sort_order ASC
					",
					$internal_id
				),
				ARRAY_A
			);
		}

		/**
		 * Get internal video ID from wp_post_videos.
		 */
		public function get_internal_id( $youtube_id, $post_id ) {
			global $wpdb;

			return $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT id FROM {$wpdb->prefix}post_videos
					WHERE platform = 'youtube' AND video_id = %s AND post_id = %d
					",
					$youtube_id,
					$post_id
				)
			);
		}

		/**
		 * Search chapter titles for autocomplete.
		 */
		public function get_chapter_titles( $term ) {
			global $wpdb;

			$query = $wpdb->prepare(
				"
				SELECT c.title
				FROM {$wpdb->prefix}post_video_chapters c
				INNER JOIN {$wpdb->prefix}post_videos v ON v.id = c.video_id
				WHERE c.title LIKE %s
				AND v.is_old = 0
				AND v.platform = 'youtube'
				GROUP BY c.title
				ORDER BY COUNT(*) DESC, c.title ASC
				LIMIT 10
				",
				'%' . $wpdb->esc_like( $term ) . '%'
			);

			return $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Save chapters for a video (delete + insert in transaction).
		 *
		 * @param int   $internal_id Video ID in wp_post_videos.
		 * @param array $chapters    Array of ['startChapter' => string, 'title' => string].
		 * @return int Number of chapters saved.
		 * @throws Exception On failure.
		 */
		public function save_chapters( $internal_id, $chapters ) {
			global $wpdb;

			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new Exception( 'Failed to start chapter save transaction: ' . esc_html( $wpdb->last_error ) );
			}

			try {
				if (
					false === $wpdb->delete(
						"{$wpdb->prefix}post_video_chapters",
						array( 'video_id' => $internal_id )
					)
				) {
					throw new Exception( 'Failed to remove existing chapters: ' . esc_html( $wpdb->last_error ) );
				}

				$count = 0;
				foreach ( $chapters as $index => $chapter ) {
					$result = $wpdb->insert(
						"{$wpdb->prefix}post_video_chapters",
						array(
							'video_id'   => $internal_id,
							'start_time' => $chapter['startChapter'],
							'title'      => $chapter['title'],
							'sort_order' => $index + 1,
						)
					);

					if ( false === $result ) {
						throw new Exception( 'Failed to insert chapter: ' . esc_html( $wpdb->last_error ) );
					}
					++$count;
				}

				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new Exception( 'Failed to commit chapter save transaction: ' . esc_html( $wpdb->last_error ) );
				}

				return $count;
			} catch ( Exception $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
		}

		/**
		 * Create custom tables on plugin activation.
		 */
		public static function activate() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			$sql_videos = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}post_videos (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				post_id bigint(20) NOT NULL,
				video_id varchar(255) NOT NULL,
				platform varchar(50) NOT NULL DEFAULT 'youtube',
				is_old tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY post_id (post_id),
				KEY video_id (video_id),
				UNIQUE KEY platform_video (platform, video_id)
			) $charset_collate;";

			$sql_chapters = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}post_video_chapters (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				video_id bigint(20) NOT NULL,
				start_time varchar(20) NOT NULL,
				title varchar(255) NOT NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY video_id (video_id),
				KEY sort_order (sort_order)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_videos );
			dbDelta( $sql_chapters );
		}
	}
}
