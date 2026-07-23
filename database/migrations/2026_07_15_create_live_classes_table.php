<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('live_classes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('course_id')->constrained()->onDelete('cascade');
        $table->foreignId('tutor_id')->constrained('users')->onDelete('cascade');
        $table->string('title');
        $table->string('room_name')->unique();
        $table->text('description')->nullable();
        $table->dateTime('start_time');
        $table->dateTime('end_time')->nullable();
        $table->enum('status', ['scheduled', 'live', 'ended'])->default('scheduled');
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_classes');
    }
};
