<?php

namespace App\Http\Requests;

use App\Rules\Recaptcha;
use App\Rules\ValidCommentMarkup;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            'email'     => ['required', 'email:rfc', 'max:255'],
            'home_page' => ['nullable', 'url:http,https', 'max:255'],
            'text'      => ['required', 'string', 'max:5000', new ValidCommentMarkup],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
            'captcha'   => ['required', 'string', new Recaptcha],
        ];
    }

    public function messages(): array
    {
        return [
            'user_name.regex' => 'User Name may contain only Latin letters and digits.',
            'captcha.required' => 'Please confirm that you are not a robot.',
        ];
    }
}