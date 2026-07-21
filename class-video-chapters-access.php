<?php
/**
 * Access control for Video Chapters Manager.
 *
 * Determines which non-admin users are allowed to use the plugin,
 * based on a configurable whitelist stored in wp_options.
 * Administrators (manage_options) always retain access, regardless
 * of the whitelist content, so the plugin can never lock itself out.
 */

if ( ! class_exists( 'Video_Chapters_Access' ) ) {

	class Video_Chapters_Access {

		const OPTION_KEY = 'video_chapters_allowed_users';

		private static $instance = null;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {}

		/**
		 * Runs on plugin activation. Creates the option only if it doesn't
		 * exist yet, so re-activating / updating the plugin never wipes
		 * an existing list.
		 */
		public static function activate() {
			if ( false === get_option( self::OPTION_KEY, false ) ) {
				add_option( self::OPTION_KEY, array(), '', 'no' );
			}
		}

		/**
		 * Whether the given user (or current user) is allowed to use the plugin.
		 *
		 * @param int|null $user_id
		 * @return bool
		 */
		public function user_has_access( $user_id = null ) {
			$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

			if ( ! $user_id ) {
				return false;
			}

			if ( user_can( $user_id, 'manage_options' ) ) {
				return true;
			}

			return in_array( $user_id, $this->get_allowed_users(), true );
		}

		/**
		 * Returns the list of allowed user IDs (sanitized, deduplicated).
		 *
		 * @return int[]
		 */
		public function get_allowed_users() {
			$stored = get_option( self::OPTION_KEY, array() );

			if ( ! is_array( $stored ) ) {
				return array();
			}

			return array_values( array_unique( array_map( 'absint', $stored ) ) );
		}

		/**
		 * Returns allowed users as WP_User objects, for rendering the admin list.
		 * IDs that no longer correspond to an existing user are skipped.
		 *
		 * @return WP_User[]
		 */
		public function get_allowed_users_data() {
			$users = array();

			foreach ( $this->get_allowed_users() as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$users[] = $user;
				}
			}

			return $users;
		}

		/**
		 * Overwrites the whole list. IDs that don't correspond to an existing
		 * WP user are silently dropped (self-cleaning on every save).
		 *
		 * @param int[] $user_ids
		 * @return int[] The sanitized list that was actually saved.
		 */
		public function set_allowed_users( array $user_ids ) {
			$sanitized = array_values( array_unique( array_map( 'absint', $user_ids ) ) );

			$sanitized = array_values(
				array_filter(
					$sanitized,
					function ( $id ) {
						return (bool) get_userdata( $id );
					}
				)
			);

			update_option( self::OPTION_KEY, $sanitized, false );

			return $sanitized;
		}

		/**
		 * Adds a single user to the list.
		 *
		 * @param int $user_id
		 * @return int[]|WP_Error Updated list, or WP_Error if the user doesn't exist.
		 */
		public function add_user( $user_id ) {
			$user_id = absint( $user_id );

			if ( ! get_userdata( $user_id ) ) {
				return new WP_Error( 'invalid_user', 'User does not exist.' );
			}

			$current = $this->get_allowed_users();

			if ( ! in_array( $user_id, $current, true ) ) {
				$current[] = $user_id;
			}

			return $this->set_allowed_users( $current );
		}

		/**
		 * Removes a single user from the list.
		 *
		 * @param int $user_id
		 * @return int[] Updated list.
		 */
		public function remove_user( $user_id ) {
			$user_id = absint( $user_id );
			$current = $this->get_allowed_users();
			$current = array_diff( $current, array( $user_id ) );

			return $this->set_allowed_users( $current );
		}
	}
}
