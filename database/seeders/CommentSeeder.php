<?php

namespace Database\Seeders;

use App\Models\Comment;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $root = $this->make(null);
            $this->replies($root, random_int(0, 3), 1);
        }
    }

    private function replies(Comment $parent, int $count, int $depth): void
    {
        if ($depth > 6) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $child = $this->make($parent);
            $this->replies($child, random_int(0, 1), $depth + 1);
        }
    }

    private function make(?Comment $parent): Comment
    {
        $comment = new Comment([
            'parent_id' => $parent?->id,
            'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
            'user_name' => fake()->regexify('[A-Za-z0-9]{8}'),
            'email' => fake()->safeEmail(),
            'home_page' => fake()->boolean(30) ? fake()->url() : null,
            'text' => e(fake()->sentence(10)),
        ]);
        $comment->created_at = fake()->dateTimeBetween('-30 days');
        $comment->save();

        return $comment;
    }
}
