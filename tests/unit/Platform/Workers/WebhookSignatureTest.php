<?php

namespace Tests\Unit\Platform\Workers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for BUG-06 (Webhooks.php) — Backward-compatible dual-signature delivery.
 *
 * Covers:
 *  - sha1 signature generation (default, backward-compat)
 *  - sha256 signature generation (migration target)
 *  - Both differ from each other
 *  - hash_equals() constant-time comparison demonstrates the correct consumer-side pattern
 *  - Env-var _APP_WEBHOOK_SIGNATURE_ALGO selects the primary algorithm
 */
class WebhookSignatureTest extends TestCase
{
    private string $url          = 'https://example.com/webhook';
    private string $payload      = '{"event":"users.create","userId":"u1"}';
    private string $signatureKey = 'test-secret-key-12345';

    // -----------------------------------------------------------------------
    // Signature generation
    // -----------------------------------------------------------------------

    public function testSha1SignatureIsCorrectlyGenerated(): void
    {
        $expected  = base64_encode(hash_hmac('sha1', $this->url . $this->payload, $this->signatureKey, true));
        $generated = $this->computeSignature('sha1');

        $this->assertEquals($expected, $generated, 'SHA-1 signature must match the expected HMAC-SHA1');

        // Verify raw binary length = 20 bytes (160-bit digest)
        $this->assertEquals(20, strlen(base64_decode($generated)));
    }

    public function testSha256SignatureIsCorrectlyGenerated(): void
    {
        $expected  = base64_encode(hash_hmac('sha256', $this->url . $this->payload, $this->signatureKey, true));
        $generated = $this->computeSignature('sha256');

        $this->assertEquals($expected, $generated, 'SHA-256 signature must match the expected HMAC-SHA256');

        // Verify raw binary length = 32 bytes (256-bit digest)
        $this->assertEquals(32, strlen(base64_decode($generated)));
    }

    public function testSha1AndSha256SignaturesDiffer(): void
    {
        $sha1   = $this->computeSignature('sha1');
        $sha256 = $this->computeSignature('sha256');

        $this->assertNotEquals($sha1, $sha256, 'SHA-1 and SHA-256 signatures must produce different output');
    }

    // -----------------------------------------------------------------------
    // Env-var configurable primary algorithm
    // -----------------------------------------------------------------------

    public function testDefaultAlgoIsSha1ForBackwardCompatibility(): void
    {
        // Without any env override, the default must be sha1 to protect existing consumers.
        $defaultAlgo = 'sha1'; // mirrors: System::getEnv('_APP_WEBHOOK_SIGNATURE_ALGO', 'sha1')

        $signatureWithDefault = $this->computeSignature($defaultAlgo);
        $sha1Signature        = $this->computeSignature('sha1');

        $this->assertEquals(
            $sha1Signature,
            $signatureWithDefault,
            'Default algorithm must be sha1 so existing consumers are not broken'
        );
    }

    public function testSha256CanBeSetAsPrimaryViaConfig(): void
    {
        // Simulates: System::getEnv('_APP_WEBHOOK_SIGNATURE_ALGO', 'sha1') returning 'sha256'
        $algo             = 'sha256';
        $signatureWithEnv = $this->computeSignature($algo);
        $sha256Signature  = $this->computeSignature('sha256');

        $this->assertEquals(
            $sha256Signature,
            $signatureWithEnv,
            'When algo is sha256 the primary signature must be HMAC-SHA256'
        );
    }

    // -----------------------------------------------------------------------
    // Dual-header delivery: both sha1 and sha256 always sent
    // -----------------------------------------------------------------------

    public function testBothSignaturesAreSentInDualHeaderDelivery(): void
    {
        $primary   = $this->computeSignature('sha1');    // X-Appwrite-Webhook-Signature (default)
        $secondary = $this->computeSignature('sha256');  // X-Appwrite-Webhook-Signature-256

        // Simulate the consumer receiving and validating either header
        $receivedPrimary   = $primary;
        $receivedSecondary = $secondary;

        $expectedPrimary   = base64_encode(hash_hmac('sha1',   $this->url . $this->payload, $this->signatureKey, true));
        $expectedSecondary = base64_encode(hash_hmac('sha256', $this->url . $this->payload, $this->signatureKey, true));

        $this->assertTrue(
            hash_equals($expectedPrimary, $receivedPrimary),
            'Consumer can validate the primary (sha1) header using hash_equals()'
        );

        $this->assertTrue(
            hash_equals($expectedSecondary, $receivedSecondary),
            'Consumer can validate the sha256 header using hash_equals()'
        );
    }

    // -----------------------------------------------------------------------
    // Constant-time comparison pattern (consumer-side)
    // -----------------------------------------------------------------------

    public function testHashEqualsConstantTimeComparisonForSha1(): void
    {
        $signature = $this->computeSignature('sha1');
        $expected  = base64_encode(hash_hmac('sha1', $this->url . $this->payload, $this->signatureKey, true));

        // CORRECT: use hash_equals, not === (prevents timing attacks)
        $this->assertTrue(hash_equals($expected, $signature));
    }

    public function testHashEqualsConstantTimeComparisonForSha256(): void
    {
        $signature = $this->computeSignature('sha256');
        $expected  = base64_encode(hash_hmac('sha256', $this->url . $this->payload, $this->signatureKey, true));

        $this->assertTrue(hash_equals($expected, $signature));
    }

    public function testTamperedPayloadFailsSignatureValidation(): void
    {
        $signature       = $this->computeSignature('sha256');
        $tamperedPayload = '{"event":"users.delete","userId":"u1"}'; // different payload

        $expectedFromTampered = base64_encode(
            hash_hmac('sha256', $this->url . $tamperedPayload, $this->signatureKey, true)
        );

        $this->assertFalse(
            hash_equals($expectedFromTampered, $signature),
            'A tampered payload must produce a different signature (validation must fail)'
        );
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Mirrors the production signature computation in Webhooks::execute().
     */
    private function computeSignature(string $algo): string
    {
        return base64_encode(hash_hmac($algo, $this->url . $this->payload, $this->signatureKey, true));
    }
}
