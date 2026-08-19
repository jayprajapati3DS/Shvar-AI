<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:10240',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
            ],
            'import_duplicates' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Upload a .csv file.',
            'file.max' => 'The file must be 10 MB or smaller.',
        ];
    }
}
