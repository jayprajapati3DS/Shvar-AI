<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * The outcome of one send attempt.
 *
 * Immutable, and deliberately not an Eloquent model - it describes one attempt,
 * whereas the draft's sent_at/delivery_mode columns are the persisted record.
 */
final readonly class EmailSendResult
{
    public function __construct(
        public bool $delivered,
        public string $mode,
        public string $recipient,
        public string $subject,
        public \DateTimeImmutable $at,
        public bool $simulated,
        public ?string $error = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'delivered' => $this->delivered,
            'mode' => $this->mode,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'at' => $this->at->format(DATE_ATOM),
            'simulated' => $this->simulated,
            'error' => $this->error,
        ];
    }
}
