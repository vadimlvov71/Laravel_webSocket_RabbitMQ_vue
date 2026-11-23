<?php

namespace App\Models;

use App\Jobs\SyncCommentToSearch;
use App\Services\CommentCache;
use App\Services\CommentSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $fillable = [
        'parent_id', 'root_id', 'user_name', 'email', 'home_page', 'text',
        'attachment_path', 'attachment_type', 'attachment_name',
    ];

    // полный email не уходит наружу даже при случайном toArray()/toJson();
    // публично показывается только maskedEmail()
    protected $hidden = ['email'];

    protected static function booted(): void
    {
        // любое изменение набора комментариев делает закэшированные страницы устаревшими
        static::created(fn () => app(CommentCache::class)->flush());
        static::deleted(fn () => app(CommentCache::class)->flush());

        static::created(fn (Comment $comment) => self::syncSearch($comment));
        static::deleted(fn (Comment $comment) => self::syncSearch($comment));
    }

    /**
     * Индекс поиска обновляет задача в очереди. Если брокер недоступен, комментарий
     * всё равно сохраняется: индекс догонит php artisan comments:reindex.
     */
    private static function syncSearch(Comment $comment): void
    {
        if (! app(CommentSearch::class)->enabled()) {
            return;
        }

        try {
            SyncCommentToSearch::dispatch($comment->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Непосредственные ответы на комментарий.
     *
     * Для вывода всего дерева эта связь не используется напрямую (она загрузила бы
     * один уровень): CommentService подключает ответы всех уровней одним запросом
     * по root_id.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** «a***@example.com»: адрес нельзя собрать для рассылки, но видно, что он разный у разных авторов. */
    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $this->email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
