<?php
/**
 * Plugin Name: Moneybird Sync for WooCommerce
 * Description: Syncs WooCommerce / Stripe orders to Moneybird using the Stripe Clearing Account model.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: moneybird-sync-for-woo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 *
 * @package MoneybirdSyncForWoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MBSFW_VERSION', '1.0.0' );
define( 'MBSFW_PLUGIN_FILE', __FILE__ );
define( 'MBSFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Bail early if Composer autoloader is missing (e.g. during development before `composer install`).
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p><strong>Moneybird Sync for WooCommerce:</strong> Run <code>composer install</code> before activating this plugin.</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// ── Activation ────────────────────────────────────────────────────────────────

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Moneybird Sync for WooCommerce requires WooCommerce to be active.', 'moneybird-sync-for-woo' ) );
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = (string) file_get_contents( MBSFW_PLUGIN_DIR . 'database/migrations.sql' );
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		// dbDelta handles CREATE TABLE idempotently.
		dbDelta( $sql );

		// Schedule cron worker.
		if ( ! wp_next_scheduled( \MoneybirdSyncForWoo\Worker::CRON_HOOK ) ) {
			wp_schedule_event( time(), \MoneybirdSyncForWoo\Worker::CRON_INTERVAL, \MoneybirdSyncForWoo\Worker::CRON_HOOK );
		}
	}
);

// ── Deactivation ──────────────────────────────────────────────────────────────

register_deactivation_hook(
	__FILE__,
	static function (): void {
		$timestamp = wp_next_scheduled( \MoneybirdSyncForWoo\Worker::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, \MoneybirdSyncForWoo\Worker::CRON_HOOK );
		}
	}
);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\MoneybirdSyncForWoo\Plugin::instance()->init();
	}
);
