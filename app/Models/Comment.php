<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $fillable = ['parent_id', 'root_id', 'user_name', 'email', 'home_page', 'text'];
    /*
    belongsTo означает «этот комментарий принадлежит другому». Комментарий хранит ссылку на родителя в своей строке (колонка parent_id), поэтому он «принадлежит» родителю.
    self::class это модель, к которой идёт связь. Комментарий ссылается на комментарий же, то есть таблица связана сама с собой (self-referencing). self::class равно Comment::class и не требует повторять имя класса.
    'parent_id' это внешний ключ. Он нужен явно, потому что Laravel по умолчанию искал бы колонку по имени метода и модели (parent_id для метода parent() он бы угадал, но для ясности и надёжности ключ указывают).
    : BelongsTo это возвращаемый тип. Он нужен для статического анализа и подсказок в IDE, и Laravel 12 его поддерживает и рекомендует.
    public function parent(): BelongsTo
    */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }


    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}