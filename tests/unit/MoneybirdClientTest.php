<?php
declare(strict_types=1);

namespace MoneybirdSyncForWoo\Tests\Unit;

use MoneybirdSyncForWoo\MoneybirdClient;
use PHPUnit\Framework\TestCase;
use WP_Error;

class MoneybirdClientTest extends TestCase {
	private MoneybirdClient $client;

	protected function setUp(): void {
		$this->client                   = new MoneybirdClient( 'test-token-123', 'admin_456' );
		$GLOBALS['__mbsfw_http_handler'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['__mbsfw_http_handler'] = null;
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $response_body
	 */
	private function mock_response( array $response_body, int $code = 200 ): void {
		$GLOBALS['__mbsfw_http_handler'] = static function () use ( $response_body, $code ): array {
			return array(
				'response' => array( 'code' => $code ),
				'body'     => (string) json_encode( $response_body ),
			);
		};
	}

	/**
	 * @param array<string, mixed>|null $captured_args reference to capture request args
	 * @param array<string, mixed>      $response_body
	 */
	private function capture_request( ?array &$captured_args, ?string &$captured_url, array $response_body, int $code = 200 ): void {
		$GLOBALS['__mbsfw_http_handler'] = static function ( string $url, array $args ) use ( &$captured_args, &$captured_url, $response_body, $code ): array {
			$captured_url  = $url;
			$captured_args = $args;
			return array(
				'response' => array( 'code' => $code ),
				'body'     => (string) json_encode( $response_body ),
			);
		};
	}

	// ── Authorization header ────────────────────────────────────────────────

	public function test_sends_bearer_token_in_auth_header(): void {
		$args = null;
		$url  = null;
		$this->capture_request( $args, $url, array( 'id' => 'inv_1' ) );

		$this->client->create_invoice( array( 'contact_id' => 'c1', 'reference' => 'WOO-1' ) );

		$this->assertIsArray( $args );
		$this->assertSame( 'Bearer test-token-123', $args['headers']['Authorization'] );
	}

	public function test_uses_administration_id_in_url(): void {
		$url  = null;
		$args = null;
		$this->capture_request( $args, $url, array( 'id' => 'inv_1' ) );

		$this->client->create_invoice( array( 'contact_id' => 'c1', 'reference' => 'WOO-1' ) );

		$this->assertStringContainsString( 'admin_456', (string) $url );
	}

	// ── create_invoice ───────────────────────────────────────────────────────

	public function test_create_invoice_sends_correct_payload(): void {
		$args = null;
		$url  = null;
		$this->capture_request( $args, $url, array( 'id' => 'inv_abc' ) );

		$result = $this->client->create_invoice(
			array(
				'contact_id'         => 'contact_1',
				'reference'          => 'WOO-42',
				'invoice_date'       => '2024-06-01',
				'details_attributes' => array(
					array( 'description' => 'T-Shirt', 'amount' => 2, 'price' => '19.99' ),
				),
			)
		);

		$this->assertSame( 'inv_abc', $result['id'] );
		$body = json_decode( (string) $args['body'], true );
		$this->assertArrayHasKey( 'sales_invoice', $body );
		$this->assertSame( 'WOO-42', $body['sales_invoice']['reference'] );
	}

	// ── create_invoice_payment ──────────────────────────────────────────────

	public function test_create_invoice_payment_targets_clearing_account(): void {
		$args = null;
		$url  = null;
		$this->capture_request( $args, $url, array( 'id' => 'pay_1' ) );

		$this->client->create_invoice_payment( 'inv_abc', 'clearing_123', '99.99', '2024-06-01' );

		$body = json_decode( (string) $args['body'], true );
		$this->assertSame( 'clearing_123', $body['payment']['financial_account_id'] );
		$this->assertSame( '99.99', $body['payment']['price'] );
		$this->assertStringContainsString( 'inv_abc', (string) $url );
	}

	// ── create_transfer ──────────────────────────────────────────────────────

	public function test_create_transfer_sends_source_and_target(): void {
		$args = null;
		$url  = null;
		$this->capture_request( $args, $url, array( 'id' => 'txfr_1' ) );

		$this->client->create_transfer( 'clearing_123', 'bank_456', '500.00', '2024-06-15', 'Stripe payout po_abc' );

		$body = json_decode( (string) $args['body'], true );
		$this->assertSame( 'clearing_123', $body['transfer']['source_id'] );
		$this->assertSame( 'bank_456', $body['transfer']['target_id'] );
		$this->assertSame( '500.00', $body['transfer']['price'] );
	}

	// ── Error handling ───────────────────────────────────────────────────────

	public function test_throws_runtime_exception_on_wp_error(): void {
		$GLOBALS['__mbsfw_http_handler'] = static function (): WP_Error {
			return new WP_Error( 'http_error', 'Connection timed out' );
		};

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Connection timed out/' );

		$this->client->create_invoice( array( 'contact_id' => 'c1' ) );
	}

	public function test_throws_runtime_exception_on_4xx_response(): void {
		$this->mock_response( array( 'error' => 'Unauthorized' ), 401 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/401/' );

		$this->client->create_invoice( array( 'contact_id' => 'c1' ) );
	}

	public function test_throws_runtime_exception_on_5xx_response(): void {
		$this->mock_response( array( 'error' => 'Internal Server Error' ), 500 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/500/' );

		$this->client->find_invoice_by_reference( 'WOO-1' );
	}

	// ── Response validation ──────────────────────────────────────────────────

	public function test_returns_empty_array_on_empty_response_body(): void {
		$GLOBALS['__mbsfw_http_handler'] = static function (): array {
			return array( 'response' => array( 'code' => 200 ), 'body' => '' );
		};

		$result = $this->client->create_invoice( array( 'contact_id' => 'c1' ) );
		$this->assertSame( array(), $result );
	}

	public function test_find_invoice_by_reference_returns_null_when_empty(): void {
		$this->mock_response( array() );

		$result = $this->client->find_invoice_by_reference( 'WOO-999' );
		$this->assertNull( $result );
	}

	public function test_find_invoice_by_reference_returns_first_match(): void {
		$this->mock_response( array( array( 'id' => 'inv_1', 'reference' => 'WOO-1' ) ) );

		$result = $this->client->find_invoice_by_reference( 'WOO-1' );
		$this->assertIsArray( $result );
		$this->assertSame( 'inv_1', $result['id'] );
	}

	// ── Journal document ─────────────────────────────────────────────────────

	public function test_create_journal_document_wraps_payload_correctly(): void {
		$args = null;
		$url  = null;
		$this->capture_request( $args, $url, array( 'id' => 'jnl_1' ) );

		$this->client->create_journal_document(
			array(
				'reference'   => 'STRIPE-FEE-fee_abc',
				'date'        => '2024-06-01',
				'description' => 'Stripe transaction fee',
				'general_journal_document_entries_attributes' => array(),
			)
		);

		$body = json_decode( (string) $args['body'], true );
		$this->assertArrayHasKey( 'general_journal_document', $body );
		$this->assertSame( 'STRIPE-FEE-fee_abc', $body['general_journal_document']['reference'] );
	}
}
