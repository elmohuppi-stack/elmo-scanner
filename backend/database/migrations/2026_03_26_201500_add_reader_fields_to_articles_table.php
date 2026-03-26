<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->longText('reader_html')->nullable()->after('content_hash');
            $table->longText('reader_text')->nullable()->after('reader_html');
            $table->timestamp('reader_extracted_at')->nullable()->after('reader_text');
            $table->text('reader_error')->nullable()->after('reader_extracted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn([
                'reader_html',
                'reader_text',
                'reader_extracted_at',
                'reader_error',
            ]);
        });
    }
};
