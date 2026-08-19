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

        /**
         * True when the message was handed to something else to finish.
         *
         * The Outlook transport populates a compose window and stops; the human
         * presses Send. At that moment nothing has been sent, so `delivered` is
         * false and the draft becomes Queued rather than Sent. Saying otherwise
         * would put a lie in the activity timeline.
         */
        public bool $handedOff = false,

        /** A transport-specific handle - the Outlook EntryID, for reconciling later. */
        public ?string $reference = null,
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
            'handed_off' => $this->handedOff,
            'reference' => $this->reference,
        ];
    }
}
