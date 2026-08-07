<?php
/**
 * Admin page settings.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Admin' ) ) {
	/**
	 * Handles Ahentic admin screens and settings.
	 */
	class Ahentic_Admin {
		/**
		 * Settings page slug.
		 *
		 * @var string
		 */
		const SETTINGS_SLUG = 'ahentic';

		/**
		 * Constructor.
		 */
		public function __construct() {
			if ( is_admin() ) {
				add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
				add_action( 'admin_init', array( $this, 'register_settings' ) );
				add_filter( 'plugin_action_links_' . plugin_basename( AHENTIC_FILE ), array( $this, 'add_admin_action_links' ) );
			}
		}

		/**
		 * Add admin menu under Settings.
		 */
		public function add_admin_menu() {
			add_options_page(
				__( 'Ahentic Settings', 'ahentic' ),
				__( 'Ahentic', 'ahentic' ),
				'manage_options',
				self::SETTINGS_SLUG,
				array( $this, 'admin_page_callback' )
			);
		}

		/**
		 * Register settings (token limits).
		 */
		public function register_settings() {
			register_setting(
				'ahentic_settings',
				Ahentic_Usage::OPTION_LIMITS,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_limits_option' ),
					'default'           => Ahentic_Usage::default_limits_state(),
				)
			);
		}

		/**
		 * Sanitize limits option from the settings form or programmatic update_option.
		 *
		 * Settings form posts only daily_limit — preserve lock/temp fields from current.
		 * Programmatic saves (test helpers, boosts, unlock) pass full state including runaway_locked.
		 *
		 * @param mixed $input Raw input.
		 * @return array
		 */
		public function sanitize_limits_option( $input ) {
			$current = Ahentic_Usage::get_limits_state();
			if ( ! is_array( $input ) ) {
				return $current;
			}

			// Full state write (includes runaway / streak / temp keys).
			if ( array_key_exists( 'runaway_locked', $input ) || array_key_exists( 'temp_limit', $input ) || array_key_exists( 'streak', $input ) ) {
				return Ahentic_Usage::normalize_limits_state( $input );
			}

			$limit = isset( $input['daily_limit'] ) ? (int) $input['daily_limit'] : (int) $current['daily_limit'];
			return Ahentic_Usage::with_daily_limit( $current, $limit );
		}

		/**
		 * Add a Settings link to the plugin action links.
		 *
		 * @param string[] $links Existing action links.
		 * @return string[]
		 */
		public function add_admin_action_links( $links ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'ahentic' ) . '</a>';
			return array_merge( array( $settings_link ), $links );
		}

		/**
		 * Render a small admin-post button form.
		 *
		 * @param string $action  admin-post action.
		 * @param string $nonce   Nonce action.
		 * @param string $label   Button label.
		 * @param string $type    Button type (primary|secondary).
		 * @param array  $hidden  Extra hidden fields name => value.
		 */
		private function render_action_button( $action, $nonce, $label, $type = 'secondary', array $hidden = array() ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 8px 0;">
				<?php wp_nonce_field( $nonce ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
				<?php foreach ( $hidden as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
				<?php endforeach; ?>
				<?php submit_button( $label, $type, 'submit', false ); ?>
			</form>
			<?php
		}

		/**
		 * Render the settings page.
		 */
		public function admin_page_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( isset( $_GET['ahentic_unlocked'] ) && '1' === $_GET['ahentic_unlocked'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flash only.
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Runaway protection unlocked. Ahentic may send prompts again.', 'ahentic' ) . '</p></div>';
			}
			if ( isset( $_GET['ahentic_boosted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flash only.
				$boost = sanitize_key( wp_unslash( (string) $_GET['ahentic_boosted'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( 'temp' === $boost ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Daily limit increased by 10% for today only. Prompts may continue until that temporary cap is reached.', 'ahentic' ) . '</p></div>';
				} elseif ( 'perm' === $boost ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Daily limit permanently increased by 10%.', 'ahentic' ) . '</p></div>';
				}
			}

			$status = Ahentic_Usage::get_status();
			$used   = (int) $status['today_used'];
			$limit  = (int) $status['daily_limit'];
			$eff    = (int) $status['effective_limit'];
			$pct    = $eff > 0 ? min( 100, (int) round( ( $used / $eff ) * 100 ) ) : 0;
			$daily_blocked   = ! empty( $status['blocked'] ) && Ahentic_Usage::CODE_DAILY_LIMIT === $status['block_code'];
			$runaway_blocked = ! empty( $status['runaway_locked'] );
			?>
			<div class="wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

				<?php if ( $runaway_blocked ) : ?>
					<div class="notice notice-error" style="padding:12px;">
						<p><strong><?php esc_html_e( 'Runaway token protection is active', 'ahentic' ); ?></strong></p>
						<p>
							<?php esc_html_e( 'The daily token limit was hit on 3 consecutive days, so Ahentic paused all prompts to protect your spend. Lift the lock below to allow agents to run again. You can also raise the daily limit by 10% when unlocking.', 'ahentic' ); ?>
						</p>
						<p>
							<?php
							$this->render_action_button(
								'ahentic_unlock_runaway',
								'ahentic_unlock_runaway',
								__( 'Acknowledge & unlock', 'ahentic' ),
								'primary'
							);
							$this->render_action_button(
								'ahentic_unlock_and_boost',
								'ahentic_unlock_and_boost',
								__( 'Unlock and raise limit +10%', 'ahentic' ),
								'secondary'
							);
							?>
						</p>
					</div>
				<?php elseif ( $daily_blocked ) : ?>
					<div class="notice notice-warning" style="padding:12px;">
						<p><strong><?php esc_html_e( 'Daily token limit reached', 'ahentic' ); ?></strong></p>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: tokens used, 2: effective daily limit */
									__( 'This site has used %1$s of %2$s tokens today (site timezone). Ongoing agent runs were stopped and new prompts are blocked until you raise the limit.', 'ahentic' ),
									number_format_i18n( $used ),
									number_format_i18n( $eff )
								)
							);
							?>
						</p>
						<p>
							<?php
							$this->render_action_button(
								'ahentic_boost_limit',
								'ahentic_boost_limit',
								__( 'Increase temporarily for today (+10%)', 'ahentic' ),
								'primary',
								array( 'ahentic_boost_mode' => 'temp' )
							);
							$this->render_action_button(
								'ahentic_boost_limit',
								'ahentic_boost_limit',
								__( 'Increase permanently (+10%)', 'ahentic' ),
								'secondary',
								array( 'ahentic_boost_mode' => 'perm' )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<p><?php esc_html_e( 'An intelligent AI agent that understands your WordPress site and works alongside you to build, edit, troubleshoot, and manage it.', 'ahentic' ); ?></p>

				<form method="post" action="options.php">
					<?php settings_fields( 'ahentic_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="ahentic_daily_limit"><?php esc_html_e( 'Daily token limit', 'ahentic' ); ?></label>
							</th>
							<td>
								<input
									name="<?php echo esc_attr( Ahentic_Usage::OPTION_LIMITS ); ?>[daily_limit]"
									type="number"
									id="ahentic_daily_limit"
									value="<?php echo esc_attr( (string) $limit ); ?>"
									placeholder="<?php echo esc_attr( (string) Ahentic_Usage::DEFAULT_DAILY_LIMIT ); ?>"
									min="1"
									step="1000"
									class="regular-text"
								/>
								<p class="description">
									<?php
									esc_html_e( 'Site-wide daily limit to stop runaway agent loops from spending unexpected tokens. Raise anytime — a safety backstop, not a plan restriction.', 'ahentic' );
									echo ' ';
									if ( ! empty( $status['temp_boost'] ) && $eff !== $limit ) {
										echo esc_html(
											sprintf(
												/* translators: 1: tokens used today, 2: temporary effective limit, 3: permanent limit, 4: percent */
												__( 'Today (site timezone): %1$s / %2$s tokens (%4$d%%). Temporary boost active (permanent limit %3$s).', 'ahentic' ),
												number_format_i18n( $used ),
												number_format_i18n( $eff ),
												number_format_i18n( $limit ),
												$pct
											)
										);
									} else {
										echo esc_html(
											sprintf(
												/* translators: 1: tokens used today, 2: daily limit, 3: percent */
												__( 'Today (site timezone): %1$s / %2$s tokens (%3$d%%).', 'ahentic' ),
												number_format_i18n( $used ),
												number_format_i18n( $eff ),
												$pct
											)
										);
									}
									?>
								</p>
								<div style="max-width: 28rem; height: 8px; background: #dcdcde; border-radius: 4px; overflow: hidden;" aria-hidden="true">
									<div style="width: <?php echo esc_attr( (string) $pct ); ?>%; height: 100%; background: #5750F8;"></div>
								</div>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save changes', 'ahentic' ) ); ?>
				</form>
			</div>
			<?php
		}
	}

	/**
	 * Handle runaway unlock from Settings.
	 */
	function ahentic_handle_unlock_runaway() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- prefixed ahentic_.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'ahentic' ), 403 );
		}
		check_admin_referer( 'ahentic_unlock_runaway' );
		Ahentic_Usage::unlock_runaway();
		wp_safe_redirect(
			add_query_arg(
				'ahentic_unlocked',
				'1',
				admin_url( 'options-general.php?page=' . Ahentic_Admin::SETTINGS_SLUG )
			)
		);
		exit;
	}
	add_action( 'admin_post_ahentic_unlock_runaway', 'ahentic_handle_unlock_runaway' );

	/**
	 * Unlock runaway and permanently raise the daily limit by 10%.
	 */
	function ahentic_handle_unlock_and_boost() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- prefixed ahentic_.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'ahentic' ), 403 );
		}
		check_admin_referer( 'ahentic_unlock_and_boost' );
		Ahentic_Usage::boost_permanent_10();
		Ahentic_Usage::unlock_runaway();
		wp_safe_redirect(
			add_query_arg(
				array(
					'ahentic_unlocked' => '1',
					'ahentic_boosted'  => 'perm',
				),
				admin_url( 'options-general.php?page=' . Ahentic_Admin::SETTINGS_SLUG )
			)
		);
		exit;
	}
	add_action( 'admin_post_ahentic_unlock_and_boost', 'ahentic_handle_unlock_and_boost' );

	/**
	 * Raise daily limit (+10% temporary today or permanent).
	 */
	function ahentic_handle_boost_limit() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- prefixed ahentic_.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'ahentic' ), 403 );
		}
		check_admin_referer( 'ahentic_boost_limit' );
		$mode = isset( $_POST['ahentic_boost_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['ahentic_boost_mode'] ) ) : 'temp';
		if ( 'perm' === $mode ) {
			Ahentic_Usage::boost_permanent_10();
		} else {
			Ahentic_Usage::boost_temporary_10();
			$mode = 'temp';
		}
		wp_safe_redirect(
			add_query_arg(
				'ahentic_boosted',
				$mode,
				admin_url( 'options-general.php?page=' . Ahentic_Admin::SETTINGS_SLUG )
			)
		);
		exit;
	}
	add_action( 'admin_post_ahentic_boost_limit', 'ahentic_handle_boost_limit' );

	new Ahentic_Admin();
}
