<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Contact;

class BulkContactActionRequest extends BulkActionRequest
{
    protected function model(): string
    {
        return Contact::class;
    }

    protected function table(): string
    {
        return 'contacts';
    }
}
