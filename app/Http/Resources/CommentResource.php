<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * JSON-представление комментария.
 *
 * Ответы (replies) выводятся рекурсивно, если связь загружена
 * (см. CommentService::attachReplies), поэтому глубина вложенности не ограничена.
 *
 * @mixin \App\Models\Comment
 */
class CommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_name' => $this->user_name,
            /** Masked for privacy: the first character of the local part and the domain, e.g. `a***@example.com`. */
            'email' => $this->maskedEmail(),
            'home_page' => $this->home_page,
            'text' => $this->text,
            'created_at' => $this->created_at->toIso8601String(),
            'attachment' => $this->attachment_path ? [
                'type' => $this->attachment_type,
                'name' => $this->attachment_name,
                'url' => Storage::disk('public')->url($this->attachment_path),
            ] : null,
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
