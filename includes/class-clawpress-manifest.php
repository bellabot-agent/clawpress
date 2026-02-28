<?php
/**
 * ClawPress Site Manifest — tells agents what they're working with.
 *
 * Exposes a REST endpoint at /wp-json/clawpress/v1/manifest that returns
 * a structured overview of the site: theme, plugins, content types, recent
 * content, capabilities, and agent-relevant metadata.
 *
 * @package ClawPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClawPress_Manifest {

	/**
	 * Register REST routes.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the manifest endpoint.
	 */
	public function register_routes() {
		register_rest_route( 'clawpress/v1', '/manifest', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_manifest' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );
	}

	/**
	 * Require authentication — agents must use their app password.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permissions() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'clawpress_unauthorized',
				__( 'Authentication required. Use your Application Password.', 'clawpress' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Build and return the site manifest.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_manifest( $request ) {
		$manifest = array(
			'clawpress_version' => CLAWPRESS_VERSION,
			'site'              => $this->get_site_info(),
			'theme'             => $this->get_theme_info(),
			'plugins'           => $this->get_plugin_info(),
			'content'           => $this->get_content_info(),
			'capabilities'      => $this->get_capabilities(),
			'api'               => $this->get_api_info(),
			'agent'             => $this->get_agent_info(),
		);

		return new WP_REST_Response( $manifest, 200 );
	}

	/**
	 * Basic site information.
	 */
	private function get_site_info() {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url( '/' ),
			'admin_url'   => admin_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'language'    => get_locale(),
			'timezone'    => wp_timezone_string(),
			'multisite'   => is_multisite(),
		);
	}

	/**
	 * Active theme details.
	 */
	private function get_theme_info() {
		$theme = wp_get_theme();
		return array(
			'name'         => $theme->get( 'Name' ),
			'slug'         => $theme->get_stylesheet(),
			'version'      => $theme->get( 'Version' ),
			'is_block'     => $theme->is_block_theme(),
			'parent'       => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			'supports'     => array(
				'custom_logo'       => current_theme_supports( 'custom-logo' ),
				'post_thumbnails'   => current_theme_supports( 'post-thumbnails' ),
				'widgets'           => current_theme_supports( 'widgets' ),
				'editor_styles'     => current_theme_supports( 'editor-styles' ),
				'wp_block_styles'   => current_theme_supports( 'wp-block-styles' ),
			),
		);
	}

	/**
	 * Active plugins (names and versions, not paths — security).
	 */
	private function get_plugin_info() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$result         = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$p = $all_plugins[ $plugin_file ];
				$result[] = array(
					'name'        => $p['Name'],
					'version'     => $p['Version'],
					'description' => wp_strip_all_tags( $p['Description'] ),
				);
			}
		}

		return $result;
	}

	/**
	 * Content overview — post types, counts, recent items.
	 */
	private function get_content_info() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$result     = array();

		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt->name );
			$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$draft     = isset( $counts->draft ) ? (int) $counts->draft : 0;

			$recent = get_posts( array(
				'post_type'      => $pt->name,
				'posts_per_page' => 5,
				'post_status'    => 'any',
				'orderby'        => 'date',
				'order'          => 'DESC',
			) );

			$recent_items = array();
			foreach ( $recent as $post ) {
				$recent_items[] = array(
					'id'     => $post->ID,
					'title'  => $post->post_title,
					'slug'   => $post->post_name,
					'status' => $post->post_status,
					'date'   => $post->post_date,
					'url'    => get_permalink( $post ),
				);
			}

			$result[ $pt->name ] = array(
				'label'     => $pt->label,
				'published' => $published,
				'draft'     => $draft,
				'rest_base' => $pt->rest_base ?: $pt->name,
				'supports'  => array_keys( array_filter( get_all_post_type_supports( $pt->name ) ) ),
				'recent'    => $recent_items,
			);
		}

		// Taxonomies
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$tax_info   = array();
		foreach ( $taxonomies as $tax ) {
			$tax_info[ $tax->name ] = array(
				'label'     => $tax->label,
				'rest_base' => $tax->rest_base ?: $tax->name,
				'count'     => (int) wp_count_terms( array( 'taxonomy' => $tax->name ) ),
			);
		}

		return array(
			'post_types' => $result,
			'taxonomies' => $tax_info,
		);
	}

	/**
	 * What the authenticated user can do.
	 */
	private function get_capabilities() {
		$user = wp_get_current_user();
		$caps = array(
			'role'              => implode( ', ', $user->roles ),
			'can_publish_posts' => current_user_can( 'publish_posts' ),
			'can_publish_pages' => current_user_can( 'publish_pages' ),
			'can_upload_files'  => current_user_can( 'upload_files' ),
			'can_edit_others'   => current_user_can( 'edit_others_posts' ),
			'can_manage_options'=> current_user_can( 'manage_options' ),
			'can_edit_theme'    => current_user_can( 'edit_theme_options' ),
			'can_install_plugins' => current_user_can( 'install_plugins' ),
			'can_manage_comments' => current_user_can( 'moderate_comments' ),
		);

		return $caps;
	}

	/**
	 * Available REST API routes — the agent's toolkit.
	 */
	private function get_api_info() {
		$info = array(
			'base_url'   => rest_url(),
			'namespaces' => array( 'wp/v2', 'clawpress/v1' ),
			'endpoints'  => array(
				'posts'      => rest_url( 'wp/v2/posts' ),
				'pages'      => rest_url( 'wp/v2/pages' ),
				'media'      => rest_url( 'wp/v2/media' ),
				'comments'   => rest_url( 'wp/v2/comments' ),
				'categories' => rest_url( 'wp/v2/categories' ),
				'tags'       => rest_url( 'wp/v2/tags' ),
				'settings'   => rest_url( 'wp/v2/settings' ),
				'manifest'   => rest_url( 'clawpress/v1/manifest' ),
			),
		);

		// Check for WooCommerce
		if ( class_exists( 'WooCommerce' ) ) {
			$info['woocommerce'] = array(
				'version'    => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
				'products'   => rest_url( 'wc/v3/products' ),
				'orders'     => rest_url( 'wc/v3/orders' ),
				'customers'  => rest_url( 'wc/v3/customers' ),
			);
		}

		return $info;
	}

	/**
	 * Agent-specific metadata from ClawPress tracker.
	 */
	private function get_agent_info() {
		$user  = wp_get_current_user();
		$stats = ClawPress_Tracker::get_stats( $user->ID );

		return array(
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'posts_created'=> $stats['post_count'],
			'media_created'=> $stats['media_count'],
			'recent_work'  => array_map( function( $p ) {
				return array(
					'id'     => $p->ID,
					'title'  => $p->post_title,
					'type'   => $p->post_type,
					'status' => $p->post_status,
					'date'   => $p->post_date,
				);
			}, $stats['recent_posts'] ),
		);
	}
}
