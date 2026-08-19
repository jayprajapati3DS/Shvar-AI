<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Company;

class BulkCompanyActionRequest extends BulkActionRequest
{
    protected function model(): string
    {
        return Company::class;
    }

    protected function table(): string
    {
        return 'companies';
    }
}
