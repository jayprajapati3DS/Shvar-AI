<?php

declare(strict_types=1);

namespace App\Services\Email\Outlook;

use COM;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Drives the classic Outlook desktop app over COM/MAPI.
 *
 * The ONLY class in this application that touches COM. Everything above it
 * depends on OutlookGatewayInterface, so the rest of the email layer neither
 * knows nor cares that Outlook exists.
 *
 * WHY THIS RATHER THAN SMTP OR GRAPH:
 *
 *   - No credential. It uses the Outlook session already signed in on this
 *     desktop, so there is no password to store, encrypt or leak.
 *   - No SMTP AUTH. Microsoft 365 tenants routinely have it disabled, which is
 *     the wall most people hit first.
 *   - No OAuth, no app registration, no consent screen, no tenant admin.
 *   - Nothing crosses the network from this application. COM is local IPC to a
 *     process on the same machine; Outlook itself does the talking to Exchange,
 *     exactly as it does when you use it by hand.
 *
 * WHAT IT NEEDS:
 *
 *   - Windows, and the com_dotnet extension enabled in php.ini.
 *   - CLASSIC Outlook, with a configured mail profile. The new Outlook (olk,
 *     the Store app) exposes no automation interface at all - if that is the
 *     only one installed, this cannot work and SMTP is the alternative.
 *
 * Every COM call is wrapped: a dead Outlook, a locked profile or a security
 * prompt must surface as a friendly message, never as a raw Invoke() failure.
 */
class ComOutlookGateway implements OutlookGatewayInterface
{
    /** olFolderSentMail */
    private const FOLDER_SENT = 5;

    /** olFolderInbox */
    private const FOLDER_INBOX = 6;

    /** olMailItem */
    private const ITEM_MAIL = 0;

    /**
     * The codepage COM strings are converted with.
     *
     * Everything in this application is UTF-8; com_dotnet assumes ANSI unless
     * told otherwise. See application().
     */
    private const CP_UTF8 = 65001;

    private ?COM $outlook = null;

    public function isAvailable(): bool
    {
        try {
            $this->application();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $unavailable = fn (string $message): array => [
            'available' => false,
            'message' => $message,
            'version' => null,
            'account' => null,
            'inbox_count' => null,
            'sent_count' => null,
        ];

        if (! extension_loaded('com_dotnet')) {
            return $unavailable('PHP cannot talk to Outlook: the com_dotnet extension is not loaded.');
        }

        if (! $this->isWindows()) {
            return $unavailable('Outlook integration only works on Windows.');
        }

        try {
            $outlook = $this->application();
            $namespace = $outlook->GetNamespace('MAPI');

            return [
                'available' => true,
                'message' => 'Connected to the Outlook desktop app on this machine.',
                'version' => (string) $outlook->Version,
                'account' => $this->accountName($namespace),
                'inbox_count' => (int) $namespace->GetDefaultFolder(self::FOLDER_INBOX)->Items->Count,
                'sent_count' => (int) $namespace->GetDefaultFolder(self::FOLDER_SENT)->Items->Count,
            ];
        } catch (Throwable $e) {
            return $unavailable(
                'Outlook did not respond. Make sure the classic Outlook desktop app is installed '
                .'with a mail profile. The new Outlook has no automation interface.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function displayDraft(array $message): string
    {
        try {
            $mail = $this->application()->CreateItem(self::ITEM_MAIL);

            $mail->To = (string) $message['to'];
            $mail->Subject = (string) $message['subject'];

            // Plain text. Body, not HTMLBody - nothing this application produces
            // is markup, and setting HTMLBody would invite a mail client to
            // interpret whatever the model wrote.
            $mail->Body = (string) $message['body'];

            // Saved first so it gets a stable EntryID we can reconcile against
            // later. A displayed-but-unsaved item has none.
            $mail->Save();
            $entryId = (string) $mail->EntryID;

            // Opens the compose window. Deliberately NOT ->Send(): the human
            // presses Send in Outlook, which is the last checkpoint and also
            // lets Outlook apply its own signature rules and transport rules.
            $mail->Display();

            return $entryId;
        } catch (OutlookException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OutlookException(
                'Failed to create the Outlook message: '.$e->getMessage(),
                'Outlook would not open the message.',
                'Check that Outlook is running and not showing a dialog that needs dismissing.',
                $e,
            );
        }
    }

    /**
     * @param  list<string>  $addresses
     * @return list<array<string, mixed>>
     */
    public function inboxFrom(array $addresses, DateTimeInterface $since, int $limit = 100): array
    {
        if ($addresses === []) {
            return [];
        }

        $wanted = array_flip(array_map(mb_strtolower(...), $addresses));

        try {
            $items = $this->application()
                ->GetNamespace('MAPI')
                ->GetDefaultFolder(self::FOLDER_INBOX)
                ->Items;

            // Sorted newest first, then narrowed by date BEFORE any per-item
            // work. On a 5,800-message mailbox the difference between filtering
            // in MAPI and filtering in PHP is seconds versus minutes.
            $items->Sort('[ReceivedTime]', true);

            $filtered = $items->Restrict(sprintf(
                "[ReceivedTime] >= '%s'",
                $since->format('m/d/Y H:i A'),
            ));

            $found = [];
            $scanned = 0;

            // Iterating by index rather than foreach: the COM collection is
            // 1-based and foreach over it is unreliable across Outlook builds.
            $count = (int) $filtered->Count;

            for ($i = 1; $i <= $count; $i++) {
                if (count($found) >= $limit || $scanned >= 2000) {
                    break;
                }

                $scanned++;

                try {
                    $item = $filtered->Item($i);

                    // Non-mail items (meeting requests, reports) have no sender
                    // address and are not replies.
                    $address = $this->senderAddress($item);

                    if ($address === null || ! isset($wanted[mb_strtolower($address)])) {
                        continue;
                    }

                    $found[] = [
                        'entry_id' => (string) $item->EntryID,
                        'conversation_id' => $this->safeString($item, 'ConversationID'),
                        'from_address' => $address,
                        'from_name' => $this->safeString($item, 'SenderName'),
                        'subject' => $this->utf8((string) $item->Subject),
                        'body' => $this->utf8((string) $item->Body),
                        'received_at' => $this->toDateTime($item->ReceivedTime),
                    ];
                } catch (Throwable) {
                    // One unreadable item must not abort the whole sync.
                    continue;
                }
            }

            return $found;
        } catch (OutlookException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OutlookException(
                'Failed to read the Outlook inbox: '.$e->getMessage(),
                'Could not read your Outlook inbox.',
                'Make sure Outlook is running and signed in.',
                $e,
            );
        }
    }

    public function wasSent(string $entryId): bool
    {
        if ($entryId === '') {
            return false;
        }

        try {
            $namespace = $this->application()->GetNamespace('MAPI');

            // GetItemFromID throws when the item has moved - which is exactly
            // what happens when Outlook sends it and relocates it to Sent
            // Items, so a throw here is not conclusive on its own.
            $item = $namespace->GetItemFromID($entryId);

            // MailItem.Sent is the authoritative answer and the only one that
            // is actually a boolean.
            //
            // This USED to test SentOn for emptiness, which was wrong in the
            // worst possible direction: an unsent draft reports SentOn as
            // '01-01-4501' - Outlook's null-date sentinel, a perfectly
            // non-empty string - so every queued draft looked sent. That would
            // have promoted drafts to Sent that were still sitting unsent in a
            // compose window, which is precisely the lie Queued exists to
            // prevent. Found by checking a real draft rather than trusting the
            // property name.
            $sent = $item->Sent;

            if (is_bool($sent)) {
                return $sent;
            }

            // Older builds can hand back a VARIANT rather than a bool. Fall
            // back to SentOn, rejecting the 4501 sentinel explicitly.
            $sentOn = $this->safeString($item, 'SentOn');

            return $sentOn !== null
                && $sentOn !== ''
                && ! str_contains($sentOn, '4501');
        } catch (Throwable) {
            return false;
        }
    }

    /* ---------------------------------------------------------------------- */

    /**
     * The Outlook application object, created once per request.
     *
     * `new COM` attaches to a running Outlook if there is one and starts it if
     * there is not - which is why the caller never has to care whether the user
     * happens to have it open.
     */
    private function application(): COM
    {
        if ($this->outlook !== null) {
            return $this->outlook;
        }

        if (! extension_loaded('com_dotnet')) {
            throw OutlookException::extensionMissing();
        }

        if (! $this->isWindows()) {
            throw OutlookException::notWindows();
        }

        try {
            // CP_UTF8 (65001) is not optional. PHP strings here are UTF-8, but
            // com_dotnet converts them to COM BSTRs using com.code_page, which
            // is unset by default and falls back to the system ANSI codepage.
            // The result is silent mojibake in the message body: an apostrophe
            // arrives as "â€™", an en dash as "â€\"", a non-breaking space as
            // "Â". Nothing errors - the email just looks broken to whoever
            // receives it.
            //
            // Passed per-instance rather than set globally in php.ini, so the
            // fix travels with the code instead of depending on how this
            // machine happens to be configured.
            return $this->outlook = new COM('Outlook.Application', null, self::CP_UTF8);
        } catch (Throwable $e) {
            throw OutlookException::unreachable($e);
        }
    }

    /**
     * Whose mailbox this is, if Outlook will say.
     *
     * Namespace->CurrentUser goes through Outlook's Object Model Guard, the
     * security layer that gates address-book access - and on this machine it
     * returns 0x80004004 "Operation aborted" outright. Reading folders is
     * unaffected, so a blocked name must not take the whole connection down
     * with it: the account is a nicety, the mailbox is the feature.
     *
     * Session->Accounts is asked first because it is not guarded.
     */
    private function accountName(mixed $namespace): ?string
    {
        try {
            $accounts = $namespace->Accounts;

            if ((int) $accounts->Count > 0) {
                $account = $accounts->Item(1);

                foreach (['SmtpAddress', 'DisplayName'] as $property) {
                    $value = $this->safeString($account, $property);

                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            }
        } catch (Throwable) {
            // Fall through.
        }

        try {
            return (string) $namespace->CurrentUser->Name;
        } catch (Throwable) {
            return null;
        }
    }

    private function isWindows(): bool
    {
        return str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
    }

    /**
     * The sender's SMTP address.
     *
     * SenderEmailAddress holds an X.500 distinguished name rather than an SMTP
     * address for anything sent from inside the same Exchange organisation, so
     * the PropertyAccessor is consulted for the real one. That is not an edge
     * case - it is every internal colleague.
     */
    private function senderAddress(mixed $item): ?string
    {
        try {
            $type = $this->safeString($item, 'SenderEmailType');
            $address = $this->safeString($item, 'SenderEmailAddress');

            if ($type === 'EX') {
                // PR_SENT_REPRESENTING_SMTP_ADDRESS
                $smtp = $item->PropertyAccessor->GetProperty(
                    'http://schemas.microsoft.com/mapi/proptag/0x5D0A001F'
                );

                if (is_string($smtp) && $smtp !== '') {
                    return $smtp;
                }
            }

            return $address !== '' ? $address : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeString(mixed $item, string $property): ?string
    {
        try {
            $value = $item->{$property};

            return $value === null ? null : $this->utf8((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Force text out of Outlook into valid UTF-8.
     *
     * MAPI hands back Windows-1252 for anything typed with a smart quote, an
     * en-dash, an accented name or a non-breaking space - which is most real
     * business email. Those bytes are not valid UTF-8, and the first thing that
     * notices is json_encode inside the HTTP client, which throws
     * "Malformed UTF-8 characters" from somewhere that explains nothing.
     *
     * Found the hard way: classifying a genuine 2.4 KB reply blew up here.
     *
     * Scrubbed at the boundary where the bad bytes enter, so nothing
     * downstream - the database, the model, the browser - has to think about it.
     */
    private function utf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Windows-1252 first: it is what Outlook actually emits, and it is a
        // superset of Latin-1 over the bytes that matter here.
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

        if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }

        // Last resort: drop whatever still will not decode rather than hand a
        // broken string on.
        return (string) @iconv('UTF-8', 'UTF-8//IGNORE', $value);
    }

    private function toDateTime(mixed $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable((string) $value);
        } catch (Throwable) {
            return new DateTimeImmutable;
        }
    }
}
