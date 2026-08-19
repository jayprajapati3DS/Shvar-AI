<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;

/**
 * Sends the user back where they were, not where the code finds convenient.
 *
 * Two habits this exists to break.
 *
 * FIRST: `back()` falls back to the site ROOT when it has no previous URL -
 * after a hard refresh, a fresh session, or any request without a referer. So
 * renaming a product could drop you on the dashboard, miles from what you were
 * doing. Every module now falls back to its own index instead, so a company
 * edit can never land you anywhere but companies.
 *
 * SECOND: create actions redirected to the new record's detail page. That reads
 * well in a tutorial and is wrong in practice: the create form is a modal ON the
 * list, so saving threw you off the list you were working through. Adding three
 * people to one company meant navigating back twice.
 *
 * The rule now: stay exactly where you were. Editing a lead from the lead list
 * keeps you on the list; adding a lead from a company page keeps you on that
 * company, because building out an account is the reason you were there.
 */
trait RedirectsToOrigin
{
    /**
     * Return to the page the action was performed from.
     *
     * @param  string  $fallbackRoute  The module's own index - used only when
     *                                 there is no usable previous page.
     * @param  array<string, mixed>|int|string  $parameters
     */
    protected function backTo(string $fallbackRoute, array|int|string $parameters = []): RedirectResponse
    {
        $previous = $this->previousUrl();

        return $previous === null
            ? redirect()->route($fallbackRoute, $parameters)
            : redirect()->to($previous);
    }

    /**
     * Return to the previous page unless it was the record just deleted.
     *
     * Deleting from a detail page has to go somewhere else - the page it came
     * from no longer exists. Deleting the same record from a list should leave
     * you on that list.
     *
     * @param  array<string, mixed>|int|string  $parameters
     */
    protected function backFromDelete(
        string $deletedUrl,
        string $fallbackRoute,
        array|int|string $parameters = [],
    ): RedirectResponse {
        $previous = $this->previousUrl();

        if ($previous === null || $this->samePath($previous, $deletedUrl)) {
            return redirect()->route($fallbackRoute, $parameters);
        }

        return redirect()->to($previous);
    }

    /**
     * The previous page, or null when there is not a usable one.
     *
     * The site root counts as "not usable": it is what Laravel invents when it
     * has nothing, so treating it as a real destination is what produced the
     * surprise dashboard redirects.
     */
    private function previousUrl(): ?string
    {
        $previous = url()->previous();
        $root = url('/');

        if ($previous === '' || $this->samePath($previous, $root)) {
            return null;
        }

        // Only ever bounce somewhere inside this application.
        return str_starts_with($previous, $root) ? $previous : null;
    }

    /** Compare two URLs by path, ignoring a query string or trailing slash. */
    private function samePath(string $a, string $b): bool
    {
        $normalise = static fn (string $url): string => rtrim(
            (string) parse_url($url, PHP_URL_PATH),
            '/',
        );

        return $normalise($a) === $normalise($b);
    }
}
