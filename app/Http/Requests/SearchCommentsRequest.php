<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchCommentsRequest extends FormRequest
{
    public const MIN_LENGTH = 2;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:100'],
        ];
    }

    public function term(): string
    {
        return trim($this->validated('q'));
    }
}
