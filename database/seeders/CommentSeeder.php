<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Services\CommentCache;
use App\Services\CommentSearch;
use App\Services\CommentSanitizer;
use Illuminate\Database\Seeder;

/**
 * Демо-данные: несколько постоянных авторов, ветки ответов до 6 уровней,
 * даты за последний месяц. Корней больше, чем помещается на страницу,
 * чтобы сразу были видны пагинация и сортировка.
 *
 * php artisan db:seed --class=CommentSeeder
 */
class CommentSeeder extends Seeder
{
    public const ROOTS = 40;

    /** Постоянные имена удобнее для демо в двух окнах, чем случайные строки. */
    public const AUTHORS = [
        ['anna_dev', 'anna@example.com', 'https://anna.example.com'],
        ['bob', 'bob@example.com', null],
        ['carol-qa', 'carol@example.com', null],
        ['dmitry', 'dmitry@example.com', 'https://dmitry.example.org'],
        ['eva_k', 'eva@example.com', null],
        ['frank99', 'frank@example.com', null],
    ];

    public function run(): void
    {
        for ($i = 0; $i < self::ROOTS; $i++) {
            $root = $this->make(null, fake()->dateTimeBetween('-30 days', '-1 day'));
            $this->replies($root, random_int(0, 3), 1);
        }

        // DatabaseSeeder отключает события моделей (WithoutModelEvents),
        // поэтому сброс кэша списка из Comment::created здесь не сработает
        app(CommentCache::class)->flush();

        // по той же причине задачи индексации не отправлялись: загружаем индекс целиком.
        // Elasticsearch может быть не запущен, тогда демо-данные всё равно остаются.
        if (app(CommentSearch::class)->enabled() && $this->command?->call('comments:reindex') !== 0) {
            $this->command?->warn('Search index was not updated: run php artisan comments:reindex later.');
        }
    }

    private function replies(Comment $parent, int $count, int $depth): void
    {
        if ($depth > 6) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            // ответ всегда позже родителя
            $child = $this->make($parent, fake()->dateTimeBetween($parent->created_at, 'now'));
            $this->replies($child, random_int(0, 1), $depth + 1);
        }
    }

    private function make(?Comment $parent, \DateTimeInterface $createdAt): Comment
    {
        [$name, $email, $homePage] = fake()->randomElement(self::AUTHORS);

        $comment = new Comment([
            'parent_id' => $parent?->id,
            'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
            'user_name' => $name,
            'email' => $email,
            'home_page' => $homePage,
            'text' => CommentSanitizer::sanitize($this->text()),
        ]);
        $comment->created_at = $createdAt;
        $comment->save();

        return $comment;
    }

    /** Иногда с разрешёнными тегами, чтобы в демо было видно форматирование. */
    private function text(): string
    {
        $sentence = fake()->sentence(random_int(6, 16));

        return match (random_int(0, 5)) {
            0 => '<strong>'.fake()->words(2, true).'</strong> '.$sentence,
            1 => $sentence.' <i>'.fake()->words(3, true).'</i>',
            2 => $sentence.' Try <code>php artisan '.fake()->word().'</code>.',
            3 => $sentence.' <a href="https://example.com" title="Example">See more</a>',
            default => $sentence,
        };
    }
}
