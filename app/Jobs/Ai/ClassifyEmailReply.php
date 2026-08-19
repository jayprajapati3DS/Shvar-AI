<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Models\AiJob;
use App\Models\EmailReply;
use App\Services\Email\ReplyClassifier;

/**
 * Have the local model read one reply and suggest what to do next.
 *
 * The quickest of the four, and still worth queueing: half a minute is long
 * enough to be annoying when there are eight replies to get through, and
 * queueing them all at once means the worker chews through the batch while you
 * read the first one.
 *
 * Any follow-up it proposes arrives as a suggestion on the replies page. The
 * model does not reply to anybody and does not decide anything is handled.
 */
class ClassifyEmailReply extends TrackedAiJob
{
    protected function run(AiJob $job): array
    {
        $reply = EmailReply::find($job->subject_id);

        if ($reply === null) {
            return [
                'summary' => 'That reply was deleted before it could be read.',
                'level' => 'info',
            ];
        }

        $outcome = app(ReplyClassifier::class)->classify($reply);

        return [
            'summary' => sprintf(
                'Read as "%s".%s',
                $outcome['reply']->classification?->value ?? 'Unclear',
                $outcome['task'] !== null
                    ? ' A follow-up was suggested - review it before relying on it.'
                    : ' No follow-up suggested.',
            ),
        ];
    }
}
