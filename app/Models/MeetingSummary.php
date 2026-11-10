<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordingAccess;
use App\Traits\AddsModelNameTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Result of the meeting summary (recap) service for one meeting:
 * either a link to the document in the configured sink or the summary text (Markdown).
 */
class MeetingSummary extends Model
{
    use AddsModelNameTrait, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'room_id',
        'meeting_id',
        'access',
        'start',
        'end',
        'document_url',
        'content',
        'delivered_at',
    ];

    /**
     * The summary text is only sent to the frontend by the show endpoint.
     *
     * @var list<string>
     */
    protected $hidden = [
        'content',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'delivered_at' => 'datetime',
        'access' => RecordingAccess::class,
    ];

    public function getLogLabel(): string
    {
        return $this->id.' (meeting '.$this->meeting_id.')';
    }

    /**
     * Room the summary belongs to
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Meeting the summary belongs to
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
