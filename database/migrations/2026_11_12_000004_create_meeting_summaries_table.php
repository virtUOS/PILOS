<?php

declare(strict_types=1);

use App\Enums\RecordingAccess;
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
        Schema::create('meeting_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
            $table->string('meeting_id')->unique();
            $table->foreign('meeting_id')->references('id')->on('meetings')->onDelete('cascade');
            $table->tinyInteger('access')->default(RecordingAccess::OWNER);
            $table->dateTime('start');
            $table->dateTime('end')->nullable();
            $table->text('document_url')->nullable();
            $table->mediumText('content')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_summaries');
    }
};
