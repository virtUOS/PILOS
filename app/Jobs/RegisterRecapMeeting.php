<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Meeting;
use App\Models\User;
use App\Services\RecapService;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Register the room owner of a meeting with the meeting summary (recap) service.
 *
 * Only the model IDs are serialized, so the queue holds no personal data.
 * Network errors, rate limits and server errors are retried, other client errors are final.
 */
class RegisterRecapMeeting implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Delete the job if the meeting or the owner no longer exist (e.g. room deleted).
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 3600;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 0;

    /**
     * Create a new job instance.
     *
     * @param  Meeting  $meeting  Meeting to register
     * @param  User  $owner  Room owner at meeting start, receives the summary
     */
    public function __construct(public Meeting $meeting, public User $owner) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->meeting->id;
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['recap'];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTime
    {
        return now()->addDay();
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 60 * 5];
    }

    /**
     * Execute the job.
     *
     * @throws RuntimeException
     */
    public function handle(RecapService $recapService): void
    {
        if (! RecapService::isConfigured() || empty($this->meeting->internal_meeting_id) || empty($this->owner->oidc_sub)) {
            Log::warning('Meeting summary registration for meeting {meeting} skipped; service, record id or owner subject missing', ['meeting' => $this->meeting->id]);
            $this->fail('Meeting summary registration not possible');

            return;
        }

        // Connection errors are thrown and the job is retried
        $response = $recapService->register($this->meeting, $this->owner);

        if ($response->successful()) {
            Log::info('Registered meeting {meeting} for a meeting summary', ['meeting' => $this->meeting->id]);

            return;
        }

        if (RecapService::isRetryable($response)) {
            throw new RuntimeException('Meeting summary registration failed with status '.$response->status());
        }

        Log::error('Meeting summary registration for meeting {meeting} rejected with status {status}', [
            'meeting' => $this->meeting->id,
            'status' => $response->status(),
            'type' => $response->json('type'),
        ]);
        $this->fail('Meeting summary registration rejected with status '.$response->status());
    }
}
