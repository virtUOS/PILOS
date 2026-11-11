<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RecordingAccess;
use App\Models\Meeting;
use App\Models\MeetingSummary;
use App\Services\RecapService;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll the meeting summary (recap) service for the result of a meeting after it ended
 * and store it as a meeting summary of the room.
 *
 * The job polls with a back-off (see POLL_DELAYS, then hourly) and gives up POLL_HOURS after the meeting end,
 * if the registration is gone (404), cancelled, or its job failed or was not delivered.
 * For the `frontend` sink the summary text is fetched and the pickup is confirmed afterwards,
 * so the service deletes its copy. The summary text is never logged.
 */
class PollMeetingSummary implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Minutes between the meeting end and the first poll, and between the following polls; afterward POLL_INTERVAL
     *
     * @var list<int>
     */
    public const POLL_DELAYS = [2, 5, 10, 30];

    /**
     * Minutes between polls after POLL_DELAYS
     */
    public const POLL_INTERVAL = 60;

    /**
     * Hours after the meeting end after which polling stops
     */
    public const POLL_HOURS = 24;

    /**
     * Maximum accepted size of a summary text in bytes (2 MiB)
     */
    public const MAX_SUMMARY_BYTES = 2 * 1024 * 1024;

    /**
     * Delete the job if the meeting no longer exists (e.g. room deleted).
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * The number of times the job may be attempted, limited by retryUntil().
     *
     * @var int
     */
    public $tries = 0;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = (self::POLL_HOURS + 1) * 3600;

    /**
     * Create a new job instance.
     *
     * @param  Meeting  $meeting  Ended meeting with a meeting summary
     */
    public function __construct(public Meeting $meeting) {}

    /**
     * Dispatch the first poll for an ended meeting
     */
    public static function dispatchAfterEnd(Meeting $meeting): void
    {
        self::dispatch($meeting)->delay(now()->addMinutes(self::pollDelay(1)));
    }

    /**
     * Minutes to wait before the given poll (1 = first poll after the meeting end)
     */
    public static function pollDelay(int $poll): int
    {
        return self::POLL_DELAYS[$poll - 1] ?? self::POLL_INTERVAL;
    }

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
     * Safety limit, polling normally stops earlier (see pollAgain()).
     */
    public function retryUntil(): DateTime
    {
        return now()->addHours(self::POLL_HOURS + 1);
    }

    /**
     * Execute the job.
     */
    public function handle(RecapService $recapService): void
    {
        $recordId = $this->meeting->internal_meeting_id;

        if (! RecapService::isConfigured() || empty($recordId)) {
            Log::warning('Meeting summary result of meeting {meeting} not polled; service or record id missing', ['meeting' => $this->meeting->id]);

            return;
        }

        try {
            $response = $recapService->getRegistration($recordId);
        } catch (ConnectionException) {
            $this->pollAgain('connection error');

            return;
        }

        if ($response->notFound()) {
            Log::info('Meeting summary result of meeting {meeting} not available; registration not found', ['meeting' => $this->meeting->id]);

            return;
        }

        if (! $response->successful()) {
            $this->handleErrorResponse($response, 'registration');

            return;
        }

        $status = $response->json('status');
        $job = $response->json('job');

        if ($status === 'cancelled' || ($job['state'] ?? null) === 'failed' || in_array($job['outcome'] ?? null, ['cancelled', 'purged'], true)) {
            Log::info('Meeting summary of meeting {meeting} not delivered; registration {status}, job {state}, outcome {outcome}', [
                'meeting' => $this->meeting->id,
                'status' => $status,
                'state' => $job['state'] ?? null,
                'outcome' => $job['outcome'] ?? null,
            ]);

            return;
        }

        if (($job['outcome'] ?? null) !== 'delivered') {
            $this->pollAgain();

            return;
        }

        $this->storeResult($recapService, $recordId, $job);
    }

    /**
     * Store the delivered result and confirm the pickup of a summary text
     *
     * @param  array{document_url?: string|null, summary_available?: bool, delivered_at?: string|null}  $job
     */
    private function storeResult(RecapService $recapService, string $recordId, array $job): void
    {
        $summaryAvailable = ($job['summary_available'] ?? false) === true;
        $meetingSummary = MeetingSummary::where('meeting_id', $this->meeting->id)->first();

        if ($meetingSummary === null) {
            $content = null;
            $contentReceived = false;
            if ($summaryAvailable) {
                try {
                    $summaryResponse = $recapService->getSummary($recordId);
                } catch (ConnectionException) {
                    $this->pollAgain('connection error');

                    return;
                }

                if ($summaryResponse->successful()) {
                    $contentReceived = true;
                    $content = $this->acceptedContent($summaryResponse->body());
                } elseif (! $summaryResponse->notFound()) {
                    $this->handleErrorResponse($summaryResponse, 'summary');

                    return;
                }
            }

            $documentUrlReceived = ($job['document_url'] ?? null) !== null;
            $documentUrl = $this->acceptedDocumentUrl($job['document_url'] ?? null);

            if ($documentUrl === null && $content === null) {
                // A rejected text or link was already logged as a warning
                if (! $contentReceived && ! $documentUrlReceived) {
                    Log::notice('Meeting summary of meeting {meeting} delivered without a document link or summary text', ['meeting' => $this->meeting->id]);
                }
            } else {
                $meetingSummary = new MeetingSummary;
                $meetingSummary->room_id = $this->meeting->room_id;
                $meetingSummary->meeting_id = $this->meeting->id;
                $meetingSummary->access = RecordingAccess::OWNER;
                $meetingSummary->start = $this->meeting->start;
                $meetingSummary->end = $this->meeting->end;
                $meetingSummary->document_url = $documentUrl;
                $meetingSummary->content = $content;
                $meetingSummary->delivered_at = $job['delivered_at'] ?? now();
                $meetingSummary->save();

                Log::info('Stored meeting summary {summary} of meeting {meeting} in room {room}', [
                    'summary' => $meetingSummary->id,
                    'meeting' => $this->meeting->id,
                    'room' => $this->meeting->room_id,
                    'type' => $content !== null ? 'content' : 'document',
                ]);
            }
        }

        if ($summaryAvailable) {
            $this->confirmSummary($recapService, $recordId);
        }
    }

    /**
     * Confirm the pickup, so the service deletes the summary text
     */
    private function confirmSummary(RecapService $recapService, string $recordId): void
    {
        try {
            $response = $recapService->confirmSummary($recordId);
        } catch (ConnectionException) {
            $this->pollAgain('connection error');

            return;
        }

        if ($response->successful() || $response->notFound()) {
            Log::info('Confirmed the pickup of the meeting summary of meeting {meeting}', ['meeting' => $this->meeting->id]);

            return;
        }

        $this->handleErrorResponse($response, 'summary confirmation');
    }

    /**
     * Returns the summary text if it is valid UTF-8 and within the size limit
     */
    private function acceptedContent(string $content): ?string
    {
        if (strlen($content) > self::MAX_SUMMARY_BYTES || ! mb_check_encoding($content, 'UTF-8')) {
            Log::warning('Meeting summary text of meeting {meeting} rejected; {bytes} bytes, limit {limit} bytes, UTF-8 required', [
                'meeting' => $this->meeting->id,
                'bytes' => strlen($content),
                'limit' => self::MAX_SUMMARY_BYTES,
            ]);

            return null;
        }

        return $content;
    }

    /**
     * Returns the document link if it is an absolute http(s) URL, as it is shown as a link to users
     */
    private function acceptedDocumentUrl(mixed $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            Log::warning('Meeting summary document link of meeting {meeting} rejected; not an http(s) URL', ['meeting' => $this->meeting->id]);

            return null;
        }

        return $url;
    }

    /**
     * Retry later on rate limits and server errors, stop on other errors
     */
    private function handleErrorResponse(Response $response, string $request): void
    {
        if (RecapService::isRetryable($response)) {
            $this->pollAgain('status '.$response->status());

            return;
        }

        Log::error('Meeting summary {request} request for meeting {meeting} rejected with status {status}', [
            'request' => $request,
            'meeting' => $this->meeting->id,
            'status' => $response->status(),
            'type' => $response->json('type'),
        ]);
        $this->fail('Meeting summary '.$request.' request rejected with status '.$response->status());
    }

    /**
     * Release the job for the next poll, or give up if the next poll would be too late
     *
     * @param  string|null  $error  Reason if the poll failed
     */
    private function pollAgain(?string $error = null): void
    {
        if ($error !== null) {
            Log::warning('Polling the meeting summary result of meeting {meeting} failed: {error}', ['meeting' => $this->meeting->id, 'error' => $error]);
        }

        $delay = self::pollDelay($this->attempts() + 1);
        $deadline = ($this->meeting->end ?? $this->meeting->start)->copy()->addHours(self::POLL_HOURS);

        if (now()->addMinutes($delay)->greaterThan($deadline)) {
            Log::notice('Stopped polling the meeting summary result of meeting {meeting}; no result {hours} hours after the meeting end', [
                'meeting' => $this->meeting->id,
                'hours' => self::POLL_HOURS,
            ]);

            return;
        }

        $this->release($delay * 60);
    }
}
