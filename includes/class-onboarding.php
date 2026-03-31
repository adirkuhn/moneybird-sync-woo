<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

/**
 * Five-step onboarding wizard.
 *
 * Step 1 – Connect via OAuth
 * Step 2 – Select Moneybird administration
 * Step 3 – Select Stripe Clearing Account, Bank Account, Fees ledger
 * Step 4 – Test connection
 * Step 5 – Enable sync
 *
 * Progress is derived from actual stored state, not a separate counter, so the
 * wizard is always consistent even if settings are changed externally.
 */
class Onboarding {
	public const PAGE_SLUG = 'mb-onboarding';

	private OAuthClient $oauth;
	private MoneybirdClient $client;

	public function __construct( OAuthClient $oauth, MoneybirdClient $client ) {
		$this->oauth  = $oauth;
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_requests' ) );
	}

	// ── Request handling (runs on admin_init, before any output) ────────────

	public function handle_requests(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = isset( $_GET['mbsfw_oauth'] ) ? 'oauth_' . sanitize_key( (string) $_GET['mbsfw_oauth'] ) : '';

		if ( 'oauth_callback' === $action ) {
			$this->handle_oauth_callback();
			return;
		}

		if ( 'oauth_disconnect' === $action ) {
			check_admin_referer( 'mbsfw_disconnect' );
			$this->oauth->disconnect();
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=1&disconnected=1' ) );
			exit;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		check_admin_referer( 'mbsfw_onboarding' );
		$step = isset( $_POST['mbsfw_step'] ) ? (int) $_POST['mbsfw_step'] : 0;

		match ( $step ) {
			2       => $this->handle_step2_save(),
			3       => $this->handle_step3_save(),
			4       => $this->handle_step4_test(),
			5       => $this->handle_step5_enable(),
			default => null,
		};
	}

	private function handle_oauth_callback(): void {
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( (string) $_GET['code'] ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( (string) $_GET['state'] ) : '';

		if ( ! $code ) {
			$error = isset( $_GET['error_description'] ) ? sanitize_text_field( (string) $_GET['error_description'] ) : 'Authorization cancelled.';
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=1&oauth_error=' . rawurlencode( $error ) ) );
			exit;
		}

		try {
			$this->oauth->handle_callback( $code, $state );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=2&connected=1' ) );
		} catch ( \RuntimeException $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=1&oauth_error=' . rawurlencode( $e->getMessage() ) ) );
		}
		exit;
	}

	private function handle_step2_save(): void {
		$admin_id = isset( $_POST['administration_id'] ) ? sanitize_text_field( (string) $_POST['administration_id'] ) : '';
		if ( ! $admin_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=2&error=no_admin' ) );
			exit;
		}
		$this->oauth->save_administration_id( $admin_id );
		// Clear previously saved accounts when administration changes.
		$settings = AdminUI::get_settings();
		$settings['clearing_account_id']    = '';
		$settings['bank_account_id']        = '';
		$settings['fees_ledger_account_id'] = '';
		update_option( AdminUI::OPTION_KEY, $settings );
		delete_option( 'mbsfw_connection_tested' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=3' ) );
		exit;
	}

	private function handle_step3_save(): void {
		$settings                           = AdminUI::get_settings();
		$settings['clearing_account_id']    = sanitize_text_field( (string) ( $_POST['clearing_account_id'] ?? '' ) );
		$settings['bank_account_id']        = sanitize_text_field( (string) ( $_POST['bank_account_id'] ?? '' ) );
		$settings['fees_ledger_account_id'] = sanitize_text_field( (string) ( $_POST['fees_ledger_account_id'] ?? '' ) );
		update_option( AdminUI::OPTION_KEY, $settings );
		delete_option( 'mbsfw_connection_tested' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=4' ) );
		exit;
	}

	private function handle_step4_test(): void {
		try {
			$this->client->test_connection();
			update_option( 'mbsfw_connection_tested', '1', false );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=4&test=ok' ) );
		} catch ( \RuntimeException $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=4&test=fail&error=' . rawurlencode( $e->getMessage() ) ) );
		}
		exit;
	}

	private function handle_step5_enable(): void {
		$settings                 = AdminUI::get_settings();
		$settings['sync_enabled'] = '1';
		update_option( AdminUI::OPTION_KEY, $settings );
		wp_safe_redirect( admin_url( 'admin.php?page=mb-dashboard&onboarding_complete=1' ) );
		exit;
	}

	// ── Step progress ────────────────────────────────────────────────────────

	private function get_max_accessible_step(): int {
		if ( ! $this->oauth->is_connected() ) {
			return 1;
		}
		if ( '' === $this->oauth->get_selected_administration_id() ) {
			return 2;
		}
		$s = AdminUI::get_settings();
		if ( empty( $s['clearing_account_id'] ) || empty( $s['bank_account_id'] ) ) {
			return 3;
		}
		if ( ! get_option( 'mbsfw_connection_tested' ) ) {
			return 4;
		}
		return 5;
	}

	private function get_current_step(): int {
		$requested = isset( $_GET['step'] ) ? (int) $_GET['step'] : 0;
		$max       = $this->get_max_accessible_step();
		if ( $requested >= 1 && $requested <= $max ) {
			return $requested;
		}
		return $max;
	}

	// ── Render ───────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'moneybird-sync-for-woo' ) );
		}

		$step = $this->get_current_step();
		$max  = $this->get_max_accessible_step();
		?>
		<div class="wrap mbsfw-wrap">
			<h1><?php esc_html_e( 'Moneybird Sync — Setup', 'moneybird-sync-for-woo' ); ?></h1>

			<?php $this->render_step_bar( $step, $max ); ?>

			<div class="mbsfw-wizard-card">
				<?php
				match ( $step ) {
					1       => $this->render_step1(),
					2       => $this->render_step2(),
					3       => $this->render_step3(),
					4       => $this->render_step4(),
					5       => $this->render_step5(),
					default => $this->render_step1(),
				};
				?>
			</div>
		</div>
		<?php
	}

	private function render_step_bar( int $current, int $max_accessible ): void {
		$labels = array(
			1 => __( 'Connect', 'moneybird-sync-for-woo' ),
			2 => __( 'Administration', 'moneybird-sync-for-woo' ),
			3 => __( 'Accounts', 'moneybird-sync-for-woo' ),
			4 => __( 'Test', 'moneybird-sync-for-woo' ),
			5 => __( 'Enable', 'moneybird-sync-for-woo' ),
		);
		echo '<div class="mbsfw-step-bar">';
		foreach ( $labels as $n => $label ) {
			$class = 'mbsfw-step';
			if ( $n < $current ) {
				$class .= ' mbsfw-step--done';
			} elseif ( $n === $current ) {
				$class .= ' mbsfw-step--active';
			}
			$accessible = $n <= $max_accessible;
			if ( $accessible && $n !== $current ) {
				$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . $n );
				printf(
					'<a href="%s" class="%s"><span class="mbsfw-step__num">%d</span><span class="mbsfw-step__label">%s</span></a>',
					esc_url( $url ),
					esc_attr( $class ),
					intval( $n ),
					esc_html( $label )
				);
			} else {
				printf(
					'<span class="%s"><span class="mbsfw-step__num">%d</span><span class="mbsfw-step__label">%s</span></span>',
					esc_attr( $class ),
					intval( $n ),
					esc_html( $label )
				);
			}
			if ( $n < 5 ) {
				echo '<span class="mbsfw-step__connector"></span>';
			}
		}
		echo '</div>';
	}

	// ── Step 1: Connect ──────────────────────────────────────────────────────

	private function render_step1(): void {
		if ( isset( $_GET['oauth_error'] ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html( sanitize_text_field( (string) $_GET['oauth_error'] ) )
			);
		}
		if ( isset( $_GET['disconnected'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Disconnected from Moneybird.', 'moneybird-sync-for-woo' ) . '</p></div>';
		}

		if ( $this->oauth->is_connected() ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( '✓ Connected to Moneybird.', 'moneybird-sync-for-woo' ) . '</p></div>';
			$disconnect_url = wp_nonce_url(
				admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&mbsfw_oauth=disconnect' ),
				'mbsfw_disconnect'
			);
			printf(
				'<p><a href="%s" class="button">%s</a></p>',
				esc_url( $disconnect_url ),
				esc_html__( 'Disconnect', 'moneybird-sync-for-woo' )
			);
			$this->render_next_button( 2 );
			return;
		}

		?>
		<h2><?php esc_html_e( 'Step 1: Connect to Moneybird', 'moneybird-sync-for-woo' ); ?></h2>
		<p><?php esc_html_e( 'Click the button below to authorize this plugin to access your Moneybird account.', 'moneybird-sync-for-woo' ); ?></p>

		<?php if ( ! $this->oauth_credentials_configured() ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: 1: settings page URL */
						wp_kses(
							__( 'OAuth credentials are not configured. Please add your <strong>Client ID</strong> and <strong>Client Secret</strong> on the <a href="%s">Settings page</a> first, or define them as constants in <code>wp-config.php</code>.', 'moneybird-sync-for-woo' ),
							array( 'strong' => array(), 'a' => array( 'href' => array() ), 'code' => array() )
						),
						esc_url( admin_url( 'admin.php?page=mb-settings' ) )
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<a href="<?php echo esc_url( $this->oauth->get_authorize_url() ); ?>" class="button button-primary button-hero">
				<?php esc_html_e( '🔌 Connect to Moneybird', 'moneybird-sync-for-woo' ); ?>
			</a>
		<?php endif; ?>
		<?php
	}

	// ── Step 2: Select administration ────────────────────────────────────────

	private function render_step2(): void {
		echo '<h2>' . esc_html__( 'Step 2: Select Administration', 'moneybird-sync-for-woo' ) . '</h2>';

		try {
			$administrations = $this->client->get_administrations();
		} catch ( \RuntimeException $e ) {
			printf(
				'<div class="notice notice-error inline"><p>%s %s</p></div>',
				esc_html__( 'Could not load administrations:', 'moneybird-sync-for-woo' ),
				esc_html( $e->getMessage() )
			);
			return;
		}

		$current = $this->oauth->get_selected_administration_id();
		?>
		<form method="post">
			<?php wp_nonce_field( 'mbsfw_onboarding' ); ?>
			<input type="hidden" name="mbsfw_step" value="2" />

			<table class="form-table">
				<tr>
					<th scope="row"><label for="administration_id"><?php esc_html_e( 'Administration', 'moneybird-sync-for-woo' ); ?></label></th>
					<td>
						<select name="administration_id" id="administration_id" required>
							<option value=""><?php esc_html_e( '— Select an administration —', 'moneybird-sync-for-woo' ); ?></option>
							<?php foreach ( $administrations as $admin ) : ?>
								<option value="<?php echo esc_attr( (string) $admin['id'] ); ?>"
									<?php selected( $current, (string) $admin['id'] ); ?>>
									<?php echo esc_html( (string) $admin['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save & Continue', 'moneybird-sync-for-woo' ); ?></button>
			</p>
		</form>
		<?php
	}

	// ── Step 3: Select accounts ──────────────────────────────────────────────

	private function render_step3(): void {
		echo '<h2>' . esc_html__( 'Step 3: Select Accounts', 'moneybird-sync-for-woo' ) . '</h2>';

		try {
			$financial_accounts = $this->client->get_financial_accounts();
			$ledger_accounts    = $this->client->get_ledger_accounts();
		} catch ( \RuntimeException $e ) {
			printf(
				'<div class="notice notice-error inline"><p>%s %s</p></div>',
				esc_html__( 'Could not load accounts:', 'moneybird-sync-for-woo' ),
				esc_html( $e->getMessage() )
			);
			return;
		}

		$settings = AdminUI::get_settings();
		?>
		<p><?php esc_html_e( 'Map your Moneybird financial accounts to their roles in the Stripe clearing model.', 'moneybird-sync-for-woo' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'mbsfw_onboarding' ); ?>
			<input type="hidden" name="mbsfw_step" value="3" />

			<table class="form-table">
				<tr>
					<th scope="row"><label for="clearing_account_id"><?php esc_html_e( 'Stripe Clearing Account', 'moneybird-sync-for-woo' ); ?> <span class="required">*</span></label></th>
					<td>
						<?php $this->render_account_select( 'clearing_account_id', $financial_accounts, (string) ( $settings['clearing_account_id'] ?? '' ) ); ?>
						<p class="description"><?php esc_html_e( 'All Stripe payments land here. Never the bank.', 'moneybird-sync-for-woo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bank_account_id"><?php esc_html_e( 'Bank Account', 'moneybird-sync-for-woo' ); ?> <span class="required">*</span></label></th>
					<td>
						<?php $this->render_account_select( 'bank_account_id', $financial_accounts, (string) ( $settings['bank_account_id'] ?? '' ) ); ?>
						<p class="description"><?php esc_html_e( 'Your actual bank. Stripe payouts are transferred here.', 'moneybird-sync-for-woo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fees_ledger_account_id"><?php esc_html_e( 'Stripe Fees Ledger Account', 'moneybird-sync-for-woo' ); ?></label></th>
					<td>
						<?php $this->render_ledger_select( 'fees_ledger_account_id', $ledger_accounts, (string) ( $settings['fees_ledger_account_id'] ?? '' ) ); ?>
						<p class="description"><?php esc_html_e( 'Expense account debited for Stripe transaction fees.', 'moneybird-sync-for-woo' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save & Continue', 'moneybird-sync-for-woo' ); ?></button>
			</p>
		</form>
		<?php
	}

	// ── Step 4: Test connection ──────────────────────────────────────────────

	private function render_step4(): void {
		echo '<h2>' . esc_html__( 'Step 4: Test Connection', 'moneybird-sync-for-woo' ) . '</h2>';

		if ( isset( $_GET['test'] ) ) {
			if ( 'ok' === $_GET['test'] ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( '✓ Connection successful! Moneybird responded correctly.', 'moneybird-sync-for-woo' ) . '</p></div>';
				$this->render_next_button( 5 );
				return;
			}
			$err = isset( $_GET['error'] ) ? sanitize_text_field( (string) $_GET['error'] ) : 'Unknown error.';
			printf(
				'<div class="notice notice-error inline"><p>%s %s</p></div>',
				esc_html__( 'Connection test failed:', 'moneybird-sync-for-woo' ),
				esc_html( $err )
			);
		}
		?>
		<p><?php esc_html_e( 'Click the button to verify your credentials and selected accounts are valid.', 'moneybird-sync-for-woo' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'mbsfw_onboarding' ); ?>
			<input type="hidden" name="mbsfw_step" value="4" />
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Test Connection', 'moneybird-sync-for-woo' ); ?></button>
			</p>
		</form>
		<?php
	}

	// ── Step 5: Enable sync ──────────────────────────────────────────────────

	private function render_step5(): void {
		$settings = AdminUI::get_settings();
		$enabled  = ! empty( $settings['sync_enabled'] );
		echo '<h2>' . esc_html__( 'Step 5: Enable Sync', 'moneybird-sync-for-woo' ) . '</h2>';
		?>
		<p><?php esc_html_e( 'Everything looks good! Enable the sync to start processing new WooCommerce Stripe orders into Moneybird.', 'moneybird-sync-for-woo' ); ?></p>

		<div class="mbsfw-summary-box">
			<?php $this->render_config_summary(); ?>
		</div>

		<?php if ( $enabled ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( '✓ Sync is already enabled. New orders will be queued automatically.', 'moneybird-sync-for-woo' ); ?></p>
			</div>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mb-dashboard' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Go to Dashboard →', 'moneybird-sync-for-woo' ); ?>
			</a></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'mbsfw_onboarding' ); ?>
				<input type="hidden" name="mbsfw_step" value="5" />
				<p class="submit">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( '✓ Enable Sync', 'moneybird-sync-for-woo' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
		<?php
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private function render_next_button( int $next_step ): void {
		printf(
			'<p><a href="%s" class="button button-primary">%s →</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . $next_step ) ),
			esc_html__( 'Continue', 'moneybird-sync-for-woo' )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $accounts
	 */
	private function render_account_select( string $name, array $accounts, string $selected ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" required>';
		echo '<option value="">' . esc_html__( '— Select account —', 'moneybird-sync-for-woo' ) . '</option>';
		foreach ( $accounts as $account ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $account['id'] ),
				selected( $selected, (string) $account['id'], false ),
				esc_html( (string) $account['name'] )
			);
		}
		echo '</select>';
	}

	/**
	 * @param array<int, array<string, mixed>> $ledgers
	 */
	private function render_ledger_select( string $name, array $ledgers, string $selected ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( '— Select ledger (optional) —', 'moneybird-sync-for-woo' ) . '</option>';
		foreach ( $ledgers as $ledger ) {
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr( (string) $ledger['id'] ),
				selected( $selected, (string) $ledger['id'], false ),
				esc_html( (string) $ledger['name'] ),
				esc_html( (string) ( $ledger['account_type'] ?? '' ) )
			);
		}
		echo '</select>';
	}

	private function render_config_summary(): void {
		$settings = AdminUI::get_settings();
		echo '<ul>';
		printf( '<li>✓ %s</li>', esc_html__( 'Moneybird connected', 'moneybird-sync-for-woo' ) );
		printf( '<li>✓ %s: %s</li>', esc_html__( 'Administration ID', 'moneybird-sync-for-woo' ), esc_html( $this->oauth->get_selected_administration_id() ) );
		printf( '<li>✓ %s: %s</li>', esc_html__( 'Stripe Clearing Account', 'moneybird-sync-for-woo' ), esc_html( (string) ( $settings['clearing_account_id'] ?? '—' ) ) );
		printf( '<li>✓ %s: %s</li>', esc_html__( 'Bank Account', 'moneybird-sync-for-woo' ), esc_html( (string) ( $settings['bank_account_id'] ?? '—' ) ) );
		echo '</ul>';
	}

	private function oauth_credentials_configured(): bool {
		if ( defined( 'MBSFW_OAUTH_CLIENT_ID' ) && defined( 'MBSFW_OAUTH_CLIENT_SECRET' ) ) {
			return true;
		}
		$settings = AdminUI::get_settings();
		return ! empty( $settings['oauth_client_id'] ) && ! empty( $settings['oauth_client_secret'] );
	}
}
