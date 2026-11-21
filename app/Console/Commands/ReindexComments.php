<?php

namespace App\Console\Commands;

use App\Exceptions\SearchUnavailableException;
use App\Models\Comment;
use App\Services\CommentSearch;
use Illuminate\Console\Command;

/**
 * Пересоздаёт индекс поиска и загружает в него все комментарии.
 *
 * Нужна для уже существующих данных, после seeder'а (он отключает события
 * моделей) и после изменения схемы индекса в CommentSearch::MAPPING.
 */
class ReindexComments extends Command
{
    protected $signature = 'comments:reindex {--chunk=500 : Comments per bulk request}';

    protected $description = 'Recreate the Elasticsearch index and load all comments into it';

    public function handle(CommentSearch $search): int
    {
        try {
            $search->recreateIndex();

            $total = 0;
            Comment::query()->chunkById((int) $this->option('chunk'), function ($comments) use ($search, &$total) {
                $total += $search->bulk($comments);
            });
        } catch (SearchUnavailableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Indexed {$total} comments.");

        return self::SUCCESS;
    }
}
