<?php

namespace Tests\Feature;

use App\Events\CommentCreated;
use App\Models\Comment;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /comments с вложением: уменьшение изображений, лимит текстового файла,
 * отказ для других типов и запись отказов в журнал uploads.
 */
class CommentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Event::fake([CommentCreated::class]);
        Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true])]);
    }

    #[DataProvider('images')]
    public function test_image_is_scaled_down_proportionally(string $name, int $w, int $h, int $expW, int $expH): void
    {
        $this->send(UploadedFile::fake()->image($name, $w, $h))->assertCreated();

        $comment = Comment::sole();
        $this->assertSame('image', $comment->attachment_type);
        $this->assertSame($name, $comment->attachment_name);
        Storage::disk('public')->assertExists($comment->attachment_path);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($comment->attachment_path));
        $this->assertSame([$expW, $expH], [$width, $height]);
        $this->assertSame(pathinfo($name, PATHINFO_EXTENSION), pathinfo($comment->attachment_path, PATHINFO_EXTENSION));
    }

    public static function images(): array
    {
        return [
            '4:3 jpg is fitted to the frame' => ['photo.jpg', 640, 480, 320, 240],
            'wide png is limited by width' => ['wide.png', 1000, 200, 320, 64],
            'tall gif is limited by height' => ['tall.gif', 300, 600, 120, 240],
            'small image is not enlarged' => ['small.png', 100, 50, 100, 50],
        ];
    }

    /** Фото «с телефона» 640x480 с EXIF Orientation=6 (повернуть на 90°) становится вертикальным. */
    public function test_jpeg_is_rotated_by_exif_orientation(): void
    {
        $this->send(UploadedFile::fake()->createWithContent('phone.jpg', $this->jpegWithOrientation(640, 480, 6)))
            ->assertCreated();

        $stored = Storage::disk('public')->get(Comment::sole()->attachment_path);
        $this->assertSame([180, 240], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_text_file_is_stored_as_is(): void
    {
        $content = str_repeat("line of text\n", 1000);

        $this->send(UploadedFile::fake()->createWithContent('notes.txt', $content))->assertCreated();

        $comment = Comment::sole();
        $this->assertSame('text', $comment->attachment_type);
        $this->assertSame($content, Storage::disk('public')->get($comment->attachment_path));
    }

    public function test_text_file_over_100_kb_is_rejected_and_logged(): void
    {
        Log::shouldReceive('channel')->with('uploads')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Attachment rejected'
                && $context['name'] === 'big.txt'
        );

        $this->send(UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 101 * 1024)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');

        $this->assertSame(0, Comment::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_other_file_types_are_rejected(): void
    {
        $this->send(UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');

        $this->assertSame(0, Comment::count());
    }

    public function test_image_with_wrong_extension_is_rejected(): void
    {
        $this->send(UploadedFile::fake()->image('photo.bmp', 10, 10))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }

    /** finfo называет такой файл text/html, но это текст, и он должен приниматься. */
    public function test_text_file_with_markup_is_accepted_as_text(): void
    {
        $this->send(UploadedFile::fake()->createWithContent('page.txt', '<html><script>alert(1)</script></html>'))
            ->assertCreated();

        $this->assertSame('text', Comment::sole()->attachment_type);
    }

    public function test_text_disguised_as_image_is_rejected(): void
    {
        $this->send(UploadedFile::fake()->createWithContent('photo.png', 'not really a png'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }

    public function test_image_disguised_as_text_is_rejected(): void
    {
        $png = UploadedFile::fake()->image('x.png', 10, 10)->get();

        $this->send(UploadedFile::fake()->createWithContent('notes.txt', $png))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }

    public function test_comment_without_attachment_still_works(): void
    {
        $this->send(null)->assertCreated();

        $this->assertNull(Comment::sole()->attachment_path);
    }

    public function test_attachment_is_returned_in_list(): void
    {
        $this->send(UploadedFile::fake()->image('photo.jpg', 50, 50))->assertCreated();
        $path = Comment::sole()->attachment_path;

        $this->getJson('/comments')
            ->assertOk()
            ->assertJsonPath('data.0.attachment.type', 'image')
            ->assertJsonPath('data.0.attachment.name', 'photo.jpg')
            ->assertJsonPath('data.0.attachment.url', Storage::disk('public')->url($path));
    }

    public function test_fit_keeps_proportions(): void
    {
        $this->assertSame([320, 240], AttachmentStorage::fit(3200, 2400));
        $this->assertSame([320, 180], AttachmentStorage::fit(1920, 1080));
        $this->assertSame([1, 240], AttachmentStorage::fit(1, 5000));
    }

    /** JPEG с минимальным APP1/EXIF-блоком, в котором только тег Orientation. */
    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));
        $jpeg = ob_get_clean();

        // TIFF little-endian: заголовок, IFD из одной записи 0x0112 (SHORT), конец IFD
        $tiff = "II*\x00".pack('V', 8).pack('v', 1)
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
        $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\x00\x00".$tiff;

        // сразу после маркера SOI (FFD8)
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function send(?UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this->post('/comments', array_filter([
            'user_name' => 'John',
            'email' => 'john@example.com',
            'text' => 'Hello',
            'captcha' => 'token',
            'attachment' => $file,
        ]), ['Accept' => 'application/json']);
    }
}
