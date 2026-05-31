<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nosocket_channel_watermarks')) {
            Schema::create('nosocket_channel_watermarks', function (Blueprint $table): void {
                $table->string('channel', 128)->primary();
                $table->unsignedBigInteger('event_id');
                $table->dateTime('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nosocket_channel_watermarks');
    }
};
