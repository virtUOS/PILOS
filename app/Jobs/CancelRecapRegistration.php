<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RecapService;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cancel the registration of a meeting with the meeting summary (recap) service.
 *
 * The job carries the record id (internal meeting id) instead of the meeting model,
 * as the meeting is deleted with its room before the job runs.
 */
class CancelRecapRegistration implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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
     * @param  string  $recordId  Internal meeting id of the meeting
     */
    public function __construct(public string $recordId) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->recordId;
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
        if (! RecapService::isConfigured()) {
            Log::warning('Meeting summary registration {record} not cancelled; service not configured', ['record' => $this->recordId]);
            $this->fail('Meeting summary service not configured');

            return;
        }

        // Connection errors are thrown and the job is retried
        $response = $recapService->cancel($this->recordId);

        // 204: cancelled, 202: running job stopped, 404: unknown or already removed
        if ($response->successful() || $response->notFound()) {
            Log::info('Cancelled meeting summary registration {record}', ['record' => $this->recordId]);

            return;
        }

        if ($response->conflict() && $response->json('type') === 'urn:recap:problem:already-delivered') {
            Log::notice('Meeting summary registration {record} not cancelled; summary already delivered', ['record' => $this->recordId]);

            return;
        }

        if (RecapService::isRetryable($response)) {
            throw new RuntimeException('Cancelling meeting summary registration failed with status '.$response->status());
        }

        Log::error('Cancelling meeting summary registration {record} rejected with status {status}', [
            'record' => $this->recordId,
            'status' => $response->status(),
            'type' => $response->json('type'),
        ]);
        $this->fail('Cancelling meeting summary registration rejected with status '.$response->status());
    }
}
