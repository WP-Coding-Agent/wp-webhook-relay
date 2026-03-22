<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * Data model for webhook subscriptions.
 */
final class Subscriber
{
    public static function create(string $url, array $events, string $secret): int
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_SUBS;

        $wpdb->insert(
            $table,
            [
                'url'    => esc_url_raw($url),
                'events' => wp_json_encode(array_values(array_unique($events))),
                'secret' => $secret,
            ],
            ['%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_SUBS;

        return (bool) $wpdb->delete($table, ['id' => $id], ['%d']);
    }

    /**
     * Find all active subscriptions for a given event.
     *
     * @return array<int, array{id: int, url: string, secret: string}>
     */
    public static function forEvent(string $event): array
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_SUBS;

        // Events are stored as JSON array. Use LIKE for initial filter,
        // then verify in PHP to avoid false positives.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, secret, events FROM {$table} WHERE active = 1 AND events LIKE %s",
                '%' . $wpdb->esc_like($event) . '%'
            ),
            ARRAY_A
        );

        $matches = [];
        foreach ($rows as $row) {
            $events = json_decode($row['events'], true);
            if (is_array($events) && in_array($event, $events, true)) {
                $matches[] = [
                    'id'     => (int) $row['id'],
                    'url'    => $row['url'],
                    'secret' => $row['secret'],
                ];
            }
        }

        return $matches;
    }

    /**
     * List all subscriptions with pagination.
     *
     * @return array{items: array, total: int}
     */
    public static function list(int $page = 1, int $perPage = 20): array
    {
        global $wpdb;
        $table  = $wpdb->prefix . WEBHOOK_RELAY_TABLE_SUBS;
        $offset = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, events, active, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $perPage,
                $offset
            ),
            ARRAY_A
        );

        foreach ($items as &$item) {
            $item['id']     = (int) $item['id'];
            $item['active'] = (bool) $item['active'];
            $item['events'] = json_decode($item['events'], true);
        }

        return ['items' => $items, 'total' => $total];
    }
}
