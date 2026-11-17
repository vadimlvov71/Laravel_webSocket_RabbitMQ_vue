<?php

namespace App\Rules;

use App\Services\CommentSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ValidCommentMarkup implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $html = CommentSanitizer::sanitize((string) $value);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
            return;
        }

        if (trim(strip_tags($html)) === '') {
            $fail('The :attribute must contain some text.');
        }
    }
}