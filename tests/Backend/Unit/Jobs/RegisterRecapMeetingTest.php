<?php

declare(strict_types=1);

namespace Tests\Backend\Unit\Jobs;

use App\Jobs\RegisterRecapMeeting;
use App\Models\Meeting;
use App\Models\User;
use App\Services\RecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Backend\TestCase;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

class RegisterRecapMeetingTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_URL = 'https://service.recap.test/base';

    private const FRONTEND_TOKEN = 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef';

    private const OWNER_SUB = 'fixture-sub-ada-0001';

    private const OWNER_EMAIL = 'ada.testperson@example.org';

    private const RECORD_ID = '536c0e9585fba3ee1c984b5af671f5be7ec5cf96-1761732000000';

    protected Meeting $meeting;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Log::swap(new LogFake);

        config([
            'recap.service_url' => self::SERVICE_URL,
            'recap.frontend_token' => self::FRONTEND_TOKEN,
        ]);

        $this->owner = User::factory()->create([
            'authenticator' => 'oidc',
            'oidc_sub' => self::OWNER_SUB,
            'email' => self::OWNER_EMAIL,
        ]);
        $this->meeting = Meeting::factory()->create([
            'recap' => true,
            'internal_meeting_id' => self::RECORD_ID,
        ]);
    }

    private function createJob(): RegisterRecapMeeting
    {
        return (new RegisterRecapMeeting($this->meeting, $this->owner))->withFakeQueueInteractions();
    }

    /**
     * Assert that no log entry contains the owner subject, email or the token
     */
    private function assertNoPersonalDataLogged(): void
    {
        Log::assertNotLogged(function (LogEntry $log) {
            $entry = $log->message.json_encode($log->context);

            return str_contains($entry, self::OWNER_SUB)
                || str_contains($entry, self::OWNER_EMAIL)
                || str_contains($entry, self::FRONTEND_TOKEN);
        });
    }

    public function test_register()
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(['status' => 'registered'], 201)]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::SERVICE_URL.'/api/v1/registrations/'.self::RECORD_ID
            && $request->hasHeader('Authorization', 'Bearer '.self::FRONTEND_TOKEN)
            && $request->isJson()
            && $request->data() === [
                'schema_version' => '1.0.0',
                'meeting_id' => $this->meeting->id,
                'record_id' => self::RECORD_ID,
                'owner' => [
                    'sub' => self::OWNER_SUB,
                    'email' => self::OWNER_EMAIL,
                ],
            ]);

        Log::assertLogged(fn (LogEntry $log) => $log->level === 'info' && $log->context['meeting'] === $this->meeting->id);
        $this->assertNoPersonalDataLogged();
    }

    public function test_queue_payload_has_no_personal_data()
    {
        $payload = serialize(new RegisterRecapMeeting($this->meeting, $this->owner));

        $this->assertStringNotContainsString(self::OWNER_SUB, $payload);
        $this->assertStringNotContainsString(self::OWNER_EMAIL, $payload);
    }

    public static function retryableStatusProvider(): array
    {
        return [
            'too many requests' => [429],
            'internal server error' => [500],
            'service unavailable' => [503],
        ];
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_retry_on_status(int $status)
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(['type' => 'urn:recap:problem:rate-limited'], $status)]);

        $job = $this->createJob();

        try {
            $job->handle(new RecapService);
            $this->fail('Expected exception to retry the job');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString(self::OWNER_SUB, $exception->getMessage());
        }

        $job->assertNotFailed();
        $this->assertNoPersonalDataLogged();
    }

    public function test_retry_on_connection_error()
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::failedConnection()]);

        $job = $this->createJob();

        $this->expectException(ConnectionException::class);
        $job->handle(new RecapService);
    }

    public function test_retry_settings()
    {
        $job = new RegisterRecapMeeting($this->meeting, $this->owner);

        $this->assertEquals(0, $job->tries);
        $this->assertEquals([30, 60, 300], $job->backoff());
        $this->assertTrue($job->retryUntil() > now()->addHours(23));
    }

    public static function finalStatusProvider(): array
    {
        return [
            'bad request' => [400, 'urn:recap:problem:invalid-request'],
            'unauthorized' => [401, 'urn:recap:problem:unauthorized'],
            'conflict' => [409, 'urn:recap:problem:registration-locked'],
            'unprocessable' => [422, 'urn:recap:problem:unsupported-schema-version'],
        ];
    }

    #[DataProvider('finalStatusProvider')]
    public function test_fail_on_client_error(int $status, string $type)
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(['type' => $type, 'detail' => 'fixture detail'], $status)]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertFailed();
        Http::assertSentCount(1);
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'error'
            && $log->context['status'] === $status
            && $log->context['type'] === $type
            && $log->context['meeting'] === $this->meeting->id);
        $this->assertNoPersonalDataLogged();
    }

    public function test_fail_without_owner_subject()
    {
        Http::fake();
        $this->owner->forceFill(['oidc_sub' => null])->save();

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertFailed();
        Http::assertNothingSent();
        $this->assertNoPersonalDataLogged();
    }

    public function test_fail_without_service_configuration()
    {
        Http::fake();
        config(['recap.frontend_token' => null]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertFailed();
        Http::assertNothingSent();
    }
}
