<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for the registration API of the meeting summary (recap) service
 */
class RecapService
{
    public const SCHEMA_VERSION = '1.0.0';

    /**
     * Indicates whether the service URL and the token are configured
     */
    public static function isConfigured(): bool
    {
        return ! empty(config('recap.service_url')) && ! empty(config('recap.frontend_token'));
    }

    public function getHttpClient(): PendingRequest
    {
        return Http::timeout(config('recap.server_timeout'))
            ->connectTimeout(config('recap.server_connect_timeout'))
            ->baseUrl(config('recap.service_url'))
            ->withToken(config('recap.frontend_token'))
            ->acceptJson();
    }

    /**
     * Register the owner of a meeting with the service
     *
     * @throws ConnectionException
     */
    public function register(Meeting $meeting, User $owner): Response
    {
        return $this->getHttpClient()->put('api/v1/registrations/'.rawurlencode($meeting->internal_meeting_id), [
            'schema_version' => self::SCHEMA_VERSION,
            'meeting_id' => $meeting->id,
            'record_id' => $meeting->internal_meeting_id,
            'owner' => [
                'sub' => $owner->oidc_sub,
                'email' => $owner->email,
            ],
        ]);
    }

    /**
     * Cancel the registration of a meeting
     *
     * @throws ConnectionException
     */
    public function cancel(string $recordId): Response
    {
        return $this->getHttpClient()->delete('api/v1/registrations/'.rawurlencode($recordId));
    }

    /**
     * Get the registration of a meeting, including the result of its job
     *
     * @throws ConnectionException
     */
    public function getRegistration(string $recordId): Response
    {
        return $this->getHttpClient()->get('api/v1/registrations/'.rawurlencode($recordId));
    }

    /**
     * Get the summary text (Markdown) that the service holds for pickup
     *
     * @throws ConnectionException
     */
    public function getSummary(string $recordId): Response
    {
        return $this->getHttpClient()
            ->accept('text/markdown')
            ->get('api/v1/registrations/'.rawurlencode($recordId).'/summary');
    }

    /**
     * Confirm the pickup of the summary text, the service then deletes it
     *
     * @throws ConnectionException
     */
    public function confirmSummary(string $recordId): Response
    {
        return $this->getHttpClient()->delete('api/v1/registrations/'.rawurlencode($recordId).'/summary');
    }

    /**
     * Indicates whether a failed request should be retried (rate limit or server error)
     */
    public static function isRetryable(Response $response): bool
    {
        return $response->tooManyRequests() || $response->serverError();
    }
}
