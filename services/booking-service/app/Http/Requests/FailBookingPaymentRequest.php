<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailBookingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'failure_reason' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
