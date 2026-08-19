<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('products', 'name')->ignore($productId),
            ],
            'category' => ['nullable', 'string', 'max:180'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'detailed_description' => ['nullable', 'string', 'max:20000'],
            'target_customer' => ['nullable', 'string', 'max:20000'],
            'target_specialty' => ['nullable', 'string', 'max:20000'],
            'key_features' => ['nullable', 'string', 'max:20000'],
            'value_proposition' => ['nullable', 'string', 'max:20000'],
            'sales_notes' => ['nullable', 'string', 'max:20000'],
            'active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }
}
