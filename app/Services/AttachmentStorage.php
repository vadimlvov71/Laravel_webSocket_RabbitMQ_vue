<?php

namespace App\Services;

use App\Enums\AttachmentType;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Сохраняет вложение комментария на диск public.
 *
 * Изображение всегда перекодируется через GD: так из файла пропадает всё, кроме
 * пикселей (EXIF, вставленный скрипт, «полиглот» PNG+HTML), а размер приводится
 * к рамке MAX_WIDTH x MAX_HEIGHT. Текстовый файл сохраняется как есть,
 * на фронтенде он выводится только как текст.
 */
class AttachmentStorage
{
    public const MAX_WIDTH = 320;

    public const MAX_HEIGHT = 240;

    private const DIR = 'attachments';

    /** Тип изображения из getimagesize() → расширение сохранённого файла. */
    private const IMAGE_EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
    ];

    /**
     * @return array{attachment_path: string, attachment_type: string, attachment_name: string}
     *
     * @throws ValidationException файл прошёл валидацию, но обработать его не удалось
     */
    public function store(UploadedFile $file): array
    {
        // файл уже прошёл CommentAttachment, поэтому тип известен
        $type = AttachmentType::detect($file) ?? throw new \InvalidArgumentException('Attachment was not validated');

        try {
            $path = $type === AttachmentType::Image ? $this->storeImage($file) : $this->storeText($file);
        } catch (Throwable $e) {
            Log::channel('uploads')->error('Attachment processing failed', [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'attachment' => 'The file could not be processed. Please try another one.',
            ]);
        }

        return [
            'attachment_path' => $path,
            'attachment_type' => $type->value,
            // имя только для показа; путь к файлу из него не строится
            'attachment_name' => Str::limit($file->getClientOriginalName(), 200, ''),
        ];
    }

    /** Удаляет файл, если комментарий так и не сохранился. */
    public function delete(string $path): void
    {
        Storage::disk('public')->delete($path);
    }

    /**
     * Новые размеры, вписанные в рамку с сохранением пропорций. Увеличения нет.
     *
     * @return array{0: int, 1: int}
     */
    public static function fit(int $width, int $height): array
    {
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function storeText(UploadedFile $file): string
    {
        return $this->put($file->get(), 'txt');
    }

    private function storeImage(UploadedFile $file): string
    {
        $info = getimagesize($file->getRealPath());
        $extension = self::IMAGE_EXTENSIONS[$info[2] ?? null] ?? throw new \RuntimeException('Unsupported image type');

        $source = imagecreatefromstring($file->get()) ?: throw new \RuntimeException('GD could not read the image');
        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->applyExifOrientation($source, $file->getRealPath());
        }
        [$width, $height] = self::fit(imagesx($source), imagesy($source));
        $target = $this->resize($source, $width, $height, $info[2]);

        ob_start();
        match ($info[2]) {
            IMAGETYPE_JPEG => imagejpeg($target, null, 85),
            IMAGETYPE_PNG => imagepng($target, null, 6),
            IMAGETYPE_GIF => imagegif($target),
        };

        return $this->put((string) ob_get_clean(), $extension);
    }

    /**
     * GD не читает EXIF, а перекодирование его удаляет: без поворота фото с телефона
     * «легло бы на бок». Обычно поворачивает уже браузер (imageResize.js), это запасной путь.
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }

    private function resize(GdImage $source, int $width, int $height, int $type): GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        // прозрачность PNG и GIF без этого превратилась бы в чёрный фон
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    private function put(string $contents, string $extension): string
    {
        $path = self::DIR.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('public')->put($path, $contents)) {
            throw new \RuntimeException("Could not write {$path}");
        }

        return $path;
    }
}
