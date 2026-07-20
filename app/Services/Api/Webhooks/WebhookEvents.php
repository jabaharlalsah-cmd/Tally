<?php

namespace App\Services\Api\Webhooks;

/**
 * Phase 16C — the event catalog.
 *
 * One list, consulted by the subscription UI/API (which events are choosable), the emitter (what
 * may be emitted), and the docs. A subscription can only ever hold a catalog event or '*', so a
 * typo in a subscription request is rejected rather than silently subscribing to nothing.
 */
class WebhookEvents
{
    public const WILDCARD = '*';

    /** A signed no-op the customer can fire from the UI/API to test their endpoint. */
    public const PING = 'ping';

    public const VOUCHER_CREATED = 'voucher.created';

    public const VOUCHER_ALTERED = 'voucher.altered';

    public const VOUCHER_CANCELLED = 'voucher.cancelled';

    public const PAYMENT_RECORDED = 'payment.recorded';

    public const PARTY_OUTSTANDING_CHANGED = 'party.outstanding.changed';

    /** event key => human description (the UI checkbox label + the docs table). */
    public const CATALOG = [
        self::VOUCHER_CREATED => 'A voucher was posted (any type).',
        self::VOUCHER_ALTERED => 'A voucher was altered.',
        self::VOUCHER_CANCELLED => 'A voucher was cancelled.',
        self::PAYMENT_RECORDED => 'A receipt or payment voucher was posted in your books.',
        self::PARTY_OUTSTANDING_CHANGED => "A party's bill-wise outstanding changed.",
        self::PING => 'A test event you trigger yourself.',
    ];

    /** Every subscribable event, wildcard excluded (ping is subscribable but always deliverable). */
    public static function all(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function isValid(string $event): bool
    {
        return $event === self::WILDCARD || array_key_exists($event, self::CATALOG);
    }

    /**
     * Keep only real events, de-duplicated. An unknown string is DROPPED — a subscription can only
     * ever hold events this catalog defines.
     */
    public static function sanitize(array $events): array
    {
        $clean = [];

        foreach ($events as $e) {
            if (! is_string($e)) {
                continue;
            }
            $e = trim($e);
            if (self::isValid($e) && ! in_array($e, $clean, true)) {
                $clean[] = $e;
            }
        }

        return $clean;
    }
}
