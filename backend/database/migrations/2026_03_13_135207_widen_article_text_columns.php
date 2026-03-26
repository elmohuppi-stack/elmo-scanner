<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE articles ALTER COLUMN title TYPE TEXT');
        DB::statement('ALTER TABLE articles ALTER COLUMN url TYPE TEXT');
        DB::statement('ALTER TABLE articles ALTER COLUMN guid TYPE TEXT');
        DB::statement('ALTER TABLE articles ALTER COLUMN author TYPE TEXT');
        DB::statement('ALTER TABLE articles ALTER COLUMN image_url TYPE TEXT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE articles ALTER COLUMN title TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE articles ALTER COLUMN url TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE articles ALTER COLUMN guid TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE articles ALTER COLUMN author TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE articles ALTER COLUMN image_url TYPE VARCHAR(255)');
    }
};
