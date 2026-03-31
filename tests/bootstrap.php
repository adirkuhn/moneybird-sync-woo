<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap: loads the Composer autoloader and provides lightweight
 * stubs for WordPress and WooCommerce global functions so unit tests can run
 * without a full WordPress installation.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Global HTTP request handler (tests override $GLOBALS['__mbsfw_http_handler'])
// ---------------------------------------------------------------------------
$GLOBALS['__mbsfw_http_handler']  = null;
$GLOBALS['__mbsfw_options']       = array();   // simulated wp_options table.
$GLOBALS['__mbsfw_wc_order_map']  = array();   // order_id → WC_Order mock.

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string|false {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param array<string, string> $args
	 */
	function add_query_arg( array $args, string $url ): string {
		$query = http_build_query( $args );
		return strpos( $url, '?' ) !== false ? $url . '&' . $query : $url . '?' . $query;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	function wp_remote_post( string $url, array $args = [] ): array|\WP_Error {
		if ( isset( $GLOBALS['__mbsfw_http_handler'] ) && is_callable( $GLOBALS['__mbsfw_http_handler'] ) ) {
			return ( $GLOBALS['__mbsfw_http_handler'] )( $url, $args );
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	function wp_remote_request( string $url, array $args = [] ): array|\WP_Error {
		if ( isset( $GLOBALS['__mbsfw_http_handler'] ) && is_callable( $GLOBALS['__mbsfw_http_handler'] ) ) {
			return ( $GLOBALS['__mbsfw_http_handler'] )( $url, $args );
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array<string, mixed>|\WP_Error $response
	 */
	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array<string, mixed>|\WP_Error $response
	 */
	function wp_remote_retrieve_body( array|\WP_Error $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $id ): \WC_Order|false {
		if ( isset( $GLOBALS['__mbsfw_wc_order_map'][ $id ] ) ) {
			return $GLOBALS['__mbsfw_wc_order_map'][ $id ];
		}
		return false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['__mbsfw_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool|string $autoload = true ): bool {
		$GLOBALS['__mbsfw_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['__mbsfw_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( int $timestamp, string $hook ): bool {
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'number_format' ) ) {
	// Already a PHP core function — no stub needed.
}

// ---------------------------------------------------------------------------
// Minimal wpdb stub (tests use PHPUnit mocks of this class)
// ---------------------------------------------------------------------------

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;

		public function insert( string $table, array $data, array $format = [] ): int|false { return 1; }
		public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false { return 1; }
		public function query( string $query ): int|bool { return 1; }
		public function get_results( string $query, string $output = 'OBJECT' ): array { return []; }
		public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): object|null { return null; }
		public function get_var( string $query, int $x = 0, int $y = 0 ): mixed { return null; }
		public function prepare( string $query, mixed ...$args ): string { return $query; }
	}
}

// ---------------------------------------------------------------------------
// Minimal WP_Error stub
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// ---------------------------------------------------------------------------
// Minimal WC_Order stub (tests use PHPUnit mocks instead of this)
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public function get_id(): int { return 0; }
		public function get_payment_method(): string { return ''; }
		public function get_billing_email(): string { return ''; }
		public function get_billing_first_name(): string { return ''; }
		public function get_billing_last_name(): string { return ''; }
		public function get_total(): float { return 0.0; }
		public function get_date_created(): ?\DateTimeInterface { return null; }
		public function get_date_paid(): ?\DateTimeInterface { return null; }
		/** @return \WC_Order_Item_Product[] */
		public function get_items(): array { return array(); }
		public function get_meta( string $key, bool $single = true ): mixed { return ''; }
		public function update_meta_data( string $key, mixed $value ): void {}
		public function save_meta_data(): void {}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		public function get_name(): string { return ''; }
		public function get_quantity(): int { return 1; }
		public function get_total(): float { return 0.0; }
	}
}
