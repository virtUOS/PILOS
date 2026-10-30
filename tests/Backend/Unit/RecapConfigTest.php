<?php

declare(strict_types=1);

namespace Tests\Backend\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Backend\TestCase;

class RecapConfigTest extends TestCase
{
    private const ENV_KEYS = ['RECAP_ENABLED', 'RECAP_SERVICE_URL', 'RECAP_FRONTEND_TOKEN'];

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->setEnv($key, null);
        }

        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public static function environmentProvider(): array
    {
        return [
            'all set' => ['true', 'https://service.recap.test', 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef', true],
            'switch off' => ['false', 'https://service.recap.test', 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef', false],
            'service url missing' => ['true', null, 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef', false],
            'token missing' => ['true', 'https://service.recap.test', null, false],
            'token empty' => ['true', 'https://service.recap.test', '', false],
        ];
    }

    /**
     * The feature is only enabled with the master switch, the service URL and the token
     */
    #[DataProvider('environmentProvider')]
    public function test_enabled_requires_service_url_and_token(string $enabled, ?string $serviceUrl, ?string $token, bool $expected)
    {
        $this->setEnv('RECAP_ENABLED', $enabled);
        $this->setEnv('RECAP_SERVICE_URL', $serviceUrl);
        $this->setEnv('RECAP_FRONTEND_TOKEN', $token);

        $config = require config_path('recap.php');

        $this->assertSame($expected, $config['enabled']);
    }
}
