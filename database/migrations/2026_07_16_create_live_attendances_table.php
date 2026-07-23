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
        if (!Schema::hasTable('live_attendances')) {
            Schema::create('live_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('live_class_id')->constrained('live_classes')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->dateTime('joined_at');
                $table->dateTime('left_at')->nullable();
                $table->integer('duration_minutes')->nullable();
                $table->timestamps();

                // Unique constraint to prevent duplicate attendance records
                $table->unique(['live_class_id', 'user_id', 'joined_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_attendances');
    }
};
