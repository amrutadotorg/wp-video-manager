<?php
/**
 * AJAX request handlers for Video Chapters Manager.
 *
 * Each method handles one wp_ajax_* action.
 * Validation, capability checks, and JSON responses live here.
 * DB calls are delegated to Video_Chapters_DB.
 */

if ( ! class_exists( 'Video_Chapters_AJAX' ) ) {

	class Video_Chapters_AJAX {

		private $db;

		public function __construct() {
			$this->db = Video_Chapters_DB::get_instance();
		}

		/**
		 * AJAX: Autocomplete chapter titles.
		 */
		public function get_chapter_titles() {
			check_ajax_referer( 'video-chapters-nonce', 'nonce' );

			$term   = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
			$titles = $this->db->get_chapter_titles( $term );

			wp_send_json( $titles );
		}

		/**
		 * AJAX: Search video by YouTube ID.
		 */
		public function search_video() {
			check_ajax_referer( 'video-chapters-nonce', 'nonce' );

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$youtube_id = isset( $_POST['youtube_id'] ) ? sanitize_text_field( $_POST['youtube_id'] ) : '';
			$video_data = $this->db->get_video_by_youtube_id( $youtube_id );

			if ( ! $video_data ) {
				wp_send_json_error( 'Video not found in wp_post_videos' );
			}

			$chapters_results = $this->db->get_chapters( $video_data->internal_id );

			wp_send_json_success(
				array(
					'id'       => $video_data->post_id,
					'title'    => esc_html( $video_data->post_title ),
					'ytid'     => esc_attr( $youtube_id ),
					'chapters' => wp_json_encode( $chapters_results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				)
			);
		}

		/**
		 * AJAX: Save chapters for a video.
		 */
		public function save_chapters() {
			try {
				if ( ! check_ajax_referer( 'video-chapters-nonce', 'nonce', false ) ) {
					throw new Exception( 'Security verification failed' );
				}

				if ( ! current_user_can( 'edit_posts' ) ) {
					wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
				}

				$post_id    = filter_input( INPUT_POST, 'video_id', FILTER_VALIDATE_INT );
				$youtube_id = isset( $_POST['youtube_id'] ) ? sanitize_text_field( $_POST['youtube_id'] ) : '';
				$chapters   = isset( $_POST['chapters'] ) ? $_POST['chapters'] : array();

				if ( ! $post_id || ! $youtube_id ) {
					throw new Exception(
						'Missing required data: ' .
						( ! $post_id ? 'post_id ' : '' ) .
						( ! $youtube_id ? 'youtube_id' : '' )
					);
				}

				if ( ! get_post( $post_id ) ) {
					throw new Exception( "Post $post_id not found" );
				}

				$validated_chapters = $this->validate_chapters( $chapters );

				$internal_id = $this->db->get_internal_id( $youtube_id, $post_id );

				if ( ! $internal_id ) {
					throw new Exception( "Internal video record not found for YouTube ID $youtube_id" );
				}

				$count = $this->db->save_chapters( $internal_id, $validated_chapters );

				error_log( "Successfully saved $count chapters for video $internal_id (Post $post_id)" );

				if ( class_exists( 'Sync_Queue' ) ) {
					Sync_Queue::queue_task( $post_id, 'youtube', 10, $youtube_id, 'video-chapters-manager' );
				}

				wp_send_json_success(
					array(
						'message'       => empty( $chapters ) ? 'Chapters cleared successfully' : 'Chapters saved successfully',
						'post_id'       => $post_id,
						'video_id'      => $internal_id,
						'chapter_count' => $count,
					)
				);

			} catch ( Exception $e ) {
				$this->log_error( $e );

				wp_send_json_error(
					array(
						'message' => $e->getMessage(),
						'debug'   => WP_DEBUG ? array(
							'db_error' => $wpdb->last_error ?? 'no error',
							'db_query' => $wpdb->last_query ?? 'no query',
							'trace'    => $e->getTraceAsString(),
						) : null,
					)
				);
			}
		}

		/**
		 * Sanitize and validate chapter data from POST.
		 */
		private function validate_chapters( $chapters ) {
			if ( empty( $chapters ) ) {
				return array();
			}

			return array_map(
				function ( $chapter ) {
					return array(
						'startChapter' => sanitize_text_field( $chapter['startChapter'] ),
						'title'        => sanitize_text_field( stripslashes( $chapter['title'] ) ),
					);
				},
				$chapters
			);
		}

		/**
		 * Log error with context.
		 */
		private function log_error( Exception $e ) {
			global $wpdb;

			error_log( 'Video Chapters Manager Error: ' . $e->getMessage() );
			error_log(
				'Error context: ' . print_r(
					array(
						'post_id'         => $post_id ?? 'not set',
						'youtube_id'      => $youtube_id ?? 'not set',
						'wpdb_last_error' => $wpdb->last_error ?? 'no error',
						'wpdb_last_query' => $wpdb->last_query ?? 'no query',
						'php_error'       => error_get_last(),
					),
					true
				)
			);
		}
	}
}
