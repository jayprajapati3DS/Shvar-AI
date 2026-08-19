<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;

class BulkProductActionRequest extends BulkActionRequest
{
    protected function model(): string
    {
        return Product::class;
    }

    protected function table(): string
    {
        return 'products';
    }
}
