<?php
declare(strict_types=1);
/**
 * Plugin Name:  WP Webhook Relay
 * Description:  Reliable outbound webhook delivery with HMAC signing, exponential backoff, and queue processing.
 * Version:      1.0.0
 * Requires PHP: 8.0
 * License:      GPL-2.0-or-later
 *
 * @package WebhookRelay
 */

defined( 'ABSPATH' ) || exit;

define( 'WEBHOOK_RELAY_VERSION', '1.0.0' );
define( 'WEBHOOK_RELAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBHOOK_RELAY_TABLE_SUBS', 'webhook_relay_subscriptions' );
define( 'WEBHOOK_RELAY_TABLE_QUEUE', 'webhook_relay_queue' );
define( 'WEBHOOK_RELAY_TABLE_LOG', 'webhook_relay_delivery_log' );

require_once WEBHOOK_RELAY_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ WebhookRelay\Schema::class, 'install' ] );

add_action( 'rest_api_init', static function (): void {
	( new WebhookRelay\REST\SubscriptionController() )->register_routes();
	( new WebhookRelay\REST\DeliveryController() )->register_routes();
} );

// Register the cron processor.
add_action( 'webhook_relay_process_queue', [ WebhookRelay\Processor::class, 'run' ] );

// Schedule cron if not already scheduled.
add_action( 'init', static function (): void {
	if ( ! wp_next_scheduled( 'webhook_relay_process_queue' ) ) {
		wp_schedule_event( time(), 'every_minute', 'webhook_relay_process_queue' );
	}
} );

// Add custom cron interval.
add_filter( 'cron_schedules', static function ( array $schedules ): array {
	$schedules['every_minute'] = [
		'interval' => 60,
		'display'  => 'Every Minute',
	];
	return $schedules;
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'webhook', WebhookRelay\CLI\WebhookCommand::class );
}
