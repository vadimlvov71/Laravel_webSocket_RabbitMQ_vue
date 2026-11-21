<?php

namespace App\Rules;

use App\Enums\AttachmentType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Вложение комментария: изображение JPG/GIF/PNG или текстовый файл TXT.
 *
 * Тип определяет AttachmentType::detect по расширению и содержимому, а не по
 * заголовку Content-Type от клиента. Лимиты зависят от типа.
 */
class CommentAttachment implements ValidationRule
{
    public const TEXT_MAX_KB = 100;

    /** Исходное изображение до уменьшения; больше и не нужно для картинки 320x240. */
    public const IMAGE_MAX_KB = 5120;

    /** GD держит распакованный кадр в памяти (~5 байт на пиксель), отсюда предел. */
    public const IMAGE_MAX_SIDE = 5000;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $type = AttachmentType::detect($value);
        $kb = $value->getSize() / 1024;

        if ($type === null) {
            $fail('Only JPG, GIF, PNG images and TXT files are allowed.');
        } elseif ($type === AttachmentType::Text && $kb > self::TEXT_MAX_KB) {
            $fail('A text file must not be larger than '.self::TEXT_MAX_KB.' KB.');
        } elseif ($type === AttachmentType::Image && $kb > self::IMAGE_MAX_KB) {
            $fail('An image must not be larger than '.(self::IMAGE_MAX_KB / 1024).' MB.');
        } elseif ($type === AttachmentType::Image) {
            [$width, $height] = getimagesize($value->getRealPath());
            if (max($width, $height) > self::IMAGE_MAX_SIDE) {
                $fail('The image must not be larger than '.self::IMAGE_MAX_SIDE.' px on any side.');
            }
        }
    }
}
