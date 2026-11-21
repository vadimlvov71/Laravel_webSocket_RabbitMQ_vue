<?php

namespace App\Models;

use App\Services\CommentCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $fillable = [
        'parent_id', 'root_id', 'user_name', 'email', 'home_page', 'text',
        'attachment_path', 'attachment_type', 'attachment_name',
    ];

    protected static function booted(): void
    {
        // любое изменение набора комментариев делает закэшированные страницы устаревшими
        static::created(fn () => app(CommentCache::class)->flush());
        static::deleted(fn () => app(CommentCache::class)->flush());
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
}
