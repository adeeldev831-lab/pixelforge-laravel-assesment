<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our own id. It is created before Pixelforge knows about the generation, and is
            // sent as the Idempotency-Key and in metadata so webhooks can always find this row.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->string('status')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // All money columns are whole nanodollars. The estimate and the charge are
            // what the user pays; provider_cost_nanos is what Pixelforge bills us.
            $table->unsignedBigInteger('estimated_min_nanos');
            $table->unsignedBigInteger('estimated_max_nanos');
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charged_nanos')->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            // When Pixelforge finished it. Pixelforge bills on this UTC day.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
