<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * Accepts events and creates queue entries for each matching subscription.
 */
final class Dispatcher
{
    /**
     * Queue an event for delivery to all matching subscribers.
     *
     * @param string               $event   Event name.
     * @param array<string, mixed> $payload Event payload.
     * @return int Number of queue entries created.
     */
    public function dispatch(string $event, array $payload = []): int
    {
        global $wpdb;

        $subscribers = Subscriber::forEvent($event);
        if (empty($subscribers)) {
            return 0;
        }

        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_QUEUE;
        $envelope = $this->buildEnvelope($event, $payload);
        $count = 0;

        foreach ($subscribers as $sub) {
            $wpdb->insert(
                $table,
                [
                    'subscription_id' => $sub['id'],
                    'event'           => $event,
                    'payload'         => wp_json_encode($envelope),
                    'max_attempts'    => $this->maxAttempts(),
                    'next_attempt_at' => current_time('mysql', true),
                ],
                ['%d', '%s', '%s', '%d', '%s']
            );
            ++$count;
        }

        /**
         * Fires after webhook events are queued.
         *
         * @param string $event      Event name.
         * @param int    $count      Number of queue entries created.
         * @param array  $payload    Original payload.
         */
        do_action('webhook_relay_dispatched', $event, $count, $payload);

        return $count;
    }

    /**
     * Build the standard webhook envelope.
     *
     * @return array{event: string, timestamp: string, delivery_id: string, data: array}
     */
    private function buildEnvelope(string $event, array $payload): array
    {
        return [
            'event'       => $event,
            'timestamp'   => gmdate('c'),
            'delivery_id' => wp_generate_uuid4(),
            'data'        => $payload,
        ];
    }

    private function maxAttempts(): int
    {
        return (int) apply_filters('webhook_relay_max_attempts', 5);
    }
}
