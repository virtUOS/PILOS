<?php

declare(strict_types=1);

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
        Schema::table('room_types', function (Blueprint $table) {
            $table->boolean('recap_default')->default(false);
            $table->boolean('recap_enforced')->default(false);
            $table->string('recap_type', 63)->nullable();
            $table->string('recap_sink', 63)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['recap_default', 'recap_enforced', 'recap_type', 'recap_sink']);
        });
    }
};
