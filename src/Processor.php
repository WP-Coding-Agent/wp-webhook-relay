<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * Cron-driven queue processor with DB-level locking to prevent overlapping runs.
 *
 * Uses GET_LOCK() for distributed locking so only one processor runs at a time,
 * even across multiple cron workers.
 */
final class Processor
{
    private const LOCK_NAME    = 'webhook_relay_processor';
    private const LOCK_TIMEOUT = 1;
    private const BATCH_SIZE   = 50;

    /**
     * Backoff schedule in seconds: attempt 1→60s, 2→300s, 3→1800s, 4→7200s, 5→86400s
     */
    private const BACKOFF_BASE = 60;
    private const BACKOFF_MULTIPLIER = 5;

    public static function run(): void
    {
        global $wpdb;

        // Acquire an advisory lock — prevents overlapping cron runs.
        $lock = $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s, %d)", self::LOCK_NAME, self::LOCK_TIMEOUT)
        );

        if ((int) $lock !== 1) {
            return; // Another processor is running.
        }

        try {
            self::processQueue();
        } finally {
            $wpdb->query(
                $wpdb->prepare("SELECT RELEASE_LOCK(%s)", self::LOCK_NAME)
            );
        }
    }

    private static function processQueue(): void
    {
        global $wpdb;

        $queue_table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_QUEUE;
        $subs_table  = $wpdb->prefix . WEBHOOK_RELAY_TABLE_SUBS;
        $log_table   = $wpdb->prefix . WEBHOOK_RELAY_TABLE_LOG;
        $now         = current_time('mysql', true);

        // Fetch pending items whose next_attempt_at has passed.
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.subscription_id, q.event, q.payload, q.attempts, q.max_attempts,
                        s.url, s.secret
                 FROM {$queue_table} q
                 JOIN {$subs_table} s ON s.id = q.subscription_id
                 WHERE q.status = 'pending'
                   AND q.next_attempt_at <= %s
                 ORDER BY q.next_attempt_at ASC
                 LIMIT %d",
                $now,
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        if (empty($items)) {
            return;
        }

        // Mark as processing to prevent double-pickup.
        $ids = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$queue_table} SET status = 'processing' WHERE id IN ({$placeholders})",
                ...$ids
            )
        );

        foreach ($items as $item) {
            $result = Delivery::send($item['url'], $item['payload'], $item['secret']);

            // Log the attempt.
            $wpdb->insert($log_table, [
                'queue_id'        => $item['id'],
                'subscription_id' => $item['subscription_id'],
                'event'           => $item['event'],
                'status_code'     => $result['status_code'],
                'response_body'   => $result['response_body'],
                'response_time_ms'=> $result['response_time_ms'],
                'error_message'   => $result['error'],
            ], ['%d', '%d', '%s', '%d', '%s', '%d', '%s']);

            $attempts = (int) $item['attempts'] + 1;

            if ($result['success']) {
                $wpdb->update(
                    $queue_table,
                    ['status' => 'delivered', 'attempts' => $attempts],
                    ['id' => $item['id']],
                    ['%s', '%d'],
                    ['%d']
                );
            } elseif ($attempts >= (int) $item['max_attempts']) {
                $wpdb->update(
                    $queue_table,
                    ['status' => 'failed', 'attempts' => $attempts],
                    ['id' => $item['id']],
                    ['%s', '%d'],
                    ['%d']
                );
            } else {
                // Schedule retry with exponential backoff + jitter.
                $backoff = self::calculateBackoff($attempts);
                $next = gmdate('Y-m-d H:i:s', time() + $backoff);

                $wpdb->update(
                    $queue_table,
                    [
                        'status'          => 'pending',
                        'attempts'        => $attempts,
                        'next_attempt_at' => $next,
                    ],
                    ['id' => $item['id']],
                    ['%s', '%d', '%s'],
                    ['%d']
                );
            }
        }
    }

    /**
     * Exponential backoff with jitter.
     *
     * Attempt 1: ~60s, 2: ~300s, 3: ~1500s, 4: ~7500s, 5: ~37500s
     * Jitter: ±20% to prevent thundering herd on retries.
     */
    private static function calculateBackoff(int $attempt): int
    {
        $delay = (int) (self::BACKOFF_BASE * pow(self::BACKOFF_MULTIPLIER, $attempt - 1));
        $jitter = (int) ($delay * 0.2);

        return $delay + random_int(-$jitter, $jitter);
    }
}
