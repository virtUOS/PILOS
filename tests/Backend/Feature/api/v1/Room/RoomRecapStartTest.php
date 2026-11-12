<?php

declare(strict_types=1);

namespace Tests\Backend\Feature\api\v1\Room;

use App\Jobs\CancelRecapRegistration;
use App\Jobs\PollMeetingSummary;
use App\Jobs\RegisterRecapMeeting;
use App\Models\Meeting;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Backend\TestCase;
use Tests\Backend\Utils\BigBlueButtonServerFaker;
use TiMacDonald\Log\LogEntry;
use TiMacDonald\Log\LogFake;

/**
 * Tests for the meeting summary (recap) parameters on meeting start and join, and the cancellation on room deletion
 */
class RoomRecapStartTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_URL = 'https://service.recap.test';

    private const FRONTEND_TOKEN = 'FIXTURE-NOT-A-SECRET-fe-0123456789abcdef0123456789abcdef';

    private const OWNER_SUB = 'fixture-sub-ada-0001';

    private const OWNER_EMAIL = 'ada.testperson@example.org';

    /**
     * Internal meeting id returned by the BigBlueButtonServerFaker
     */
    private const INTERNAL_MEETING_ID = '5756487f8952a40879db59f8fe4085798cb79ccc-1695892370102';

    private const RECAP_META_KEYS = ['meta_recap-v', 'meta_recap-enabled', 'meta_recap-type', 'meta_recap-sink'];

    protected Room $room;

    protected BigBlueButtonServerFaker $bbbFaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'recap.enabled' => true,
            'recap.meta_prefix' => 'recap',
            'recap.service_url' => self::SERVICE_URL,
            'recap.frontend_token' => self::FRONTEND_TOKEN,
        ]);

        $roomType = RoomType::factory()->create([
            'recap_type' => 'meeting-summary',
            'recap_sink' => 'docs-main',
        ]);
        $owner = User::factory()->create([
            'authenticator' => 'oidc',
            'oidc_sub' => self::OWNER_SUB,
            'email' => self::OWNER_EMAIL,
        ]);
        $this->room = Room::factory()->create([
            'room_type_id' => $roomType->id,
            'user_id' => $owner->id,
            'expert_mode' => true,
            'record' => true,
            'record_attendance' => false,
            'recap' => true,
        ]);

        $server = Server::factory()->create();
        $roomType->serverPool->servers()->attach($server);
        $this->bbbFaker = new BigBlueButtonServerFaker($server->base_url, $server->secret);
    }

    /**
     * Start the room as owner and return the query parameters of the BBB create call
     */
    private function startRoom(): array
    {
        $this->bbbFaker->addCreateMeetingRequest();
        $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.start', ['room' => $this->room]), [
            'consent_record_attendance' => false,
            'consent_record' => true,
            'consent_record_video' => false,
        ])->assertSuccessful();

        return $this->bbbFaker->getRequest(0)->data();
    }

    private function recapMetaKeys(array $createParameters): array
    {
        return array_values(array_filter(array_keys($createParameters), fn (string $key) => str_starts_with(strtolower($key), 'meta_recap')));
    }

    public function test_start_with_recap()
    {
        Queue::fake();

        $createParameters = $this->startRoom();

        $this->assertEqualsCanonicalizing(self::RECAP_META_KEYS, $this->recapMetaKeys($createParameters));
        $this->assertEquals('1', $createParameters['meta_recap-v']);
        $this->assertEquals('true', $createParameters['meta_recap-enabled']);
        $this->assertEquals('meeting-summary', $createParameters['meta_recap-type']);
        $this->assertEquals('docs-main', $createParameters['meta_recap-sink']);
        $this->assertEquals('PILOS', $createParameters['meta_bbb-origin']);

        $meeting = $this->room->refresh()->latestMeeting;
        $this->assertTrue($meeting->recap);
        $this->assertEquals(self::INTERNAL_MEETING_ID, $meeting->internal_meeting_id);
        $this->assertEquals($meeting->id, $createParameters['meetingID']);

        Queue::assertPushed(RegisterRecapMeeting::class, 1);
        Queue::assertPushed(RegisterRecapMeeting::class, fn (RegisterRecapMeeting $job) => $job->meeting->is($meeting) && $job->owner->is($this->room->owner));
    }

    public function test_start_with_configured_meta_prefix()
    {
        Queue::fake();
        config(['recap.meta_prefix' => 'summary']);

        $createParameters = $this->startRoom();

        $this->assertEquals([], $this->recapMetaKeys($createParameters));
        $this->assertEquals('1', $createParameters['meta_summary-v']);
        $this->assertEquals('true', $createParameters['meta_summary-enabled']);
        $this->assertEquals('meeting-summary', $createParameters['meta_summary-type']);
        $this->assertEquals('docs-main', $createParameters['meta_summary-sink']);
    }

    /**
     * The meeting end reaches PILOS through the signed meetingEndedURL; only a valid callback ends the meeting and
     * starts polling for the summary, an unsigned callback (old salt URL) is rejected and the server poll ends the meeting later
     */
    public function test_end_callback_dispatches_summary_poll()
    {
        Queue::fake();

        $createParameters = $this->startRoom();
        $meeting = $this->room->refresh()->latestMeeting;

        $this->assertArrayNotHasKey('meta_endCallbackUrl', $createParameters);
        $callbackUrl = $createParameters['meetingEndedURL'];
        $this->assertStringContainsString('signature=', $callbackUrl);

        $this->get(route('api.v1.meetings.endcallback', ['meeting' => $meeting]))->assertUnauthorized();
        $this->assertNull($meeting->fresh()->end);
        Queue::assertNotPushed(PollMeetingSummary::class);

        // BBB may append parameters to the callback URL
        $this->get($callbackUrl.'&recordingmarks=true&meetingID='.$meeting->id)->assertSuccessful();
        $this->assertNotNull($meeting->fresh()->end);
        Queue::assertPushed(PollMeetingSummary::class, 1);
        Queue::assertPushed(PollMeetingSummary::class, fn (PollMeetingSummary $job) => $job->meeting->is($meeting));
    }

    public function test_start_registers_owner()
    {
        Http::fake([self::SERVICE_URL.'/*' => Http::response(['status' => 'registered'], 201)]);

        $this->startRoom();

        $meeting = $this->room->refresh()->latestMeeting;
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::SERVICE_URL.'/api/v1/registrations/'.self::INTERNAL_MEETING_ID
            && $request->hasHeader('Authorization', 'Bearer '.self::FRONTEND_TOKEN)
            && $request->data() === [
                'schema_version' => '1.0.0',
                'meeting_id' => $meeting->id,
                'record_id' => self::INTERNAL_MEETING_ID,
                'owner' => [
                    'sub' => self::OWNER_SUB,
                    'email' => self::OWNER_EMAIL,
                ],
            ]);
    }

    public static function recapConditionProvider(): array
    {
        return [
            'feature disabled' => ['feature_disabled'],
            'invalid meta prefix' => ['invalid_meta_prefix'],
            'room setting off' => ['room_setting_off'],
            'room setting enforced off by room type' => ['enforced_off'],
            'owner without OIDC subject' => ['owner_without_sub'],
            'room type without meeting type' => ['no_recap_type'],
            'room type without sink' => ['no_recap_sink'],
        ];
    }

    /**
     * Each condition alone prevents the meeting summary. The stored room value stays true in all cases except
     * 'room_setting_off', like a room type default copied into the room at creation.
     */
    #[DataProvider('recapConditionProvider')]
    public function test_start_without_recap(string $condition)
    {
        Queue::fake();

        match ($condition) {
            'feature_disabled' => config(['recap.enabled' => false]),
            'invalid_meta_prefix' => config(['recap.meta_prefix' => 'Recap']),
            'room_setting_off' => $this->room->forceFill(['recap' => false])->save(),
            'enforced_off' => $this->room->roomType->forceFill(['recap_default' => false, 'recap_enforced' => true])->save(),
            'owner_without_sub' => $this->room->owner->forceFill(['oidc_sub' => null])->save(),
            'no_recap_type' => $this->room->roomType->forceFill(['recap_type' => null])->save(),
            'no_recap_sink' => $this->room->roomType->forceFill(['recap_sink' => null])->save(),
        };

        $createParameters = $this->startRoom();

        $this->assertEquals([], $this->recapMetaKeys($createParameters));

        $meeting = $this->room->refresh()->latestMeeting;
        $this->assertFalse($meeting->recap);
        $this->assertEquals(self::INTERNAL_MEETING_ID, $meeting->internal_meeting_id);

        Queue::assertNotPushed(RegisterRecapMeeting::class);
    }

    public static function createParameterProvider(): array
    {
        $cases = [];
        foreach ([true, false] as $recap) {
            foreach ([true, false] as $record) {
                foreach ([true, false] as $autoStart) {
                    $label = ($recap ? 'summary on' : 'summary off').', '.($record ? 'recording on' : 'recording off').', '.($autoStart ? 'auto start on' : 'auto start off');
                    $cases[$label] = [$recap, $record, $autoStart];
                }
            }
        }

        return $cases;
    }

    /**
     * BBB records the meeting if the room asks for a recording or a meeting summary,
     * the automatic recording start always follows the room
     */
    #[DataProvider('createParameterProvider')]
    public function test_start_record_parameters(bool $recap, bool $record, bool $autoStart)
    {
        Queue::fake();
        $this->room->forceFill(['recap' => $recap, 'record' => $record, 'auto_start_recording' => $autoStart])->save();

        $createParameters = $this->startRoom();

        $this->assertEquals($recap || $record ? 'true' : 'false', $createParameters['record']);
        $this->assertEquals($autoStart ? 'true' : 'false', $createParameters['autoStartRecording']);
        $this->assertEqualsCanonicalizing($recap ? self::RECAP_META_KEYS : [], $this->recapMetaKeys($createParameters));

        // The meeting keeps the recording setting of the room
        $meeting = $this->room->refresh()->latestMeeting;
        $this->assertSame($record, $meeting->record);
        $this->assertSame($recap, $meeting->recap);
    }

    /**
     * Fake response of the BBB getMeetingInfo call for a running meeting
     */
    private function addRunningMeetingInfoRequest(): void
    {
        $this->bbbFaker->addRequest(fn (Request $request) => Http::response('
            <response>
                <returncode>SUCCESS</returncode>
                <meetingName>test</meetingName>
                <meetingID>'.$this->room->latestMeeting->id.'</meetingID>
                <internalMeetingID>'.self::INTERNAL_MEETING_ID.'</internalMeetingID>
                <running>true</running>
                <hasUserJoined>true</hasUserJoined>
                <recording>false</recording>
                <hasBeenForciblyEnded>false</hasBeenForciblyEnded>
                <participantCount>0</participantCount>
                <listenerCount>0</listenerCount>
                <voiceParticipantCount>0</voiceParticipantCount>
                <videoCount>0</videoCount>
                <moderatorCount>0</moderatorCount>
                <isBreakout>false</isBreakout>
            </response>'));
    }

    /**
     * Summary-only meeting: consent is required on start and join, camera video is never recorded
     */
    public function test_start_and_join_summary_only()
    {
        Queue::fake();
        $this->room->forceFill(['record' => false])->save();

        // Start requirements and consent
        $this->actingAs($this->room->owner)->optionsJson(route('api.v1.rooms.start-requirements', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.features.recording', false)
            ->assertJsonPath('data.features.recap', true);

        $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.start', ['room' => $this->room]), ['consent_record' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_record' => __('validation.custom.consent_recap.accepted')])
            ->assertJsonMissingValidationErrors(['consent_record_video']);

        $this->bbbFaker->addCreateMeetingRequest();
        $joinUrl = $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.start', ['room' => $this->room]), ['consent_record' => true, 'consent_record_video' => true])
            ->assertSuccessful()
            ->json('url');
        $this->assertStringContainsString('userdata-bbb_record_video=false', $joinUrl);

        // Join requirements and consent
        $this->room->refresh();
        $this->actingAs($this->room->owner)->optionsJson(route('api.v1.rooms.join-requirements', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.features.recording', false)
            ->assertJsonPath('data.features.recap', true);

        $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.join', ['room' => $this->room]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_record' => __('validation.required', ['attribute' => __('validation.attributes.consent_recap')])])
            ->assertJsonMissingValidationErrors(['consent_record_video']);

        $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.join', ['room' => $this->room]), ['consent_record' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_record' => __('validation.custom.consent_recap.accepted')]);

        $this->addRunningMeetingInfoRequest();
        $joinUrl = $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.join', ['room' => $this->room]), ['consent_record' => true, 'consent_record_video' => true])
            ->assertSuccessful()
            ->json('url');
        $this->assertStringContainsString('userdata-bbb_record_video=false', $joinUrl);
    }

    /**
     * Meeting with recording and summary: the consent to record the own video is asked as for a recording
     */
    public function test_join_recording_and_summary()
    {
        Queue::fake();

        $this->startRoom();
        $this->room->refresh();

        $this->actingAs($this->room->owner)->optionsJson(route('api.v1.rooms.join-requirements', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.features.recording', true)
            ->assertJsonPath('data.features.recap', true);

        $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.join', ['room' => $this->room]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'consent_record' => __('validation.required', ['attribute' => __('validation.attributes.consent_record')]),
                'consent_record_video',
            ]);

        $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.join', ['room' => $this->room]), ['consent_record' => false, 'consent_record_video' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_record' => __('validation.custom.consent_record.accepted')]);

        $this->addRunningMeetingInfoRequest();
        $joinUrl = $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.join', ['room' => $this->room]), ['consent_record' => true, 'consent_record_video' => true])
            ->assertSuccessful()
            ->json('url');
        $this->assertStringContainsString('userdata-bbb_record_video=true', $joinUrl);
    }

    /**
     * Without recording and summary no consent is needed and the video parameter is not passed
     */
    public function test_start_without_recording_and_summary()
    {
        Queue::fake();
        $this->room->forceFill(['record' => false, 'recap' => false])->save();

        $this->actingAs($this->room->owner)->optionsJson(route('api.v1.rooms.start-requirements', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.features.recording', false)
            ->assertJsonPath('data.features.recap', false);

        $this->bbbFaker->addCreateMeetingRequest();
        $joinUrl = $this->actingAs($this->room->owner)->postJson(route('api.v1.rooms.start', ['room' => $this->room]))
            ->assertSuccessful()
            ->json('url');
        $this->assertStringNotContainsString('userdata-bbb_record_video', $joinUrl);
    }

    public function test_room_deletion_cancels_registrations()
    {
        Queue::fake();

        $eligible = Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => true, 'internal_meeting_id' => 'record-id-a-1695892370102']);
        Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => true, 'internal_meeting_id' => 'record-id-b-1695892370103']);
        Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => false, 'internal_meeting_id' => 'record-id-c-1695892370104']);
        Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => true, 'internal_meeting_id' => null]);
        Meeting::factory()->create(['recap' => true, 'internal_meeting_id' => 'record-id-other-room']);

        $this->actingAs($this->room->owner)->deleteJson(route('api.v1.rooms.destroy', ['room' => $this->room]))
            ->assertNoContent();

        $this->assertModelMissing($eligible);

        Queue::assertPushed(CancelRecapRegistration::class, 2);
        Queue::assertPushed(CancelRecapRegistration::class, fn (CancelRecapRegistration $job) => $job->recordId === 'record-id-a-1695892370102');
        Queue::assertPushed(CancelRecapRegistration::class, fn (CancelRecapRegistration $job) => $job->recordId === 'record-id-b-1695892370103');
    }

    public function test_room_deletion_without_service_configuration()
    {
        Queue::fake();
        Log::swap(new LogFake);
        config(['recap.frontend_token' => null]);

        Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => true, 'internal_meeting_id' => 'record-id-a-1695892370102']);
        Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => true, 'internal_meeting_id' => 'record-id-b-1695892370103']);

        $this->actingAs($this->room->owner)->deleteJson(route('api.v1.rooms.destroy', ['room' => $this->room]))
            ->assertNoContent();

        Queue::assertNotPushed(CancelRecapRegistration::class);
        Log::assertLogged(fn (LogEntry $log) => $log->level === 'warning'
            && $log->context['room'] === $this->room->getLogLabel()
            && $log->context['count'] === 2);
        Log::assertNotLogged(fn (LogEntry $log) => str_contains($log->message.json_encode($log->context), self::OWNER_SUB)
            || str_contains($log->message.json_encode($log->context), self::OWNER_EMAIL));
    }

    public function test_owner_deletion_cancels_registrations()
    {
        Queue::fake();

        Meeting::factory()->create(['room_id' => $this->room->id, 'recap' => true, 'internal_meeting_id' => 'record-id-a-1695892370102']);

        $this->room->owner->delete();

        Queue::assertPushed(CancelRecapRegistration::class, fn (CancelRecapRegistration $job) => $job->recordId === 'record-id-a-1695892370102');
    }
}
