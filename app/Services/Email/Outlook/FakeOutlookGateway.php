<?php

declare(strict_types=1);

namespace App\Services\Email\Outlook;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * An Outlook that answers from an array.
 *
 * Bound in tests so the whole email layer above the COM boundary can be covered
 * without Windows, without a MAPI profile and without a signed-in user. Every
 * test in the suite that involves Outlook uses this; none of them touch COM.
 *
 * Also usable to demonstrate the workflow on a machine with no Outlook at all.
 */
class FakeOutlookGateway implements OutlookGatewayInterface
{
    /** @var list<array<string, mixed>> */
    private array $inbox = [];

    /** @var list<array<string, mixed>> */
    public array $displayed = [];

    /** @var array<string, bool> */
    private array $sent = [];

    private bool $available = true;

    private ?OutlookException $throws = null;

    public function __construct(private readonly string $account = 'Test User') {}

    /* ------------------------------------------------------------------ */
    /* Arrangement */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $message */
    public function withInboxMessage(array $message): self
    {
        $this->inbox[] = [
            'entry_id' => $message['entry_id'] ?? 'entry-'.count($this->inbox),
            'conversation_id' => $message['conversation_id'] ?? null,
            'from_address' => $message['from_address'],
            'from_name' => $message['from_name'] ?? null,
            'subject' => $message['subject'] ?? 'A subject',
            'body' => $message['body'] ?? 'A body.',
            'received_at' => $message['received_at'] ?? new DateTimeImmutable,
        ];

        return $this;
    }

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    public function throwing(OutlookException $e): self
    {
        $this->throws = $e;

        return $this;
    }

    /** Mark an entry id as having actually been sent by the user. */
    public function markSent(string $entryId): self
    {
        $this->sent[$entryId] = true;

        return $this;
    }

    /* ------------------------------------------------------------------ */
    /* The interface */
    /* ------------------------------------------------------------------ */

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->available
            ? [
                'available' => true,
                'message' => 'Connected to the Outlook desktop app on this machine.',
                'version' => '16.0.0.0',
                'account' => $this->account,
                'inbox_count' => count($this->inbox),
                'sent_count' => count($this->sent),
            ]
            : [
                'available' => false,
                'message' => 'Outlook did not respond.',
                'version' => null,
                'account' => null,
                'inbox_count' => null,
                'sent_count' => null,
            ];
    }

    /** @param array<string, mixed> $message */
    public function displayDraft(array $message): string
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $this->displayed[] = $message;

        return 'displayed-entry-'.count($this->displayed);
    }

    /**
     * @param  list<string>  $addresses
     * @return list<array<string, mixed>>
     */
    public function inboxFrom(array $addresses, DateTimeInterface $since, int $limit = 100): array
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $wanted = array_flip(array_map(mb_strtolower(...), $addresses));

        return array_values(array_slice(
            array_filter(
                $this->inbox,
                fn (array $m) => isset($wanted[mb_strtolower((string) $m['from_address'])])
                    && $m['received_at'] >= $since,
            ),
            0,
            $limit,
        ));
    }

    public function wasSent(string $entryId): bool
    {
        return $this->sent[$entryId] ?? false;
    }
}
