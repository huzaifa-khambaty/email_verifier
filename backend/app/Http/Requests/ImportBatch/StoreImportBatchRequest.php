<?php

namespace App\Http\Requests\ImportBatch;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by auth:sanctum at the route level
    }

    public function rules(): array
    {
        return [
            // Up to 512MB — a 6.5M-row "First Name,Last Name,Email" CSV is
            // roughly this order of magnitude. mimes:csv,txt since some
            // OSes/exports label CSVs as text/plain.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:524288'],
        ];
    }
}
