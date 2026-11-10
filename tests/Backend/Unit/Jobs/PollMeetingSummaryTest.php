<?php

declare(strict_types=1);

namespace Tests\Backend\Unit\Jobs;

use App\Enums\RecordingAccess;
use App\Jobs\PollMeetingSummary;
use App\Models\Meeting;
use App\Models\MeetingSummary;
use App\Services\MeetingService;
use App\Services\RecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Backend\TestCase;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

class PollMeetingSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_URL = 'https://service.recap.test/base';

    private const FRONTEND_TOKEN = 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef';

    private const RECORD_ID = '536c0e9585fba3ee1c984b5af671f5be7ec5cf96-1761732000000';

    private const REGISTRATION_URL = self::SERVICE_URL.'/api/v1/registrations/'.self::RECORD_ID;

    private const SUMMARY_URL = self::REGISTRATION_URL.'/summary';

    private const DOCUMENT_URL = 'https://docs.recap.test/docs/0b6b7c7e-5d3c-4c1e-9a43-3f0c2f6d9a11/';

    private const SUMMARY_MARKDOWN = "# Fixture meeting summary\n\n- Action item for Ada Testperson: FIXTURE-SUMMARY-SECRET-TEXT\n";

    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Log::swap(new LogFake);

        config([
            'recap.service_url' => self::SERVICE_URL,
            'recap.frontend_token' => self::FRONTEND_TOKEN,
        ]);

        $this->meeting = Meeting::factory()->create([
            'recap' => true,
            'internal_meeting_id' => self::RECORD_ID,
            'start' => now()->subHour(),
            'end' => now()->subMinutes(2),
        ]);
    }

    private function createJob(int $attempts = 1): PollMeetingSummary
    {
        $job = (new PollMeetingSummary($this->meeting))->withFakeQueueInteractions();
        $job->job->attempts = $attempts;

        return $job;
    }

    /**
     * Registration response (metadata-interface.md section 2.3 and 2.7)
     *
     * @param  array<string, mixed>|null  $job
     * @return array<string, mixed>
     */
    private function registration(string $status = 'matched', ?array $job = []): array
    {
        return [
            'schema_version' => '1.1.0',
            'record_id' => self::RECORD_ID,
            'meeting_id' => $this->meeting->id,
            'frontend_id' => 'pilos-fixture',
            'status' => $status,
            'created_at' => '2026-10-29T10:00:00Z',
            'updated_at' => '2026-10-29T10:00:00Z',
            'expires_at' => null,
            'job' => $job === null ? null : array_merge([
                'job_id' => '7f0c2d4e-1b2a-4c3d-8e9f-0a1b2c3d4e5f',
                'state' => 'running',
                'held_reason' => null,
                'outcome' => null,
                'delivered_at' => null,
                'document_url' => null,
                'summary_available' => false,
            ], $job),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveredFrontend(): array
    {
        return $this->registration('matched', [
            'state' => 'delivered',
            'outcome' => 'delivered',
            'delivered_at' => '2026-10-29T11:00:00Z',
            'summary_available' => true,
        ]);
    }

    private function assertSummaryNotLogged(): void
    {
        Log::assertNotLogged(function (LogEntry $log) {
            $entry = $log->message.json_encode($log->context);

            return str_contains($entry, 'FIXTURE-SUMMARY-SECRET-TEXT') || str_contains($entry, 'Fixture meeting summary');
        });
    }

    public function test_meeting_end_dispatches_first_poll()
    {
        Queue::fake();
        $this->freezeSecond();

        $meeting = Meeting::factory()->create(['recap' => true, 'internal_meeting_id' => self::RECORD_ID, 'end' => null]);
        (new MeetingService($meeting))->setEnd();

        Queue::assertPushed(PollMeetingSummary::class, function (PollMeetingSummary $job) use ($meeting) {
            return $job->meeting->is($meeting) && $job->delay->equalTo(now()->addMinutes(2));
        });
    }

    public function test_meeting_end_without_summary_dispatches_nothing()
    {
        Queue::fake();

        $meeting = Meeting::factory()->create(['recap' => false, 'internal_meeting_id' => self::RECORD_ID, 'end' => null]);
        (new MeetingService($meeting))->setEnd();

        config(['recap.service_url' => null]);
        $meeting = Meeting::factory()->create(['recap' => true, 'internal_meeting_id' => self::RECORD_ID, 'end' => null]);
        (new MeetingService($meeting))->setEnd();

        Queue::assertNotPushed(PollMeetingSummary::class);
    }

    public function test_document_result_is_stored()
    {
        Http::fake([
            self::REGISTRATION_URL => Http::response($this->registration('matched', [
                'state' => 'cleaned',
                'outcome' => 'delivered',
                'delivered_at' => '2026-10-29T11:00:00Z',
                'document_url' => self::DOCUMENT_URL,
            ])),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        $job->assertNotReleased();

        $summary = MeetingSummary::sole();
        $this->assertSame(self::DOCUMENT_URL, $summary->document_url);
        $this->assertNull($summary->content);
        $this->assertSame(RecordingAccess::OWNER, $summary->access);
        $this->assertTrue($summary->room->is($this->meeting->room));
        $this->assertEquals($this->meeting->start, $summary->start);
        $this->assertEquals($this->meeting->end, $summary->end);
        $this->assertSame('2026-10-29 11:00:00', $summary->delivered_at->format('Y-m-d H:i:s'));

        // Only the registration is requested, no summary text
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === self::REGISTRATION_URL
                && $request->hasHeader('Authorization', 'Bearer '.self::FRONTEND_TOKEN);
        });
    }

    public function test_frontend_result_is_stored_and_confirmed()
    {
        Http::fake([
            self::SUMMARY_URL => Http::sequence()
                ->push(self::SUMMARY_MARKDOWN, 200, ['Content-Type' => 'text/markdown; charset=utf-8'])
                ->push(null, 204),
            self::REGISTRATION_URL => Http::response($this->deliveredFrontend()),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        $job->assertNotReleased();

        $summary = MeetingSummary::sole();
        $this->assertNull($summary->document_url);
        $this->assertSame(self::SUMMARY_MARKDOWN, $summary->content);
        $this->assertSame(RecordingAccess::OWNER, $summary->access);

        // Registration, summary text, then the confirmation
        $recorded = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.$pair[0]->url())->all();
        $this->assertSame([
            'GET '.self::REGISTRATION_URL,
            'GET '.self::SUMMARY_URL,
            'DELETE '.self::SUMMARY_URL,
        ], $recorded);

        $this->assertSummaryNotLogged();
    }

    public function test_frontend_result_confirmation_is_retried()
    {
        Http::fake([
            self::SUMMARY_URL => Http::sequence()
                ->push(self::SUMMARY_MARKDOWN, 200, ['Content-Type' => 'text/markdown; charset=utf-8'])
                ->push(null, 503)
                ->push(null, 204),
            self::REGISTRATION_URL => Http::response($this->deliveredFrontend()),
        ]);

        // Confirmation fails, the summary is stored and the job polls again
        $job = $this->createJob();
        $job->handle(new RecapService);
        $job->assertReleased(5 * 60);
        $this->assertSame(self::SUMMARY_MARKDOWN, MeetingSummary::sole()->content);

        // The next poll only confirms the pickup, the text is not fetched again
        $job = $this->createJob(2);
        $job->handle(new RecapService);
        $job->assertNotReleased();
        $job->assertNotFailed();

        $this->assertSame(1, MeetingSummary::count());
        $recorded = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.$pair[0]->url())->all();
        $this->assertSame([
            'GET '.self::REGISTRATION_URL,
            'GET '.self::SUMMARY_URL,
            'DELETE '.self::SUMMARY_URL,
            'GET '.self::REGISTRATION_URL,
            'DELETE '.self::SUMMARY_URL,
        ], $recorded);

        $this->assertSummaryNotLogged();
    }

    public function test_too_large_summary_is_rejected()
    {
        Http::fake([
            self::SUMMARY_URL => Http::sequence()
                ->push(str_repeat('a', PollMeetingSummary::MAX_SUMMARY_BYTES + 1), 200, ['Content-Type' => 'text/markdown; charset=utf-8'])
                ->push(null, 204),
            self::REGISTRATION_URL => Http::response($this->deliveredFrontend()),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(0, MeetingSummary::count());
        // The pickup is still confirmed, so the service deletes the text
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::SUMMARY_URL);
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'warning' && $log->context['bytes'] === PollMeetingSummary::MAX_SUMMARY_BYTES + 1);
    }

    public function test_summary_at_size_limit_is_stored()
    {
        $content = str_repeat('a', PollMeetingSummary::MAX_SUMMARY_BYTES);
        Http::fake([
            self::SUMMARY_URL => Http::sequence()
                ->push($content, 200, ['Content-Type' => 'text/markdown; charset=utf-8'])
                ->push(null, 204),
            self::REGISTRATION_URL => Http::response($this->deliveredFrontend()),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $this->assertSame($content, MeetingSummary::sole()->content);
    }

    public function test_invalid_document_url_is_rejected()
    {
        Http::fake([
            self::REGISTRATION_URL => Http::response($this->registration('matched', [
                'state' => 'cleaned',
                'outcome' => 'delivered',
                'delivered_at' => '2026-10-29T11:00:00Z',
                'document_url' => 'javascript:alert(1)',
            ])),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(0, MeetingSummary::count());
    }

    public function test_stored_result_is_not_duplicated()
    {
        MeetingSummary::factory()->document()->create(['meeting_id' => $this->meeting->id]);

        Http::fake([
            self::REGISTRATION_URL => Http::response($this->registration('matched', [
                'state' => 'cleaned',
                'outcome' => 'delivered',
                'delivered_at' => '2026-10-29T11:00:00Z',
                'document_url' => self::DOCUMENT_URL,
            ])),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $this->assertSame(1, MeetingSummary::count());
        $this->assertNotSame(self::DOCUMENT_URL, MeetingSummary::sole()->document_url);
    }

    public static function pendingProvider(): array
    {
        return [
            'registered' => ['registered', null],
            'received' => ['matched', ['state' => 'received']],
            'running' => ['matched', ['state' => 'running']],
            'held' => ['matched', ['state' => 'held', 'held_reason' => 'owner_unresolved']],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $job
     */
    #[DataProvider('pendingProvider')]
    public function test_pending_result_is_polled_again(string $status, ?array $job)
    {
        Http::fake([self::REGISTRATION_URL => Http::response($this->registration($status, $job))]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertReleased(5 * 60);
        $job->assertNotFailed();
        $this->assertSame(0, MeetingSummary::count());
    }

    public static function backoffProvider(): array
    {
        // attempt that just ran => minutes until the next poll
        return [
            'after first poll' => [1, 5],
            'after second poll' => [2, 10],
            'after third poll' => [3, 30],
            'after fourth poll' => [4, 60],
            'after tenth poll' => [10, 60],
        ];
    }

    #[DataProvider('backoffProvider')]
    public function test_backoff(int $attempt, int $minutes)
    {
        Http::fake([self::REGISTRATION_URL => Http::response($this->registration('registered', null))]);

        $job = $this->createJob($attempt);
        $job->handle(new RecapService);

        $job->assertReleased($minutes * 60);
    }

    public function test_first_poll_delay()
    {
        $this->assertSame(2, PollMeetingSummary::pollDelay(1));
    }

    public function test_give_up_after_24_hours()
    {
        Http::fake([self::REGISTRATION_URL => Http::response($this->registration('matched', ['state' => 'held', 'held_reason' => 'owner_unresolved']))]);

        // Next poll in one hour would still be within 24 hours after the meeting end
        $this->meeting->end = now()->subHours(22)->subMinutes(59);
        $this->meeting->save();
        $job = $this->createJob(20);
        $job->handle(new RecapService);
        $job->assertReleased(60 * 60);

        // Next poll would be later than 24 hours after the meeting end
        $this->meeting->end = now()->subHours(23)->subMinutes(1);
        $this->meeting->save();
        $job = $this->createJob(21);
        $job->handle(new RecapService);
        $job->assertNotReleased();
        $job->assertNotFailed();
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'notice' && str_contains($log->message, 'Stopped polling'));
    }

    public static function finalResultProvider(): array
    {
        return [
            'registration cancelled' => ['cancelled', null],
            'job failed' => ['matched', ['state' => 'failed']],
            'outcome cancelled' => ['matched', ['state' => 'cleaned', 'outcome' => 'cancelled']],
            'outcome purged' => ['matched', ['state' => 'cleaned', 'outcome' => 'purged']],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $job
     */
    #[DataProvider('finalResultProvider')]
    public function test_stop_on_final_result(string $status, ?array $job)
    {
        Http::fake([self::REGISTRATION_URL => Http::response($this->registration($status, $job))]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(0, MeetingSummary::count());
        Http::assertSentCount(1);
    }

    public function test_stop_on_not_found()
    {
        Http::fake([self::REGISTRATION_URL => Http::response([
            'type' => 'urn:recap:problem:not-found',
            'title' => 'Not found',
            'status' => 404,
        ], 404, ['Content-Type' => 'application/problem+json'])]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(0, MeetingSummary::count());
    }

    public static function retryableStatusProvider(): array
    {
        return [
            '429' => [429],
            '500' => [500],
            '503' => [503],
        ];
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_poll_again_on_status(int $status)
    {
        Http::fake([self::REGISTRATION_URL => Http::response(null, $status)]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertReleased(5 * 60);
        $job->assertNotFailed();
    }

    public function test_poll_again_on_connection_error()
    {
        Http::fake([self::REGISTRATION_URL => fn () => throw new ConnectionException('Connection refused')]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertReleased(5 * 60);
        $job->assertNotFailed();
    }

    public function test_fail_on_client_error()
    {
        Http::fake([self::REGISTRATION_URL => Http::response([
            'type' => 'urn:recap:problem:unauthorized',
            'title' => 'Unauthorized',
            'status' => 401,
        ], 401, ['Content-Type' => 'application/problem+json'])]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertFailed();
        $job->assertNotReleased();
    }

    public function test_summary_not_available_is_not_stored()
    {
        Http::fake([
            self::SUMMARY_URL => Http::sequence()
                ->push(['type' => 'urn:recap:problem:summary-not-available', 'status' => 404], 404, ['Content-Type' => 'application/problem+json'])
                ->push(null, 204),
            self::REGISTRATION_URL => Http::response($this->deliveredFrontend()),
        ]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(0, MeetingSummary::count());
    }

    public function test_skip_without_service_configuration()
    {
        config(['recap.frontend_token' => null]);
        Http::fake();

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotReleased();
        Http::assertNothingSent();
    }
}
