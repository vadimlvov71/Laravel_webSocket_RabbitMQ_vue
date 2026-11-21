<?php

namespace App\Http\Requests;

use App\Enums\CommentSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Параметры списка: sort (user_name|email|created_at), dir (asc|desc), page.
 *
 * Правил валидации нет намеренно: неверное значение заменяется значением
 * по умолчанию, а не превращается в 422 (это просто ссылка со странными параметрами).
 */
class ListCommentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function sort(): CommentSort
    {
        // ?sort[]=x приходит массивом, а приведение массива к строке даёт 500
        $value = $this->query('sort');

        return (is_string($value) ? CommentSort::tryFrom($value) : null) ?? CommentSort::CreatedAt;
    }

    /** По умолчанию по убыванию (LIFO). */
    public function dir(): string
    {
        return $this->query('dir') === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int
    {
        return max(1, (int) $this->query('page', 1));
    }
}
