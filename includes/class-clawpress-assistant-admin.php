<?php
/**
 * ClawPress Assistant Admin — setup wizard, chat interface, profile integration, AJAX.
 *
 * @package ClawPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClawPress_Assistant_Admin {

	/**
	 * @var ClawPress_Assistant
	 */
	private $assistant;

	/**
	 * @param ClawPress_Assistant $assistant
	 */
	public function __construct( ClawPress_Assistant $assistant ) {
		$this->assistant = $assistant;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'users_page_notice' ) );
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_chat_assets' ) );
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );
		add_filter( 'get_avatar', array( $this, 'emoji_avatar' ), 10, 2 );

		// AJAX handlers.
		add_action( 'wp_ajax_clawpress_create_assistant', array( $this, 'ajax_create_assistant' ) );
		add_action( 'wp_ajax_clawpress_assistant_mock_action', array( $this, 'ajax_mock_action' ) );
	}

	/**
	 * Show "Create Your Assistant" notice on Users page.
	 */
	public function users_page_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'users' !== $screen->id ) {
			return;
		}
		if ( ClawPress_Assistant::get_assistant() ) {
			return;
		}
		?>
		<div class="notice notice-info" style="display:flex;align-items:center;gap:12px;padding:12px 16px;">
			<span class="dashicons dashicons-superhero" style="font-size:24px;color:#2271b1;"></span>
			<div style="flex:1;">
				<strong><?php esc_html_e( 'Want a hand around here?', 'clawpress' ); ?></strong>
				<p style="margin:2px 0 0;"><?php esc_html_e( 'Create an AI assistant who knows your site and can help you manage it.', 'clawpress' ); ?></p>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clawpress-assistant-setup' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Create Your Assistant', 'clawpress' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Register admin pages.
	 */
	public function register_pages() {
		// Hidden setup wizard page.
		add_submenu_page(
			null,
			__( 'Create Your Assistant', 'clawpress' ),
			__( 'Create Your Assistant', 'clawpress' ),
			'manage_options',
			'clawpress-assistant-setup',
			array( $this, 'render_setup_page' )
		);

		// Chat page under Users (only if assistant exists).
		$assistant = ClawPress_Assistant::get_assistant();
		if ( $assistant ) {
			add_users_page(
				$assistant->display_name,
				'💬 ' . esc_html( $assistant->display_name ),
				'manage_options',
				'clawpress-assistant-chat',
				array( $this, 'render_chat_page' )
			);
		}
	}

	/**
	 * Enqueue chat UI assets on the chat page.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_chat_assets( $hook_suffix ) {
		if ( 'users_page_clawpress-assistant-chat' !== $hook_suffix ) {
			return;
		}

		$assistant = ClawPress_Assistant::get_assistant();
		if ( ! $assistant ) {
			return;
		}

		$meta   = get_user_meta( $assistant->ID, 'clawpress_assistant_context', true );
		$avatar = get_user_meta( $assistant->ID, 'clawpress_avatar_emoji', true ) ?: '🤖';

		wp_enqueue_script(
			'clawpress-assistant-chat',
			CLAWPRESS_PLUGIN_URL . 'assets/js/assistant-chat.js',
			array(),
			CLAWPRESS_VERSION,
			true
		);

		wp_enqueue_style(
			'clawpress-assistant-chat',
			CLAWPRESS_PLUGIN_URL . 'assets/css/assistant-chat.css',
			array(),
			CLAWPRESS_VERSION
		);

		wp_localize_script( 'clawpress-assistant-chat', 'ClawPressChat', array(
			'name'      => $assistant->display_name,
			'avatar'    => $avatar,
			'context'   => $meta ?: array(),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'clawpress_assistant_chat' ),
			'userId'    => $assistant->ID,
			'siteTitle' => get_bloginfo( 'name' ),
		) );
	}

	/**
	 * Render the setup wizard.
	 */
	public function render_setup_page() {
		$site_title    = get_bloginfo( 'name' );
		$site_tagline  = get_bloginfo( 'description' );
		$about_page    = get_page_by_path( 'about' );
		$about_content = $about_page ? wp_strip_all_tags( $about_page->post_content ) : '';
		$about_content = mb_substr( $about_content, 0, 500 );

		$avatars = array(
			'🤖', '🧠', '✨', '🦊', '🐙', '🎯', '🌟', '🔮',
			'🦉', '🐝', '🎨', '🚀', '💡', '🌿', '🐱', '🦄',
		);
		?>
		<div class="wrap">
			<div id="clawpress-assistant-setup" style="max-width:520px;margin:40px auto;">
				<!-- Step 1: Name -->
				<div class="cp-step" id="cp-step-1">
					<h2 style="font-size:1.5em;"><?php esc_html_e( 'What do you want to call them?', 'clawpress' ); ?></h2>
					<p class="description"><?php esc_html_e( "Pick a name for your assistant. They'll show up as a real user on your site.", 'clawpress' ); ?></p>
					<input type="text" id="cp-name" class="regular-text" placeholder="e.g. Scout, Sage, Buddy..." style="font-size:1.1em;padding:8px 12px;margin:16px 0;" autofocus />
					<br/>
					<button class="button button-primary button-hero" id="cp-next-1" disabled><?php esc_html_e( 'Next →', 'clawpress' ); ?></button>
				</div>

				<!-- Step 2: Avatar -->
				<div class="cp-step" id="cp-step-2" style="display:none;">
					<h2 style="font-size:1.5em;"><span id="cp-name-display"></span><?php esc_html_e( ' needs a face', 'clawpress' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Pick an avatar for your assistant.', 'clawpress' ); ?></p>
					<div id="cp-avatars" style="display:grid;grid-template-columns:repeat(8,1fr);gap:8px;margin:16px 0;">
						<?php foreach ( $avatars as $i => $emoji ) : ?>
							<button class="cp-avatar-btn button" data-avatar="<?php echo esc_attr( $emoji ); ?>" style="font-size:28px;padding:8px;line-height:1;<?php echo 0 === $i ? 'box-shadow:0 0 0 2px #2271b1;' : ''; ?>">
								<?php echo $emoji; ?>
							</button>
						<?php endforeach; ?>
					</div>
					<button class="button button-primary button-hero" id="cp-next-2"><?php esc_html_e( 'Next →', 'clawpress' ); ?></button>
				</div>

				<!-- Step 3: Context -->
				<div class="cp-step" id="cp-step-3" style="display:none;">
					<h2 style="font-size:1.5em;"><?php esc_html_e( 'What should ', 'clawpress' ); ?><span id="cp-name-display-2"></span><?php esc_html_e( ' know about you?', 'clawpress' ); ?></h2>

					<?php if ( $site_title || $site_tagline ) : ?>
					<div style="background:#f0f0f1;padding:12px 16px;border-radius:4px;margin:12px 0;">
						<strong><?php esc_html_e( 'From your site:', 'clawpress' ); ?></strong><br/>
						<?php if ( $site_title ) : ?>
							📌 <?php echo esc_html( $site_title ); ?><br/>
						<?php endif; ?>
						<?php if ( $site_tagline ) : ?>
							📝 <?php echo esc_html( $site_tagline ); ?><br/>
						<?php endif; ?>
						<?php if ( $about_content ) : ?>
							📄 <?php echo esc_html( mb_substr( $about_content, 0, 120 ) ); ?>…
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<label for="cp-vibe" style="display:block;margin-top:16px;font-weight:600;"><?php esc_html_e( "What's the vibe?", 'clawpress' ); ?></label>
					<select id="cp-vibe" style="width:100%;margin:6px 0 12px;">
						<option value="casual"><?php esc_html_e( 'Casual & friendly', 'clawpress' ); ?></option>
						<option value="professional"><?php esc_html_e( 'Professional & polished', 'clawpress' ); ?></option>
						<option value="playful"><?php esc_html_e( 'Playful & creative', 'clawpress' ); ?></option>
						<option value="minimal"><?php esc_html_e( 'Minimal & efficient', 'clawpress' ); ?></option>
					</select>

					<label for="cp-goals" style="display:block;font-weight:600;"><?php esc_html_e( 'What do you want help with?', 'clawpress' ); ?></label>
					<textarea id="cp-goals" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Writing blog posts, keeping the site updated, SEO...', 'clawpress' ); ?>" style="margin:6px 0 12px;"></textarea>

					<label for="cp-extra" style="display:block;font-weight:600;"><?php esc_html_e( 'Anything else?', 'clawpress' ); ?></label>
					<textarea id="cp-extra" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Favorite topics, things to avoid, whatever you want...', 'clawpress' ); ?>" style="margin:6px 0 16px;"></textarea>

					<button class="button button-primary button-hero" id="cp-create">
						<span class="dashicons dashicons-superhero" style="margin-top:4px;"></span>
						<?php esc_html_e( ' Bring them to life', 'clawpress' ); ?>
					</button>
				</div>

				<!-- Step 4: Done -->
				<div class="cp-step" id="cp-step-4" style="display:none;text-align:center;">
					<div id="cp-done-avatar" style="font-size:64px;margin:20px 0;"></div>
					<div id="cp-done-message" style="font-size:1.2em;margin:20px 0;"></div>
					<a id="cp-done-chat" href="#" class="button button-primary button-hero"><?php esc_html_e( "Let's hear it →", 'clawpress' ); ?></a>
				</div>
			</div>
		</div>

		<script>
		jQuery(function($) {
			var name = '', avatar = '🤖';

			$('#cp-name').on('input', function() {
				name = $(this).val().trim();
				$('#cp-next-1').prop('disabled', !name);
			});

			$('#cp-next-1').on('click', function() {
				$('#cp-step-1').hide();
				$('#cp-name-display, #cp-name-display-2').text(name);
				$('#cp-step-2').fadeIn(200);
			});

			$('.cp-avatar-btn').on('click', function() {
				$('.cp-avatar-btn').css('box-shadow', 'none');
				$(this).css('box-shadow', '0 0 0 2px #2271b1');
				avatar = $(this).data('avatar');
			});

			$('#cp-next-2').on('click', function() {
				$('#cp-step-2').hide();
				$('#cp-step-3').fadeIn(200);
			});

			$('#cp-create').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('Creating...');

				$.post(ajaxurl, {
					action: 'clawpress_create_assistant',
					_nonce: '<?php echo wp_create_nonce( 'clawpress_create_assistant' ); ?>',
					name: name,
					avatar: avatar,
					vibe: $('#cp-vibe').val(),
					goals: $('#cp-goals').val(),
					extra: $('#cp-extra').val(),
					site_title: <?php echo wp_json_encode( $site_title ); ?>,
					site_tagline: <?php echo wp_json_encode( $site_tagline ); ?>,
					about_content: <?php echo wp_json_encode( $about_content ); ?>
				}, function(resp) {
					if (resp.success) {
						$('#cp-step-3').hide();
						$('#cp-done-avatar').text(avatar);
						$('#cp-done-message').html(
							'<strong>' + $('<span>').text(name).html() + '</strong> noticed a few things about your site.<br/>Want to hear?'
						);
						$('#cp-done-chat').attr('href', resp.data.chat_url);
						$('#cp-step-4').fadeIn(200);
					} else {
						alert(resp.data || 'Something went wrong.');
						$btn.prop('disabled', false).text('Bring them to life');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render the chat page.
	 */
	public function render_chat_page() {
		$assistant = ClawPress_Assistant::get_assistant();
		if ( ! $assistant ) {
			echo '<div class="wrap"><p>' . esc_html__( 'No assistant found.', 'clawpress' ) . '</p></div>';
			return;
		}

		$avatar = get_user_meta( $assistant->ID, 'clawpress_avatar_emoji', true ) ?: '🤖';
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:8px;">
				<span style="font-size:1.2em;"><?php echo esc_html( $avatar ); ?></span>
				<?php echo esc_html( $assistant->display_name ); ?>
			</h1>
			<div id="clawpress-assistant-chat-root" style="margin-top:16px;max-width:720px;border:1px solid #c3c4c7;border-radius:4px;height:600px;background:#fff;"></div>
		</div>
		<?php
	}

	/**
	 * Render assistant info on profile page.
	 *
	 * @param WP_User $user
	 */
	public function render_profile_section( $user ) {
		if ( ! in_array( 'ai_assistant', $user->roles, true ) ) {
			return;
		}

		$meta   = get_user_meta( $user->ID, 'clawpress_assistant_context', true );
		$avatar = get_user_meta( $user->ID, 'clawpress_avatar_emoji', true ) ?: '🤖';
		?>
		<h2><?php echo esc_html( $avatar ); ?> <?php esc_html_e( 'Assistant Profile', 'clawpress' ); ?></h2>
		<table class="form-table">
			<?php if ( ! empty( $meta['vibe'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Vibe', 'clawpress' ); ?></th>
				<td><?php echo esc_html( ucfirst( $meta['vibe'] ) ); ?></td>
			</tr>
			<?php endif; ?>
			<?php if ( ! empty( $meta['site_title'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Knows about', 'clawpress' ); ?></th>
				<td>
					<?php echo esc_html( $meta['site_title'] ); ?>
					<?php if ( ! empty( $meta['site_tagline'] ) ) : ?>
						— <?php echo esc_html( $meta['site_tagline'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( ! empty( $meta['goals'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Helping with', 'clawpress' ); ?></th>
				<td><?php echo esc_html( $meta['goals'] ); ?></td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Replace gravatar with emoji for assistant users.
	 *
	 * @param string $avatar
	 * @param mixed  $id_or_email
	 * @return string
	 */
	public function emoji_avatar( $avatar, $id_or_email ) {
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user ) {
				$user_id = $user->ID;
			}
		}

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && in_array( 'ai_assistant', $user->roles, true ) ) {
				$emoji = get_user_meta( $user_id, 'clawpress_avatar_emoji', true ) ?: '🤖';
				return '<span class="clawpress-emoji-avatar" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;font-size:24px;border-radius:50%;background:#f0f0f1;">' . esc_html( $emoji ) . '</span>';
			}
		}
		return $avatar;
	}

	/**
	 * AJAX: Create assistant user.
	 */
	public function ajax_create_assistant() {
		check_ajax_referer( 'clawpress_create_assistant', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.' );
		}

		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$avatar = sanitize_text_field( wp_unslash( $_POST['avatar'] ?? '🤖' ) );

		if ( empty( $name ) ) {
			wp_send_json_error( 'Name is required.' );
		}

		$context = array(
			'vibe'          => sanitize_text_field( wp_unslash( $_POST['vibe'] ?? '' ) ),
			'goals'         => sanitize_textarea_field( wp_unslash( $_POST['goals'] ?? '' ) ),
			'extra'         => sanitize_textarea_field( wp_unslash( $_POST['extra'] ?? '' ) ),
			'site_title'    => sanitize_text_field( wp_unslash( $_POST['site_title'] ?? '' ) ),
			'site_tagline'  => sanitize_text_field( wp_unslash( $_POST['site_tagline'] ?? '' ) ),
			'about_content' => sanitize_textarea_field( wp_unslash( $_POST['about_content'] ?? '' ) ),
		);

		$user_id = $this->assistant->create_assistant( $name, $avatar, $context );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( $user_id->get_error_message() );
		}

		wp_send_json_success( array(
			'user_id'  => $user_id,
			'chat_url' => admin_url( 'users.php?page=clawpress-assistant-chat' ),
		) );
	}

	/**
	 * AJAX: Log a mock action from the chat interface.
	 */
	public function ajax_mock_action() {
		check_ajax_referer( 'clawpress_assistant_chat', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.' );
		}

		// Just acknowledge — we're not tracking activity (wpcom has its own).
		wp_send_json_success();
	}
}
