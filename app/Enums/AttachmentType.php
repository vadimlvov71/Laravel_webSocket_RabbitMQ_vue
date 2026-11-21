<?php

namespace App\Enums;

use Illuminate\Http\UploadedFile;

enum AttachmentType: string
{
    case Image = 'image';
    case Text = 'text';

    /** Форматы изображений, которые принимаем и умеем перекодировать через GD. */
    public const IMAGE_FORMATS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];

    /**
     * Тип вложения по расширению И содержимому; null, если файл не подходит.
     *
     * Для текста проверяем только, что содержимое текстовое (text/*): finfo
     * называет .txt с HTML или кодом text/html, text/x-php и т.п., но это всё
     * равно текст, и показывается он только как текст.
     */
    public static function detect(UploadedFile $file): ?self
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'txt') {
            // finfo напрямую: getMimeType() у тестовых файлов Laravel берёт тип по имени
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

            return is_string($mime) && str_starts_with($mime, 'text/') ? self::Text : null;
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $info = @getimagesize($file->getRealPath());

            return $info !== false && in_array($info[2], self::IMAGE_FORMATS, true) ? self::Image : null;
        }

        return null;
    }
}
