<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            // непосредственный родитель (null = корневой комментарий)
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            // самый верхний предок ветки: позволяет загрузить всё дерево одним запросом
            $table->foreignId('root_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->string('user_name', 64);
            $table->string('email');
            $table->string('home_page')->nullable();
            $table->text('text'); // уже очищенный XHTML
            $table->timestamps();

            $table->index('user_name');
            $table->index('email');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
