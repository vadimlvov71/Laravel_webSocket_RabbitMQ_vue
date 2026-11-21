<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // путь на диске public; имя файла генерируется, исходное имя хранится отдельно
            $table->string('attachment_path')->nullable()->after('text');
            // image | text, см. App\Enums\AttachmentType
            $table->string('attachment_type', 16)->nullable()->after('attachment_path');
            $table->string('attachment_name')->nullable()->after('attachment_type');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_type', 'attachment_name']);
        });
    }
};
