<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\StoreCommentRequest;
use Illuminate\Support\Arr;

// Те же правила, что у формы на сайте, но без reCAPTCHA: клиента API
// подтверждает ключ с правом comments:write, а от спама защищает лимит на ключ.
// Отказы по вложению пишутся в журнал uploads так же, как у формы.
// PHPDoc класса попадает в публичную документацию OpenAPI, поэтому он на английском.

/** A new comment or a reply (with `parent_id`). */
class StoreCommentApiRequest extends StoreCommentRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['captcha']);
    }
}
