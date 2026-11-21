<?php

namespace Tests\Feature;

use App\Events\CommentCreated;
use App\Models\Comment;
use Database\Seeders\CommentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Живые обновления: событие для WebSocket, поиск страницы ветки, демо-данные.
 */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_is_not_sent_back_to_author_socket(): void
    {
        Event::fake([CommentCreated::class]);
        Http::fake(['www.google.com/recaptcha/*' => Http::response(['success' => true])]);

        $this->postJson('/comments', [
            'user_name' => 'John',
            'email' => 'john@example.com',
            'text' => 'Hello',
            'captcha' => 'token',
        ], ['X-Socket-ID' => '1234.5678'])->assertCreated();

        // socket заполнен: Reverb исключит это соединение из рассылки
        Event::assertDispatched(CommentCreated::class, fn (CommentCreated $e) => $e->socket === '1234.5678');
    }

    public function test_event_payload_has_names_but_no_private_data(): void
    {
        $parent = $this->comment('anna_dev');
        $reply = $this->comment('bob', $parent);

        $payload = (new CommentCreated($reply))->broadcastWith();

        $this->assertSame([
            'id' => $reply->id,
            'parent_id' => $parent->id,
            'root_id' => $parent->id,
            'user_name' => 'bob',
            'parent_user_name' => 'anna_dev',
        ], $payload);
    }

    public function test_page_of_reply_in_old_thread(): void
    {
        config(['comments.per_page' => 2]);
        $roots = collect(range(1, 5))->map(fn (int $i) => $this->comment("user{$i}", null, now()->subDays(10 - $i)));
        $oldest = $roots->first();
        $reply = $this->comment('bob', $this->comment('carol', $oldest));

        // по дате по убыванию самый старый корень на последней, третьей странице
        $this->getJson("/comments/{$reply->id}/page")
            ->assertOk()
            ->assertExactJson(['page' => 3, 'root_id' => $oldest->id]);

        $this->getJson("/comments/{$reply->id}/page?sort=created_at&dir=asc")
            ->assertExactJson(['page' => 1, 'root_id' => $oldest->id]);
    }

    public function test_page_matches_list_for_equal_sort_values(): void
    {
        config(['comments.per_page' => 2]);
        // одинаковые имена: порядок решает id, как в paginateRoots()
        $same = collect(range(1, 5))->map(fn () => $this->comment('same'));

        foreach ($same as $comment) {
            $page = $this->getJson("/comments/{$comment->id}/page?sort=user_name&dir=asc")->json('page');

            $this->getJson("/comments?sort=user_name&dir=asc&page={$page}")
                ->assertJsonFragment(['id' => $comment->id]);
        }
    }

    public function test_page_of_unknown_comment_is_404(): void
    {
        $this->getJson('/comments/999/page')->assertNotFound();
    }

    public function test_seeder_creates_demo_threads(): void
    {
        $this->seed(CommentSeeder::class);

        $this->assertSame(CommentSeeder::ROOTS, Comment::whereNull('parent_id')->count());

        $names = array_column(CommentSeeder::AUTHORS, 0);
        $this->assertSame([], Comment::whereNotIn('user_name', $names)->pluck('user_name')->all());

        foreach (Comment::whereNotNull('parent_id')->with('parent')->get() as $reply) {
            $this->assertNull(Comment::find($reply->root_id)->parent_id, 'root_id must point to a root');
            $this->assertTrue($reply->created_at->gte($reply->parent->created_at), 'reply is newer than parent');
        }
    }

    private function comment(string $name, ?Comment $parent = null, $createdAt = null): Comment
    {
        $comment = new Comment([
            'parent_id' => $parent?->id,
            'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
            'user_name' => $name,
            'email' => "{$name}@example.com",
            'text' => 'text',
        ]);
        $comment->created_at = $createdAt ?? now();
        $comment->save();

        return $comment;
    }
}
