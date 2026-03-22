<?php
declare(strict_types=1);

namespace WebhookRelay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WebhookRelay\Signer;

final class SignerTest extends TestCase
{
    public function test_sign_produces_sha256_prefixed_signature(): void
    {
        $sig = Signer::sign('{"test":true}', 'secret');
        $this->assertStringStartsWith('sha256=', $sig);
        $this->assertSame(71, strlen($sig)); // "sha256=" (7) + 64 hex chars.
    }

    public function test_verify_accepts_valid_signature(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'my-secret';
        $sig = Signer::sign($payload, $secret);

        $this->assertTrue(Signer::verify($payload, $secret, $sig));
    }

    public function test_verify_rejects_wrong_secret(): void
    {
        $payload = '{"event":"test"}';
        $sig = Signer::sign($payload, 'correct-secret');

        $this->assertFalse(Signer::verify($payload, 'wrong-secret', $sig));
    }

    public function test_verify_rejects_tampered_payload(): void
    {
        $secret = 'secret';
        $sig = Signer::sign('{"original":true}', $secret);

        $this->assertFalse(Signer::verify('{"tampered":true}', $secret, $sig));
    }

    public function test_generate_secret_produces_unique_values(): void
    {
        $a = Signer::generateSecret();
        $b = Signer::generateSecret();

        $this->assertNotSame($a, $b);
        $this->assertSame(64, strlen($a)); // 32 bytes = 64 hex chars.
    }

    public function test_deterministic_for_same_inputs(): void
    {
        $sig1 = Signer::sign('payload', 'key');
        $sig2 = Signer::sign('payload', 'key');

        $this->assertSame($sig1, $sig2);
    }
}
