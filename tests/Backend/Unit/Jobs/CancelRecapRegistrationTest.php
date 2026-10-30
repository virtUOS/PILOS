<?php

declare(strict_types=1);

namespace Tests\Backend\Unit\Jobs;

use App\Jobs\CancelRecapRegistration;
use App\Services\RecapService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Backend\TestCase;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

class CancelRecapRegistrationTest extends TestCase
{
    private const SERVICE_URL = 'https://service.recap.test';

    private const FRONTEND_TOKEN = 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef';

    private const RECORD_ID = '536c0e9585fba3ee1c984b5af671f5be7ec5cf96-1761732000000';

    protected function setUp(): void
    {
        parent::setUp();

        Log::swap(new LogFake);

        config([
            'recap.service_url' => self::SERVICE_URL,
            'recap.frontend_token' => self::FRONTEND_TOKEN,
        ]);
    }

    private function createJob(): CancelRecapRegistration
    {
        return (new CancelRecapRegistration(self::RECORD_ID))->withFakeQueueInteractions();
    }

    public static function successStatusProvider(): array
    {
        return [
            'cancelled' => [204],
            'running job stopped' => [202],
            'not found' => [404],
        ];
    }

    #[DataProvider('successStatusProvider')]
    public function test_cancel(int $status)
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(null, $status)]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === self::SERVICE_URL.'/api/v1/registrations/'.self::RECORD_ID
            && $request->hasHeader('Authorization', 'Bearer '.self::FRONTEND_TOKEN));
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'info' && $log->context['record'] === self::RECORD_ID);
    }

    public function test_already_delivered()
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(['type' => 'urn:recap:problem:already-delivered'], 409)]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertNotFailed();
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'notice' && $log->context['record'] === self::RECORD_ID);
    }

    public static function retryableStatusProvider(): array
    {
        return [
            'too many requests' => [429],
            'bad gateway' => [502],
        ];
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_retry_on_status(int $status)
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(null, $status)]);

        $job = $this->createJob();

        $this->expectException(RuntimeException::class);
        $job->handle(new RecapService);
    }

    public function test_retry_on_connection_error()
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::failedConnection()]);

        $job = $this->createJob();

        $this->expectException(ConnectionException::class);
        $job->handle(new RecapService);
    }

    public function test_fail_on_client_error()
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(['type' => 'urn:recap:problem:unauthorized'], 401)]);

        $job = $this->createJob();
        $job->handle(new RecapService);

        $job->assertFailed();
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'error'
            && $log->context['status'] === 401
            && ! str_contains($log->message.json_encode($log->context), self::FRONTEND_TOKEN));
    }
}
