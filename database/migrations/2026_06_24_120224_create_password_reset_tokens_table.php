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
        // Table already created in create_users_table migration
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op - table dropped by create_users_table down()
    }
};
