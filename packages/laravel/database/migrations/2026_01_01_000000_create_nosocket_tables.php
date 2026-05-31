<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nosocket_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('channel', 128);
            $table->string('event', 128);
            $table->json('payload_json');
            $table->dateTime('created_at');
            $table->dateTime('expires_at')->index();
            $table->index(['channel', 'id']);
        });
        Schema::create('nosocket_rate_limits', function (Blueprint $table): void {
            $table->char('key_hash', 64);
            $table->unsignedBigInteger('bucket');
            $table->unsignedInteger('hits');
            $table->dateTime('expires_at')->index();
            $table->primary(['key_hash', 'bucket']);
        });
        Schema::create('nosocket_channel_watermarks', function (Blueprint $table): void {
            $table->string('channel', 128)->primary();
            $table->unsignedBigInteger('event_id');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nosocket_channel_watermarks');
        Schema::dropIfExists('nosocket_rate_limits');
        Schema::dropIfExists('nosocket_events');
    }
};
