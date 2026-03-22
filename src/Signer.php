<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * HMAC-SHA256 webhook signing and verification.
 *
 * Pure PHP — no WordPress dependencies. Fully unit-testable.
 *
 * Signature format: "sha256={hex_digest}"
 * Sent in the X-Webhook-Signature header.
 */
final class Signer
{
    /**
     * Sign a payload string.
     *
     * @param string $payload JSON-encoded payload.
     * @param string $secret  Subscriber's signing secret.
     * @return string Signature in "sha256={hex}" format.
     */
    public static function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify a signature against a payload.
     *
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $payload   JSON-encoded payload.
     * @param string $secret    Subscriber's signing secret.
     * @param string $signature The signature to verify.
     * @return bool
     */
    public static function verify(string $payload, string $secret, string $signature): bool
    {
        $expected = self::sign($payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Generate a cryptographically secure random secret.
     *
     * @param int $length Number of random bytes (hex output will be 2x this).
     * @return string Hex-encoded secret.
     */
    public static function generateSecret(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}
