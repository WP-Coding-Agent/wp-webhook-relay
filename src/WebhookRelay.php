<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * Public-facing static API for dispatching webhooks and managing subscriptions.
 *
 * Usage:
 *   WebhookRelay::dispatch('post.published', ['post_id' => 42, 'title' => 'Hello']);
 *   WebhookRelay::subscribe('https://example.com/hook', ['post.published'], 'secret123');
 */
final class WebhookRelay
{
    /**
     * Dispatch an event to all matching subscribers.
     *
     * Creates queue entries for async delivery via the cron processor.
     *
     * @param string               $event   Event name (e.g., 'post.published').
     * @param array<string, mixed> $payload Arbitrary payload data.
     * @return int Number of queue entries created.
     */
    public static function dispatch(string $event, array $payload = []): int
    {
        return ( new Dispatcher() )->dispatch($event, $payload);
    }

    /**
     * Register a webhook subscription.
     *
     * @param string   $url    Delivery endpoint URL.
     * @param string[] $events Events to subscribe to.
     * @param string   $secret HMAC signing secret.
     * @return int Subscription ID.
     */
    public static function subscribe(string $url, array $events, string $secret): int
    {
        return Subscriber::create($url, $events, $secret);
    }

    /**
     * Remove a subscription.
     */
    public static function unsubscribe(int $id): bool
    {
        return Subscriber::delete($id);
    }
}
