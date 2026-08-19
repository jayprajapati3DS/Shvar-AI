<?php

declare(strict_types=1);

namespace App\Services\Email\Outlook;

use RuntimeException;
use Throwable;

/**
 * Outlook could not be reached, or refused what was asked of it.
 *
 * Carries a user-safe message separately from the technical one, like the AI
 * exceptions do: a raw COM error reads as "Invoke() failed: Exception occurred.
 * Source: Microsoft Outlook" and tells nobody anything useful.
 */
class OutlookException extends RuntimeException
{
    public function __construct(
        string $technicalMessage,
        private readonly string $userMessage = 'Outlook could not be reached.',
        private readonly ?string $hint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($technicalMessage, previous: $previous);
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }

    /** The COM extension is not enabled in php.ini. */
    public static function extensionMissing(): self
    {
        return new self(
            'The com_dotnet extension is not loaded.',
            'This build of PHP cannot talk to Outlook.',
            'Add "extension=com_dotnet" to php.ini and restart the server. '
            .'The DLL ships with PHP for Windows; it is just switched off by default.',
        );
    }

    /** Windows-only, and this is not Windows. */
    public static function notWindows(): self
    {
        return new self(
            'Outlook automation requires Windows.',
            'Outlook integration only works on Windows.',
            'Use the SMTP transport instead.',
        );
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self(
            'Could not create Outlook.Application: '.$previous->getMessage(),
            'Outlook did not respond.',
            'Make sure the classic Outlook desktop app is installed and has a mail profile set up. '
            .'The new Outlook (the Store app) has no automation interface - only classic Outlook does.',
            $previous,
        );
    }
}
