<?php

namespace App\Http\Requests;

use App\Rules\CommentAttachment;
use App\Rules\Recaptcha;
use App\Rules\ValidCommentMarkup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_name' => ['required', 'string', 'max:64', 'regex:/^(?=(?:[^A-Za-z]*[A-Za-z]){2})[A-Za-z0-9_-]+$/'],
            'email'     => ['required', 'email:rfc', 'max:255'],
            'home_page' => ['nullable', 'url:http,https', 'max:255'],
            'text'      => ['required', 'string', 'max:5000', new ValidCommentMarkup()],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
            // тип по имени и содержимому проверяет CommentAttachment
            'attachment' => ['nullable', 'file', new CommentAttachment()],
            'captcha'   => ['required', 'string', new Recaptcha()],
        ];
    }

    public function messages(): array
    {
        return [
            'user_name.regex' => 'User Name may contain only Latin letters, digits, underscore and hyphen and must include at least two letters.',
            'attachment.file' => 'The file could not be uploaded.',
            'attachment.uploaded' => 'The file could not be uploaded.',
            'captcha.required' => 'Please confirm that you are not a robot.',
        ];
    }

    /** Отказы по вложению пишем в журнал загрузок, остальные ошибки формы не логируем. */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->get('attachment');
        $file = $this->file('attachment');

        if ($errors !== [] || ($this->has('attachment') && ! $file instanceof UploadedFile)) {
            Log::channel('uploads')->warning('Attachment rejected', [
                'errors' => $errors,
                'name' => $file?->getClientOriginalName(),
                'size' => $file?->getSize(),
                'mime' => $file?->isValid() ? $file->getMimeType() : null,
                // UPLOAD_ERR_* от PHP: превышен upload_max_filesize, файл пришёл не целиком и т.п.
                'upload_error' => $file?->isValid() === false ? $file->getErrorMessage() : null,
                'ip' => $this->ip(),
            ]);
        }

        parent::failedValidation($validator);
    }
}
