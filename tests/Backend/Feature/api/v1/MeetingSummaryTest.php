<?php

declare(strict_types=1);

namespace Tests\Backend\Feature\api\v1;

use App\Enums\RecordingAccess;
use App\Enums\RoomUserRole;
use App\Models\Meeting;
use App\Models\MeetingSummary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Backend\TestCase;

/**
 * Meeting summary tests (list, view, change access, delete)
 */
class MeetingSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected Room $room;

    /**
     * Summaries of the room by access
     *
     * @var array<string, MeetingSummary>
     */
    protected array $summaries;

    protected User $participant;

    protected User $moderator;

    protected User $coOwner;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->room = Room::factory()->create(['allow_guests' => true, 'access_code' => null]);

        $this->participant = User::factory()->create();
        $this->moderator = User::factory()->create();
        $this->coOwner = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->room->members()->attach($this->participant, ['role' => RoomUserRole::USER]);
        $this->room->members()->attach($this->moderator, ['role' => RoomUserRole::MODERATOR]);
        $this->room->members()->attach($this->coOwner, ['role' => RoomUserRole::CO_OWNER]);

        $this->summaries = [];
        foreach (['everyone' => RecordingAccess::EVERYONE, 'participant' => RecordingAccess::PARTICIPANT, 'moderator' => RecordingAccess::MODERATOR, 'owner' => RecordingAccess::OWNER] as $name => $access) {
            $this->summaries[$name] = MeetingSummary::factory()->create([
                'meeting_id' => Meeting::factory()->create(['room_id' => $this->room->id])->id,
                'access' => $access,
            ]);
        }
    }

    /**
     * Visible summaries per user, keyed by the access names of setUp()
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function visibilityProvider(): array
    {
        return [
            'guest' => ['guest', ['everyone']],
            'other user' => ['otherUser', ['everyone']],
            'participant' => ['participant', ['everyone', 'participant']],
            'moderator' => ['moderator', ['everyone', 'participant', 'moderator']],
            'co-owner' => ['coOwner', ['everyone', 'participant', 'moderator', 'owner']],
            'owner' => ['owner', ['everyone', 'participant', 'moderator', 'owner']],
        ];
    }

    private function actAs(string $user): void
    {
        match ($user) {
            'guest' => Auth::logout(),
            'owner' => $this->actingAs($this->room->owner),
            default => $this->actingAs($this->{$user}),
        };
    }

    /**
     * @param  list<string>  $visible
     */
    #[DataProvider('visibilityProvider')]
    public function test_index_access(string $user, array $visible)
    {
        $this->actAs($user);

        $response = $this->getJson(route('api.v1.rooms.meetingSummaries.index', ['room' => $this->room]))
            ->assertOk()
            ->assertJsonPath('meta.total_no_filter', count($visible));

        $expectedIds = collect($visible)->map(fn ($name) => $this->summaries[$name]->id)->sort()->values()->all();
        $this->assertSame($expectedIds, collect($response->json('data'))->pluck('id')->sort()->values()->all());

        // The list never contains the summary text
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('content', $item);
            $this->assertSame('content', $item['type']);
            $this->assertSame('MeetingSummary', $item['model_name']);
        }
    }

    /**
     * @param  list<string>  $visible
     */
    #[DataProvider('visibilityProvider')]
    public function test_show_access(string $user, array $visible)
    {
        $this->actAs($user);

        foreach ($this->summaries as $name => $summary) {
            $response = $this->getJson(route('api.v1.rooms.meetingSummaries.show', ['room' => $this->room, 'meetingSummary' => $summary]));
            if (in_array($name, $visible)) {
                $response->assertOk()
                    ->assertJsonPath('data.id', $summary->id)
                    ->assertJsonPath('data.content', $summary->content)
                    ->assertJsonPath('data.type', 'content');
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_view_all_permission()
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'rooms.viewAll')->first());
        $this->otherUser->roles()->attach($role);

        $this->actingAs($this->otherUser)
            ->getJson(route('api.v1.rooms.meetingSummaries.index', ['room' => $this->room]))
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->getJson(route('api.v1.rooms.meetingSummaries.show', ['room' => $this->room, 'meetingSummary' => $this->summaries['owner']]))
            ->assertOk();
    }

    public function test_index_filter_and_sort()
    {
        $this->summaries['everyone']->update(['start' => now()->subDays(3)]);
        $this->summaries['participant']->update(['start' => now()->subDays(2)]);
        $this->summaries['moderator']->update(['start' => now()->subDays(1)]);
        $this->summaries['owner']->update(['start' => now()]);

        $this->actingAs($this->room->owner);

        // Newest first by default
        $this->getJson(route('api.v1.rooms.meetingSummaries.index', ['room' => $this->room]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->summaries['owner']->id)
            ->assertJsonPath('data.3.id', $this->summaries['everyone']->id);

        $this->getJson(route('api.v1.rooms.meetingSummaries.index', ['room' => $this->room, 'sort_direction' => 'asc']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->summaries['everyone']->id);

        $this->getJson(route('api.v1.rooms.meetingSummaries.index', ['room' => $this->room, 'filter' => 'moderator_access']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->summaries['moderator']->id)
            ->assertJsonPath('meta.total_no_filter', 4);

        $this->getJson(route('api.v1.rooms.meetingSummaries.index', ['room' => $this->room, 'filter' => 'invalid']))
            ->assertUnprocessable();
    }

    public function test_document_summary()
    {
        $summary = MeetingSummary::factory()->document()->create([
            'meeting_id' => Meeting::factory()->create(['room_id' => $this->room->id])->id,
            'access' => RecordingAccess::EVERYONE,
        ]);

        $this->getJson(route('api.v1.rooms.meetingSummaries.show', ['room' => $this->room, 'meetingSummary' => $summary]))
            ->assertOk()
            ->assertJsonPath('data.type', 'document')
            ->assertJsonPath('data.document_url', $summary->document_url)
            ->assertJsonPath('data.content', null);
    }

    public function test_show_summary_of_other_room()
    {
        $otherSummary = MeetingSummary::factory()->create(['access' => RecordingAccess::EVERYONE]);

        $this->actingAs($this->room->owner)
            ->getJson(route('api.v1.rooms.meetingSummaries.show', ['room' => $this->room, 'meetingSummary' => $otherSummary]))
            ->assertNotFound();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function manageProvider(): array
    {
        return [
            'guest' => ['guest', 401],
            'other user' => ['otherUser', 403],
            'participant' => ['participant', 403],
            'moderator' => ['moderator', 403],
            'co-owner' => ['coOwner', 200],
            'owner' => ['owner', 200],
        ];
    }

    #[DataProvider('manageProvider')]
    public function test_update_access(string $user, int $status)
    {
        $summary = $this->summaries['owner'];
        $this->actAs($user);

        $response = $this->putJson(route('api.v1.rooms.meetingSummaries.update', ['room' => $this->room, 'meetingSummary' => $summary]), ['access' => RecordingAccess::PARTICIPANT->value])
            ->assertStatus($status);

        $summary->refresh();
        if ($status === 200) {
            $response->assertJsonPath('data.access', RecordingAccess::PARTICIPANT->value);
            $this->assertSame(RecordingAccess::PARTICIPANT, $summary->access);
        } else {
            $this->assertSame(RecordingAccess::OWNER, $summary->access);
        }
    }

    public function test_update_invalid_data()
    {
        $summary = $this->summaries['owner'];
        $this->actingAs($this->room->owner);

        $this->putJson(route('api.v1.rooms.meetingSummaries.update', ['room' => $this->room, 'meetingSummary' => $summary]), ['access' => 7])
            ->assertJsonValidationErrors(['access']);
        $this->putJson(route('api.v1.rooms.meetingSummaries.update', ['room' => $this->room, 'meetingSummary' => $summary]), [])
            ->assertJsonValidationErrors(['access']);

        // Summary of another room
        $otherSummary = MeetingSummary::factory()->create();
        $this->putJson(route('api.v1.rooms.meetingSummaries.update', ['room' => $this->room, 'meetingSummary' => $otherSummary]), ['access' => RecordingAccess::EVERYONE->value])
            ->assertNotFound();
    }

    #[DataProvider('manageProvider')]
    public function test_delete(string $user, int $status)
    {
        $summary = $this->summaries['everyone'];
        $this->actAs($user);

        $this->deleteJson(route('api.v1.rooms.meetingSummaries.destroy', ['room' => $this->room, 'meetingSummary' => $summary]))
            ->assertStatus($status === 200 ? 204 : $status);

        if ($status === 200) {
            $this->assertModelMissing($summary);

            $this->deleteJson(route('api.v1.rooms.meetingSummaries.destroy', ['room' => $this->room, 'meetingSummary' => $summary]))
                ->assertNotFound()
                ->assertJson(['message' => 'model_not_found', 'model' => 'meeting_summary']);
        } else {
            $this->assertModelExists($summary);
        }
    }

    public function test_manage_permission()
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'rooms.manage')->first());
        $this->otherUser->roles()->attach($role);

        $this->actingAs($this->otherUser)
            ->putJson(route('api.v1.rooms.meetingSummaries.update', ['room' => $this->room, 'meetingSummary' => $this->summaries['owner']]), ['access' => RecordingAccess::EVERYONE->value])
            ->assertOk();
        $this->deleteJson(route('api.v1.rooms.meetingSummaries.destroy', ['room' => $this->room, 'meetingSummary' => $this->summaries['owner']]))
            ->assertNoContent();
    }

    public function test_delete_with_room()
    {
        $otherSummary = MeetingSummary::factory()->create();

        $this->actingAs($this->room->owner)
            ->deleteJson(route('api.v1.rooms.destroy', ['room' => $this->room]))
            ->assertNoContent();

        foreach ($this->summaries as $summary) {
            $this->assertModelMissing($summary);
        }
        $this->assertModelExists($otherSummary);
    }

    public function test_room_shows_whether_it_has_summaries()
    {
        $this->getJson(route('api.v1.rooms.show', ['room' => $this->room]))
            ->assertOk()
            ->assertJsonPath('data.has_meeting_summaries', true);

        $this->room->meetingSummaries()->delete();

        $this->getJson(route('api.v1.rooms.show', ['room' => $this->room]))
            ->assertOk()
            ->assertJsonPath('data.has_meeting_summaries', false);
    }
}
