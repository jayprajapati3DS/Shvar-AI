<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Lead;

class BulkLeadActionRequest extends BulkActionRequest
{
    protected function model(): string
    {
        return Lead::class;
    }

    protected function table(): string
    {
        return 'leads';
    }
}
