<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

class Recaptcha implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $response = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => config('services.recaptcha.secret'),
                'response' => $value,
                'remoteip' => request()->ip(),
            ]);
        } catch (\Throwable) {
            $fail('Could not verify CAPTCHA, please try again.');
            return;
        }

        if (! $response->json('success')) {
            $fail('CAPTCHA verification failed.');
        }
    }
}
