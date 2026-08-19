<?php

declare(strict_types=1);

namespace App\Services\Email\Outlook;

/**
 * The seam between this application and the Outlook desktop app.
 *
 * Everything that touches COM lives behind this interface and nowhere else.
 * That is not architectural taste - it is the only way the test suite can cover
 * Outlook behaviour at all. COM needs Windows, a registered classic Outlook, a
 * configured MAPI profile and a signed-in user; a test suite that required all
 * four would not run in CI, would not run on a colleague's laptop, and would be
 * quietly skipped until it rotted.
 *
 * So: ComOutlookGateway does the real work, FakeOutlookGateway answers from an
 * array, and every service above this line is tested against the fake.
 *
 * PRIVACY: this talks to a process on the same machine over local COM. There is
 * no network call, no cloud endpoint, and no credential - it uses the Outlook
 * session already signed in on this desktop.
 */
interface OutlookGatewayInterface
{
    /** Whether Outlook can be reached right now. Never throws. */
    public function isAvailable(): bool;

    /**
     * A snapshot for the settings screen: version, profile, folder counts.
     *
     * Never throws - an unreachable Outlook returns a status saying so, because
     * the settings page must render whether or not Outlook is running.
     *
     * @return array{
     *     available: bool,
     *     message: string,
     *     version: string|null,
     *     account: string|null,
     *     inbox_count: int|null,
     *     sent_count: int|null
     * }
     */
    public function status(): array;

    /**
     * Create a message in Outlook and open it for the user to review and send.
     *
     * Deliberately does NOT send. The message is populated and displayed; the
     * human presses Send in Outlook. That is the last human checkpoint, and it
     * also means the sent copy lands in the real Sent Items with whatever
     * Outlook itself adds - disclaimers, transport rules, the lot.
     *
     * @param  array{to: string, to_name?: string|null, subject: string, body: string}  $message
     * @return string the Outlook EntryID of the created item
     *
     * @throws OutlookException
     */
    public function displayDraft(array $message): string;

    /**
     * Messages received from any of the given addresses, newest first.
     *
     * @param  list<string>  $addresses  The CRM contact addresses to look for.
     * @param  \DateTimeInterface  $since  Ignore anything older.
     * @param  int  $limit  Hard cap on how many messages are read.
     * @return list<array{
     *     entry_id: string,
     *     conversation_id: string|null,
     *     from_address: string,
     *     from_name: string|null,
     *     subject: string,
     *     body: string,
     *     received_at: \DateTimeImmutable
     * }>
     *
     * @throws OutlookException
     */
    public function inboxFrom(array $addresses, \DateTimeInterface $since, int $limit = 100): array;

    /**
     * Whether a message with this conversation appears in Sent Items.
     *
     * Used to reconcile a draft handed to Outlook: once the user actually
     * presses Send, the copy shows up here and the draft moves from Queued to
     * Sent. Until then it stays Queued, because nothing was sent.
     */
    public function wasSent(string $entryId): bool;
}
