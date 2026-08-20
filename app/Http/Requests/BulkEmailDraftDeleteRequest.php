<?php

declare(strict_types=1);

namespace App\Http\Requests;

class BulkEmailDraftDeleteRequest extends BulkDeleteRequest
{
    protected function table(): string
    {
        return 'email_drafts';
    }
}
