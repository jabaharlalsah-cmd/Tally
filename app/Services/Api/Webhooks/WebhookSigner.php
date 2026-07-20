<?php

namespace App\Services\Api\Webhooks;

/**
 * Phase 16C — the HMAC-SHA256 signing scheme. USED IN BOTH DIRECTIONS.
 *
 * 16C signs OUTBOUND deliveries with it. 16D verifies INBOUND events from the customer with the
 * identical construction — which is why this class is written as a symmetric pair (sign/verify)
 * rather than a one-way helper bolted to the dispatcher. Get it right once, and the inbound phase
 * inherits it.
 *
 * THE CONSTRUCTION (Stripe/GitHub-style, deliberately conventional so a customer can use an
 * off-the-shelf snippet):
 *
 *     signed_payload = "<unix_timestamp>.<raw_request_body>"
 *     signature      = hex( HMAC-SHA256( secret, signed_payload ) )
 *     header         = "sha256=<signature>"
 *
 * WHY THE TIMESTAMP IS INSIDE THE MAC, not just a header: it binds the time to the signature. If
 * the timestamp were merely a header, an attacker who captured one valid delivery could replay the
 * body forever with a fresh timestamp — the MAC would still verify. Signing "<ts>.<body>" means
 * changing the timestamp invalidates the signature, so the receiver's freshness window is
 * actually enforceable rather than advisory.
 *
 * WHY hash_equals AND NOT ===: a naive string compare returns early at the first differing byte,
 * so its runtime leaks how many leading bytes an attacker guessed right — enough to forge a
 * signature byte-by-byte over many attempts. hash_equals compares in constant time. This is the
 * same discipline 16A applies to API keys (there via password_verify).
 */
class WebhookSigner
{
    /** The header the signature travels in. */
    public const SIGNATURE_HEADER = 'X-ZeroBook-Signature';

    public const TIMESTAMP_HEADER = 'X-ZeroBook-Timestamp';

    public const EVENT_HEADER = 'X-ZeroBook-Event';

    public const EVENT_ID_HEADER = 'X-ZeroBook-Event-Id';

    public const DELIVERY_HEADER = 'X-ZeroBook-Delivery';

    /** The prefix that names the algorithm, so the scheme can evolve without ambiguity. */
    public const PREFIX = 'sha256=';

    /**
     * How stale a delivery may be before a receiver should refuse it. Five minutes is the
     * conventional window: long enough to survive clock skew and a slow network, short enough that
     * a captured delivery is not replayable tomorrow.
     */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** Generate a fresh signing secret. 64 hex chars = 256 bits. */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The signed payload: "<timestamp>.<body>". Exposed so a customer's docs and 16D's verifier
     * describe the same string, and so a test can construct it independently.
     */
    public static function signedPayload(int $timestamp, string $body): string
    {
        return $timestamp.'.'.$body;
    }

    /** The full header value: "sha256=<hex>". */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return self::PREFIX.hash_hmac('sha256', self::signedPayload($timestamp, $body), $secret);
    }

    /**
     * Verify a received signature. Used by 16D for inbound events, and by 16C's own proof.
     *
     * Returns false — never throws — for every failure mode: a malformed header, a wrong secret, a
     * tampered body, or a timestamp outside the freshness window. All four are the same answer to
     * the caller, so the verifier never becomes an oracle about WHY it failed.
     *
     * @param  int|null  $toleranceSeconds  null disables the freshness check (only for a caller
     *                                      that has already checked it, or a fixture)
     */
    public static function verify(
        string $secret,
        int $timestamp,
        string $body,
        string $signature,
        ?int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        // Freshness first: a stale delivery is refused even if the MAC is perfect (that IS the
        // replay defence — the MAC alone cannot tell you when it was made).
        if ($toleranceSeconds !== null && ! self::withinTolerance($timestamp, $toleranceSeconds)) {
            return false;
        }

        $expected = self::sign($secret, $timestamp, $body);

        // Constant time. Note hash_equals is safe on unequal lengths and does not early-return.
        return hash_equals($expected, $signature);
    }

    /**
     * Is $timestamp within ±tolerance of now?
     *
     * Symmetric on purpose: a delivery from the FUTURE is as suspicious as a stale one (it means
     * clock skew or a forged timestamp), and allowing unbounded future timestamps would let an
     * attacker mint a delivery that stays "fresh" for years.
     */
    public static function withinTolerance(int $timestamp, int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS): bool
    {
        return abs(time() - $timestamp) <= $toleranceSeconds;
    }

    /**
     * The headers a delivery carries. One place, so the outbound sender and the documented
     * verification recipe can never drift apart.
     */
    public static function headers(string $secret, string $eventType, string $eventId, string $deliveryId, int $timestamp, string $body): array
    {
        return [
            self::EVENT_HEADER => $eventType,
            self::EVENT_ID_HEADER => $eventId,
            self::DELIVERY_HEADER => $deliveryId,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => self::sign($secret, $timestamp, $body),
        ];
    }
}
