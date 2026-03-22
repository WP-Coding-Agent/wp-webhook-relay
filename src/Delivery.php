<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * HTTP delivery engine — sends a signed payload to a webhook endpoint
 * and captures the response.
 */
final class Delivery
{
    /**
     * @return array{success: bool, status_code: int|null, response_body: string|null, response_time_ms: int, error: string|null}
     */
    public static function send(string $url, string $payload, string $secret): array
    {
        $signature = Signer::sign($payload, $secret);
        $timeout   = (int) apply_filters('webhook_relay_timeout', 15);
        $start     = hrtime(true);

        $response = wp_remote_post($url, [
            'timeout'     => $timeout,
            'httpversion' => '1.1',
            'headers'     => [
                'Content-Type'        => 'application/json',
                'X-Webhook-Signature' => $signature,
                'User-Agent'          => 'WP-Webhook-Relay/' . WEBHOOK_RELAY_VERSION,
            ],
            'body'        => $payload,
            'sslverify'   => apply_filters('webhook_relay_ssl_verify', true),
        ]);

        $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

        if (is_wp_error($response)) {
            return [
                'success'          => false,
                'status_code'      => null,
                'response_body'    => null,
                'response_time_ms' => $elapsed,
                'error'            => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Truncate response body to 1KB for storage.
        if (strlen($body) > 1024) {
            $body = substr($body, 0, 1024) . '...(truncated)';
        }

        return [
            'success'          => $code >= 200 && $code < 300,
            'status_code'      => $code,
            'response_body'    => $body,
            'response_time_ms' => $elapsed,
            'error'            => null,
        ];
    }
}
