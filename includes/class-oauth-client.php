<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

/**
 * Handles Moneybird OAuth 2.0 authorization code flow.
 *
 * Setup in Moneybird:
 *   - Create an OAuth application at https://moneybird.com/user/applications
 *   - Set redirect URI to: {site}/wp-admin/admin.php?page=mb-onboarding&mbsfw_oauth=callback
 *
 * Credentials are read from wp-config.php constants (preferred) or plugin settings:
 *   define('MBSFW_OAUTH_CLIENT_ID',     'your-client-id');
 *   define('MBSFW_OAUTH_CLIENT_SECRET', 'your-client-secret');
 */
class OAuthClient {
	private const AUTH_URL     = 'https://moneybird.com/oauth/authorize';
	private const TOKEN_URL    = 'https://moneybird.com/oauth/token';
	private const TOKEN_OPTION = 'mbsfw_oauth_token';   // stored as serialized array.
	private const STATE_OPTION = 'mbsfw_oauth_state';   // temporary CSRF token.
	private const ADMIN_OPTION = 'mbsfw_administration_id'; // selected administration.

	private string $client_id;
	private string $client_secret;

	public function __construct( string $client_id, string $client_secret ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
	}

	// ── Connection state ────────────────────────────────────────────────────

	public function is_connected(): bool {
		return '' !== $this->get_access_token();
	}

	public function get_access_token(): string {
		$data = get_option( self::TOKEN_OPTION, array() );
		return (string) ( $data['access_token'] ?? '' );
	}

	public function get_selected_administration_id(): string {
		return (string) get_option( self::ADMIN_OPTION, '' );
	}

	public function save_administration_id( string $administration_id ): void {
		update_option( self::ADMIN_OPTION, $administration_id, false );
	}

	public function disconnect(): void {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::STATE_OPTION );
		delete_option( self::ADMIN_OPTION );
		delete_option( 'mbsfw_connection_tested' );
	}

	// ── Authorization URL ────────────────────────────────────────────────────

	/**
	 * Generate the Moneybird authorization URL and persist the CSRF state token.
	 */
	public function get_authorize_url(): string {
		$state = bin2hex( random_bytes( 16 ) );
		update_option( self::STATE_OPTION, $state, false );

		return add_query_arg(
			array(
				'client_id'     => $this->client_id,
				'redirect_uri'  => $this->get_redirect_uri(),
				'response_type' => 'code',
				'scope'         => 'sales_invoices documents estimates bank',
				'state'         => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * The redirect URI registered in the Moneybird OAuth application.
	 */
	public function get_redirect_uri(): string {
		return admin_url( 'admin.php?page=mb-onboarding&mbsfw_oauth=callback' );
	}

	// ── Callback handling ────────────────────────────────────────────────────

	/**
	 * Exchange the authorization code for an access token and store it.
	 *
	 * @throws \RuntimeException on state mismatch or token exchange failure.
	 */
	public function handle_callback( string $code, string $state ): void {
		$saved_state = (string) get_option( self::STATE_OPTION, '' );

		if ( '' === $saved_state || ! hash_equals( $saved_state, $state ) ) {
			throw new \RuntimeException( 'Invalid OAuth state parameter. Please try connecting again.' );
		}

		delete_option( self::STATE_OPTION );

		$token_data = $this->exchange_code( $code );
		update_option( self::TOKEN_OPTION, $token_data, false );
	}

	// ── Token exchange ───────────────────────────────────────────────────────

	/**
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	private function exchange_code( string $code ): array {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'code'          => $code,
					'redirect_uri'  => $this->get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Token exchange failed: ' . $response->get_error_message() );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( $http_code !== 200 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) && isset( $data['error_description'] )
				? (string) $data['error_description']
				: $body;
			throw new \RuntimeException( "Token exchange failed (HTTP {$http_code}): {$error}" );
		}

		return $data;
	}

	// ── Static factory ───────────────────────────────────────────────────────

	/**
	 * Create an OAuthClient from wp-config.php constants or plugin settings.
	 */
	public static function from_settings(): self {
		$settings = AdminUI::get_settings();

		$client_id = defined( 'MBSFW_OAUTH_CLIENT_ID' )
			? constant( 'MBSFW_OAUTH_CLIENT_ID' )
			: (string) ( $settings['oauth_client_id'] ?? '' );

		$client_secret = defined( 'MBSFW_OAUTH_CLIENT_SECRET' )
			? constant( 'MBSFW_OAUTH_CLIENT_SECRET' )
			: (string) ( $settings['oauth_client_secret'] ?? '' );

		return new self( $client_id, $client_secret );
	}
}
