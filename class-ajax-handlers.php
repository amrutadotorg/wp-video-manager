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
		private $access;

		public function __construct() {
			$this->db     = Video_Chapters_DB::get_instance();
			$this->access = Video_Chapters_Access::get_instance();
		}

		/**
		 * AJAX: Autocomplete chapter titles.
		 */
		public function get_chapter_titles() {
			check_ajax_referer( 'video-chapters-nonce', 'nonce' );

			if ( ! $this->access->user_has_access() ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$term   = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
			$titles = $this->db->get_chapter_titles( $term );

			wp_send_json( $titles );
		}

		/**
		 * AJAX: Search video by YouTube ID.
		 */
		public function search_video() {
			check_ajax_referer( 'video-chapters-nonce', 'nonce' );

			if ( ! $this->access->user_has_access() ) {
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
			global $wpdb;

			try {
				if ( ! check_ajax_referer( 'video-chapters-nonce', 'nonce', false ) ) {
					throw new Exception( 'Security verification failed' );
				}

				if ( ! $this->access->user_has_access() ) {
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
				$internal_id        = $this->db->get_internal_id( $youtube_id, $post_id );

				if ( ! $internal_id ) {
					throw new Exception( "Internal video record not found for YouTube ID $youtube_id" );
				}

				// Check if chapters actually changed
				$existing_chapters = $this->db->get_chapters( $internal_id );
				if ( wp_json_encode( $existing_chapters ) === wp_json_encode( $validated_chapters ) ) {
					wp_send_json_success(
						array(
							'message'       => 'No changes detected. Chapters are up to date.',
							'post_id'       => $post_id,
							'video_id'      => $internal_id,
							'chapter_count' => count( $validated_chapters ),
						)
					);
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
				$this->log_error( $e, $post_id ?? null, $youtube_id ?? null );
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
		 * AJAX: Search WP users, to help pick who to add to the allowed-users list.
		 * Restricted to manage_options — only admins manage the whitelist.
		 */
		public function search_users() {
			check_ajax_referer( 'video-chapters-users-nonce', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';

			if ( '' === $term ) {
				wp_send_json_success( array() );
			}

			$allowed = $this->access->get_allowed_users();
			$args    = array(
				'search'         => '*' . esc_attr( $term ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => 20,
				'role__not_in'   => array( 'administrator' ),
			);
			if ( ! empty( $allowed ) ) {
				$args['exclude'] = $allowed;
			}
			$query = new WP_User_Query( $args );

			$results = array();

			foreach ( $query->get_results() as $user ) {
				$results[] = array(
					'id'    => $user->ID,
					'label' => sprintf( '%s (%s)', $user->display_name, $user->user_login ),
					'email' => $user->user_email,
				);
			}

			wp_send_json_success( $results );
		}

		/**
		 * AJAX: Add or remove a user from the allowed-users list.
		 * Restricted to manage_options — someone on the list can never edit it.
		 */
		public function save_allowed_users() {
			check_ajax_referer( 'video-chapters-users-nonce', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$action_type = isset( $_POST['user_action'] ) ? sanitize_text_field( $_POST['user_action'] ) : '';
			$user_id     = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

			if ( ! $user_id ) {
				wp_send_json_error( 'Missing user_id' );
			}

			if ( 'add' === $action_type ) {
				$result = $this->access->add_user( $user_id );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}
			} elseif ( 'remove' === $action_type ) {
				$this->access->remove_user( $user_id );
			} else {
				wp_send_json_error( 'Unknown action' );
			}

			$user = get_userdata( $user_id );

			wp_send_json_success(
				array(
					'user_id'      => $user_id,
					'display_name' => $user ? esc_html( $user->display_name ) : '',
					'user_login'   => $user ? esc_html( $user->user_login ) : '',
					'user_email'   => $user ? esc_html( $user->user_email ) : '',
					'action'       => $action_type,
				)
			);
		}

		private function time_to_seconds( $time_str ) {
			$parts = array_map( 'intval', explode( ':', $time_str ) );
			if ( count( $parts ) === 3 ) {
				return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
			}
			return $parts[0] * 60 + $parts[1];
		}

		/**
		 * Sanitize and validate chapter data from POST.
		 */
		private function validate_chapters( $chapters ) {
			if ( empty( $chapters ) ) {
				return array();
			}

			$validated = array();
			foreach ( $chapters as $chapter ) {
				if ( empty( $chapter['startChapter'] ) || empty( $chapter['title'] ) ) {
					continue;
				}

				if ( ! preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', $chapter['startChapter'] ) ) {
					throw new Exception( 'Invalid time format for chapter: ' . esc_html( $chapter['startChapter'] ) );
				}

				$validated[] = array(
					'startChapter' => sanitize_text_field( $chapter['startChapter'] ),
					'title'        => sanitize_text_field( stripslashes( $chapter['title'] ) ),
				);
			}

			if ( count( $validated ) > 0 ) {
				if ( count( $validated ) < 3 ) {
					throw new Exception( 'You must include at least three separate chapters.' );
				}

				usort(
					$validated,
					function ( $a, $b ) {
						return $this->time_to_seconds( $a['startChapter'] ) - $this->time_to_seconds( $b['startChapter'] );
					}
				);

				if ( $this->time_to_seconds( $validated[0]['startChapter'] ) !== 0 ) {
					throw new Exception( 'The very first timestamp on your list must be exactly 0:00.' );
				}
			}

			return $validated;
		}

		/**
		 * Log error with context.
		 */
		private function log_error( Exception $e, $post_id = null, $youtube_id = null ) {
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
