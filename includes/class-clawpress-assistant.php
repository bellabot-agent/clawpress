<?php
/**
 * ClawPress Assistant — role registration and assistant user management.
 *
 * @package ClawPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClawPress_Assistant {

	const ROLE = 'ai_assistant';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'maybe_register_role' ) );
		register_activation_hook( CLAWPRESS_PLUGIN_DIR . 'clawpress.php', array( $this, 'register_role' ) );
	}

	/**
	 * Register the ai_assistant role if it doesn't exist.
	 */
	public function maybe_register_role() {
		if ( ! get_role( self::ROLE ) ) {
			$this->register_role();
		}
	}

	/**
	 * Register the AI Assistant role with appropriate capabilities.
	 */
	public function register_role() {
		add_role( self::ROLE, __( 'AI Assistant', 'clawpress' ), array(
			'read'                 => true,
			'edit_posts'           => true,
			'edit_others_posts'    => true,
			'edit_published_posts' => true,
			'publish_posts'        => true,
			'edit_pages'           => true,
			'edit_others_pages'    => true,
			'edit_published_pages' => true,
			'upload_files'         => true,
		) );
	}

	/**
	 * Create an assistant user.
	 *
	 * @param string $name    Display name.
	 * @param string $avatar  Emoji avatar.
	 * @param array  $context Context metadata (vibe, goals, etc.).
	 * @return int|WP_Error User ID or error.
	 */
	public function create_assistant( $name, $avatar, $context = array() ) {
		// Check if assistant already exists.
		$existing = get_users( array( 'role' => self::ROLE, 'number' => 1 ) );
		if ( ! empty( $existing ) ) {
			return new WP_Error( 'clawpress_assistant_exists', __( 'An assistant already exists.', 'clawpress' ) );
		}

		$username = sanitize_user( strtolower( str_replace( ' ', '_', $name ) ) . '_assistant' );
		$email    = $username . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'display_name' => $name,
			'role'         => self::ROLE,
			'user_pass'    => wp_generate_password( 32 ),
		) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'clawpress_avatar_emoji', $avatar );

		$context['created'] = current_time( 'mysql' );
		update_user_meta( $user_id, 'clawpress_assistant_context', $context );

		return $user_id;
	}

	/**
	 * Get the current assistant user, if one exists.
	 *
	 * @return WP_User|null
	 */
	public static function get_assistant() {
		$users = get_users( array( 'role' => self::ROLE, 'number' => 1 ) );
		return ! empty( $users ) ? $users[0] : null;
	}
}
