<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Кэш страниц списка комментариев.
 *
 * Сброс через версию в ключе, а не удаление ключей: страниц много (сортировка,
 * направление, номер), перебирать их не нужно, а теги поддерживают не все драйверы.
 * Старые версии сами истекают по TTL.
 */
class CommentCache
{
    private const VERSION_KEY = 'comments:version';

    public function __construct(private readonly Repository $cache)
    {
    }

    /**
     * @param  Closure(): array<string, mixed>  $build  строит страницу, если её нет в кэше
     * @return array<string, mixed>
     */
    public function remember(string $sort, string $dir, int $page, Closure $build): array
    {
        $key = sprintf('comments:v%d:%s:%s:%d', $this->version(), $sort, $dir, $page);

        return $this->cache->remember($key, (int) config('comments.cache_ttl'), $build);
    }

    public function flush(): void
    {
        // add() не перезапишет версию, если она уже есть; increment() атомарен в Redis
        $this->cache->add(self::VERSION_KEY, 1);
        $this->cache->increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) $this->cache->rememberForever(self::VERSION_KEY, fn () => 1);
    }
}
