# WP Webhook Relay

Reliable outbound webhook delivery for WordPress — queued processing with exponential backoff, HMAC-SHA256 signing, delivery logs, and a full REST management API.

## The Problem

WordPress has `do_action()` for internal events, but no built-in way to reliably notify external systems. Sending HTTP requests inside hooks is fragile: timeouts block page loads, failures are silent, and there's no retry mechanism.

WP Webhook Relay adds Stripe-quality webhook infrastructure to any WordPress site.

## Features

- **Queued delivery** — events are queued in a DB table and processed asynchronously via WP-Cron
- **Exponential backoff** — failed deliveries retry at increasing intervals (1min → 5min → 25min → 2hr → 10hr) with jitter
- **HMAC signing** — every payload is signed with SHA-256 HMAC; receivers can verify authenticity
- **Delivery logging** — every attempt is logged with status code, response time, and truncated response body
- **REST API** — full CRUD for subscriptions, delivery history, retry, and event replay
- **DB locking** — `GET_LOCK()` prevents overlapping cron runs from double-processing
- **Developer API** — clean static facade: `WebhookRelay::dispatch()` and `WebhookRelay::subscribe()`

## Installation

```bash
composer require wp-coding-agent/wp-webhook-relay
```

Activate the plugin — tables are created automatically via `dbDelta`.

## Usage

### Dispatching Events

```php
use WebhookRelay\WebhookRelay;

// In your plugin or theme:
add_action('transition_post_status', function ($new, $old, $post) {
    if ($new === 'publish' && $old !== 'publish') {
        WebhookRelay::dispatch('post.published', [
            'post_id' => $post->ID,
            'title'   => $post->post_title,
            'url'     => get_permalink($post),
            'author'  => get_the_author_meta('display_name', $post->post_author),
        ]);
    }
}, 10, 3);
```

### Registering Subscriptions

```php
// Via PHP:
WebhookRelay::subscribe(
    'https://api.example.com/webhooks/wordpress',
    ['post.published', 'user.created'],
    'whsec_your_signing_secret'
);

// Via REST API:
// POST /wp-json/webhook-relay/v1/subscriptions
// {"url": "https://...", "events": ["post.published"], "secret": "optional"}
```

### Verifying Signatures (Receiver Side)

```php
// On the receiving server:
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$secret    = 'whsec_your_signing_secret';

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    die('Invalid signature');
}

$data = json_decode($payload, true);
// Process $data['event'], $data['data'], etc.
```

## Webhook Envelope Format

```json
{
  "event": "post.published",
  "timestamp": "2026-03-22T12:00:00+00:00",
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "data": {
    "post_id": 42,
    "title": "Hello World"
  }
}
```

## REST API

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/webhook-relay/v1/subscriptions` | List subscriptions |
| POST | `/webhook-relay/v1/subscriptions` | Create subscription |
| DELETE | `/webhook-relay/v1/subscriptions/{id}` | Delete subscription |
| GET | `/webhook-relay/v1/deliveries` | List queue entries (filterable) |
| POST | `/webhook-relay/v1/deliveries/{id}/retry` | Retry a failed delivery |
| POST | `/webhook-relay/v1/replay` | Re-dispatch an event |
| GET | `/webhook-relay/v1/log` | View delivery attempt logs |

All endpoints require `manage_options` capability.

## WP-CLI

```bash
wp webhook list                    # List all subscriptions
wp webhook stats                   # Queue status breakdown
wp webhook process                 # Process queue immediately
wp webhook replay post.published --payload='{"post_id":42}'
```

## License

GPL-2.0-or-later
