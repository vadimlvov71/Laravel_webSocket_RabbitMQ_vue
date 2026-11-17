<?php

namespace Tests\Unit;

use App\Http\Requests\StoreCommentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Валидация формы добавления комментария (правила StoreCommentRequest).
 * БД не нужна: проверка капчи подменяется через Http::fake().
 */
class StoreCommentRequestTest extends TestCase
{
    public function test_valid_data_passes(): void
    {
        $this->assertTrue($this->validator()->passes());
    }

    public function test_home_page_is_optional(): void
    {
        $this->assertTrue($this->validator(['home_page' => null])->passes());
        $this->assertTrue($this->validator(['home_page' => 'https://example.com/page'])->passes());
    }

    public function test_allowed_tags_in_text_pass(): void
    {
        $text = '<strong>bold</strong> <i>it</i> <code>x</code> <a href="https://example.com" title="t">link</a>';

        $this->assertTrue($this->validator(['text' => $text])->passes());
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_field_fails(string $field, ?string $value): void
    {
        $validator = $this->validator([$field => $value]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey($field, $validator->errors()->toArray());
    }

    public static function invalidFields(): array
    {
        return [
            'user_name empty' => ['user_name', ''],
            'user_name with space' => ['user_name', 'Bad Name'],
            'user_name with symbols' => ['user_name', 'bad_name!'],
            'user_name cyrillic' => ['user_name', 'Иван'],
            'email empty' => ['email', ''],
            'email invalid' => ['email', 'not-an-email'],
            'home_page not a url' => ['home_page', 'just text'],
            'home_page not http' => ['home_page', 'ftp://example.com'],
            'text empty' => ['text', ''],
            'text unclosed tag' => ['text', '<strong>unclosed'],
            'text wrong nesting' => ['text', '<strong><i>x</strong></i>'],
            'text javascript link' => ['text', '<a href="javascript:alert(1)">x</a>'],
            'text only tags' => ['text', '<i></i>'],
            'captcha empty' => ['captcha', ''],
        ];
    }

    public function test_rejected_captcha_fails(): void
    {
        $validator = $this->validator([], captchaOk: false);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('captcha', $validator->errors()->toArray());
    }

    private function validator(array $overrides = [], bool $captchaOk = true): ValidatorInstance
    {
        Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => $captchaOk])]);

        $request = new StoreCommentRequest();

        $data = array_merge([
            'user_name' => 'John123',
            'email' => 'john@example.com',
            'home_page' => null,
            'text' => 'Hello <strong>world</strong>',
            'captcha' => 'token',
        ], $overrides);

        return Validator::make($data, $request->rules(), $request->messages());
    }
}
