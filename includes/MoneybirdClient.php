<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo;

class MoneybirdClient {
	private const API_BASE = 'https://moneybird.com/api/v2';

	private string $token;
	private string $administration_id;

	public function __construct( string $token, string $administration_id ) {
		$this->token             = $token;
		$this->administration_id = $administration_id;
	}

	/**
	 * Find a contact by email or create one.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function find_or_create_contact( string $email, string $name ): array {
		$contacts = $this->request( 'GET', '/contacts', array( 'query' => $email ) );
		foreach ( $contacts as $contact ) {
			if ( isset( $contact['email'] ) && strtolower( (string) $contact['email'] ) === strtolower( $email ) ) {
				return $contact;
			}
		}
		return $this->request(
			'POST',
			'/contacts',
			array(),
			array(
				'contact' => array(
					'firstname' => $name,
					'email'     => $email,
				),
			)
		);
	}

	/**
	 * Find an existing sales invoice by reference (WOO-{order_id}).
	 *
	 * @return array<string, mixed>|null
	 * @throws \RuntimeException
	 */
	public function find_invoice_by_reference( string $reference ): ?array {
		$invoices = $this->request( 'GET', '/sales_invoices', array( 'reference' => $reference ) );
		return ! empty( $invoices ) ? $invoices[0] : null;
	}

	/**
	 * Create a sales invoice.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function create_invoice( array $data ): array {
		return $this->request( 'POST', '/sales_invoices', array(), array( 'sales_invoice' => $data ) );
	}

	/**
	 * Transition a draft invoice to 'sent' (open) status.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function send_invoice( string $invoice_id ): array {
		return $this->request(
			'POST',
			"/sales_invoices/{$invoice_id}/events",
			array(),
			array(
				'sales_invoice_event' => array(
					'action' => 'send_invoice',
				),
			)
		);
	}

	/**
	 * Delete a sales invoice.
	 *
	 * @throws \RuntimeException
	 */
	public function delete_invoice( string $invoice_id ): void {
		$this->request( 'DELETE', "/sales_invoices/{$invoice_id}" );
	}

	/**
	 * Create a payment on a sales invoice targeting the Stripe Clearing financial account.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function create_invoice_payment(
		string $invoice_id,
		string $financial_account_id,
		string $amount,
		string $date
	): array {
		return $this->request(
			'POST',
			"/sales_invoices/{$invoice_id}/payments",
			array(),
			array(
				'payment' => array(
					'payment_date'         => $date,
					'price'                => $amount,
					'financial_account_id' => $financial_account_id,
				),
			)
		);
	}

	/**
	 * Create a general journal document (used for Stripe fee entries).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function create_journal_document( array $data ): array {
		return $this->request( 'POST', '/general_journal_documents', array(), array( 'general_journal_document' => $data ) );
	}

	/**
	 * Create a transfer between two financial accounts (Stripe Clearing → Bank).
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function create_transfer(
		string $source_account_id,
		string $target_account_id,
		string $amount,
		string $date,
		string $description
	): array {
		return $this->request(
			'POST',
			'/financial_mutations/transfer',
			array(),
			array(
				'transfer' => array(
					'source_id'   => $source_account_id,
					'target_id'   => $target_account_id,
					'date'        => $date,
					'price'       => $amount,
					'description' => $description,
				),
			)
		);
	}

	/**
	 * Retrieve financial mutations for an account (used by ReconciliationService).
	 *
	 * @param array<string, string> $filters  e.g. ['filter' => 'period:2024-01-01..2024-01-31']
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException
	 */
	public function get_financial_mutations( string $account_id, array $filters = [] ): array {
		return $this->request( 'GET', "/financial_accounts/{$account_id}/financial_mutations", $filters );
	}

	// ── Onboarding / setup endpoints ────────────────────────────────────────

	/**
	 * List all Moneybird administrations the token has access to.
	 * This endpoint does NOT use the administration_id in the URL.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException
	 */
	public function get_administrations(): array {
		return $this->request_absolute( 'GET', self::API_BASE . '/administrations' );
	}

	/**
	 * List all financial accounts (bank accounts, payment processors, etc.) for the
	 * current administration. Used during onboarding to let the user choose the
	 * Stripe Clearing Account and Bank Account.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException
	 */
	public function get_financial_accounts(): array {
		return $this->request( 'GET', '/financial_accounts' );
	}

	/**
	 * List ledger accounts for the current administration. Used during onboarding
	 * to let the user pick the Stripe Fees expense ledger.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException
	 */
	public function get_ledger_accounts(): array {
		return $this->request( 'GET', '/ledger_accounts' );
	}

	/**
	 * Verify the token and administration are valid by fetching the administration.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException
	 */
	public function test_connection(): array {
		return $this->request( 'GET', '/financial_accounts' );
	}

	// ── Internal HTTP ────────────────────────────────────────────────────────

	/**
	 * Make a request to an absolute URL (no administration_id prefix).
	 *
	 * @param array<string, string> $params
	 * @param array<string, mixed>  $body
	 * @return array<mixed>
	 * @throws \RuntimeException
	 */
	private function request_absolute( string $method, string $url, array $params = array(), array $body = array() ): array {
		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Moneybird API request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$error = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : $raw;
			throw new \RuntimeException( "Moneybird API error {$code}: {$error}" );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, string> $params Query-string parameters.
	 * @param array<string, mixed>  $body   JSON body.
	 * @return array<mixed>
	 * @throws \RuntimeException
	 */
	private function request( string $method, string $path, array $params = array(), array $body = array() ): array {
		$url = self::API_BASE . '/' . $this->administration_id . $path;

		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Moneybird API request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$error = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : $raw;
			throw new \RuntimeException( "Moneybird API error {$code}: {$error}" );
		}

		return is_array( $data ) ? $data : array();
	}
}
