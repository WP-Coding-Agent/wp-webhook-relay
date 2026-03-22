<?php
declare(strict_types=1);

namespace WebhookRelay\CLI;

use WebhookRelay\Processor;
use WebhookRelay\Subscriber;
use WebhookRelay\WebhookRelay;
use WP_CLI;
use WP_CLI\Utils;

final class WebhookCommand
{
    /**
     * List webhook subscriptions.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp webhook list
     *
     * @subcommand list
     */
    public function list_(array $args, array $assoc_args): void
    {
        $result = Subscriber::list(1, 100);

        if (empty($result['items'])) {
            WP_CLI::log('No subscriptions found.');
            return;
        }

        $rows = array_map(function ($item) {
            $item['events'] = implode(', ', $item['events'] ?? []);
            return $item;
        }, $result['items']);

        Utils\format_items(
            $assoc_args['format'] ?? 'table',
            $rows,
            ['id', 'url', 'events', 'active', 'created_at']
        );
    }

    /**
     * Process the delivery queue immediately.
     *
     * ## EXAMPLES
     *
     *     wp webhook process
     */
    public function process(): void
    {
        WP_CLI::log('Processing webhook queue...');
        Processor::run();
        WP_CLI::success('Queue processed.');
    }

    /**
     * Replay an event (re-dispatch to all subscribers).
     *
     * ## OPTIONS
     *
     * <event>
     * : Event name to replay.
     *
     * [--payload=<json>]
     * : JSON payload. Default: {}
     *
     * ## EXAMPLES
     *
     *     wp webhook replay post.published --payload='{"post_id":42}'
     */
    public function replay(array $args, array $assoc_args): void
    {
        $event = $args[0];
        $payload = json_decode($assoc_args['payload'] ?? '{}', true) ?: [];

        $count = WebhookRelay::dispatch($event, $payload);
        WP_CLI::success("Replayed '{$event}' — {$count} deliveries queued.");
    }

    /**
     * Show queue statistics.
     *
     * ## EXAMPLES
     *
     *     wp webhook stats
     */
    public function stats(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_QUEUE;

        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );

        if (empty($counts)) {
            WP_CLI::log('Queue is empty.');
            return;
        }

        Utils\format_items('table', $counts, ['status', 'count']);
    }
}
