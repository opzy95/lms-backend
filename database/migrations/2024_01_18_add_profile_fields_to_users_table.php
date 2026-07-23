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
        Schema::table('users', function (Blueprint $table) {
            // Add profile fields after name column
            $table->string('title')->nullable()->comment('Prof, Dr, Mr, Mrs, etc');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_number')->nullable()->unique();
            $table->string('location')->nullable()->comment('City, State');
            $table->text('biography')->nullable();
            $table->string('school_name')->nullable();
            $table->json('core_subjects')->nullable()->comment('JSON array of subjects: ["Math", "Physics"]');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'first_name',
                'last_name',
                'phone_number',
                'location',
                'biography',
                'school_name',
                'core_subjects'
            ]);
        });
    }
};
