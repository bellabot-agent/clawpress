<?php
/**
 * ClawPress Agent Handshake — pair an agent without copy-pasting credentials.
 *
 * Flow:
 * 1. Site owner clicks "Pair Agent" in wp-admin → gets a 6-character pairing code
 * 2. Owner tells the agent the code (verbally, via chat, etc.)
 * 3. Agent calls POST /wp-json/clawpress/v1/pair with the code + agent metadata
 * 4. Plugin creates an Application Password and returns credentials
 * 5. Code expires after 5 minutes or single use
 *
 * Like pairing a Bluetooth device. Simple, secure, human-in-the-loop.
 *
 * @package ClawPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClawPress_Handshake {

	const TRANSIENT_PREFIX = 'clawpress_pair_';
	const CODE_LENGTH      = 6;
	const CODE_TTL         = 300; // 5 minutes

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_ajax_clawpress_generate_code', array( $this, 'ajax_generate_code' ) );
	}

	/**
	 * Register public REST route for claiming a pairing code.
	 */
	public function register_routes() {
		// Public endpoint — no auth required (the code IS the auth)
		register_rest_route( 'clawpress/v1', '/pair', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'claim_code' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'code' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return preg_match( '/^[A-Z0-9]{' . self::CODE_LENGTH . '}$/', strtoupper( trim( $value ) ) );
					},
				),
				'agent_name' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'Agent',
				),
				'agent_id' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
			),
		) );

		// Status check — agent can verify a code is valid before claiming
		register_rest_route( 'clawpress/v1', '/pair/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'check_code' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'code' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	/**
	 * Generate a pairing code (called from wp-admin via AJAX).
	 * Admins can pass a target_user_id to generate a code for another user.
	 */
	public function ajax_generate_code() {
		check_ajax_referer( 'clawpress_pair', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		// Allow admins to generate codes for other users
		$target_user_id = get_current_user_id();
		if ( ! empty( $_POST['target_user_id'] ) && current_user_can( 'edit_users' ) ) {
			$target = get_user_by( 'id', absint( $_POST['target_user_id'] ) );
			if ( $target ) {
				$target_user_id = $target->ID;
			}
		}

		$code = $this->generate_code();

		// Store code → user mapping with TTL
		$transient_key = self::TRANSIENT_PREFIX . strtoupper( $code );
		$data = array(
			'user_id'    => $target_user_id,
			'created_at' => time(),
			'claimed'    => false,
		);

		set_transient( $transient_key, $data, self::CODE_TTL );

		$target_user = get_user_by( 'id', $target_user_id );

		wp_send_json_success( array(
			'code'       => $code,
			'expires_in' => self::CODE_TTL,
			'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + self::CODE_TTL ),
			'for_user'   => $target_user ? $target_user->user_login : '',
		) );
	}

	/**
	 * Check if a pairing code is valid (public, no auth).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function check_code( $request ) {
		$code = strtoupper( trim( $request->get_param( 'code' ) ) );
		$transient_key = self::TRANSIENT_PREFIX . $code;
		$data = get_transient( $transient_key );

		if ( ! $data ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => 'Code not found or expired.',
			), 404 );
		}

		if ( $data['claimed'] ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => 'Code already used.',
			), 410 );
		}

		return new WP_REST_Response( array(
			'valid'     => true,
			'site_name' => get_bloginfo( 'name' ),
			'site_url'  => home_url( '/' ),
		), 200 );
	}

	/**
	 * Claim a pairing code — exchange it for Application Password credentials.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function claim_code( $request ) {
		$code       = strtoupper( trim( $request->get_param( 'code' ) ) );
		$agent_name = $request->get_param( 'agent_name' ) ?: 'Agent';
		$agent_id   = $request->get_param( 'agent_id' );

		$transient_key = self::TRANSIENT_PREFIX . $code;
		$data = get_transient( $transient_key );

		// Code doesn't exist or expired
		if ( ! $data ) {
			return new WP_REST_Response( array(
				'error'   => 'invalid_code',
				'message' => 'Pairing code not found or expired. Ask the site owner for a new one.',
			), 404 );
		}

		// Code already claimed
		if ( $data['claimed'] ) {
			return new WP_REST_Response( array(
				'error'   => 'code_used',
				'message' => 'This pairing code has already been used.',
			), 410 );
		}

		// Mark as claimed immediately (prevent race conditions)
		$data['claimed']    = true;
		$data['claimed_at'] = time();
		$data['agent_name'] = $agent_name;
		$data['agent_id']   = $agent_id;
		set_transient( $transient_key, $data, 60 ); // Keep briefly for audit, then expire

		$user_id = $data['user_id'];
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_REST_Response( array(
				'error'   => 'user_not_found',
				'message' => 'The user who generated this code no longer exists.',
			), 500 );
		}

		// Create the Application Password
		$app_name = CLAWPRESS_APP_PASSWORD_NAME . ' (' . $agent_name . ')';
		$result   = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => $app_name,
				'app_id' => 'clawpress-' . sanitize_title( $agent_name ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'error'   => 'password_creation_failed',
				'message' => $result->get_error_message(),
			), 500 );
		}

		list( $password, $item ) = $result;

		// Return everything the agent needs
		return new WP_REST_Response( array(
			'success'    => true,
			'site_name'  => get_bloginfo( 'name' ),
			'site_url'   => home_url( '/' ),
			'rest_url'   => rest_url(),
			'username'   => $user->user_login,
			'password'   => WP_Application_Passwords::chunk_password( $password ),
			'manifest'   => rest_url( 'clawpress/v1/manifest' ),
			'agent_name' => $agent_name,
			'message'    => 'Connected! Save these credentials — the password won\'t be shown again.',
		), 201 );
	}

	/**
	 * Generate a random alphanumeric code (uppercase, no ambiguous chars).
	 *
	 * @return string
	 */
	private function generate_code() {
		// Exclude ambiguous characters: 0/O, 1/I/L
		$chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$code  = '';
		for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
			$code .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}
		return $code;
	}
}
