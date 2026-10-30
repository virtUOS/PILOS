<?php

declare(strict_types=1);

namespace Tests\Backend\Feature\api\v1\Room;

use App\Enums\RoomUserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Factories\RoomFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Backend\TestCase;

/**
 * Tests for the meeting summary (recap) room setting
 */
class RoomRecapTest extends TestCase
{
    use RefreshDatabase;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config(['recap.enabled' => true]);

        $owner = User::factory()->create([
            'authenticator' => 'oidc',
            'oidc_sub' => 'fixture-sub-ada-0001',
        ]);
        $this->room = Room::factory()->create([
            'user_id' => $owner->id,
            'expert_mode' => false,
            'recap' => false,
        ]);
    }

    /**
     * Build the settings payload for a room update without expert mode
     */
    private function settingsPayload(bool $recap): array
    {
        return [
            'access_code' => null,
            'room_type' => $this->room->roomType->id,
            'expert_mode' => false,
            'name' => RoomFactory::createValidRoomName(),
            'short_description' => null,
            'allow_guests' => false,
            'recap' => $recap,
        ];
    }

    public function test_owner_can_change_recap()
    {
        $this->actingAs($this->room->owner)->getJson(route('api.v1.rooms.settings', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', false)
            ->assertJsonPath('data.recap_available', true);

        $this->actingAs($this->room->owner)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(true))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', true);

        $this->assertTrue($this->room->refresh()->recap);

        $this->actingAs($this->room->owner)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(false))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', false);

        $this->assertFalse($this->room->refresh()->recap);

        // Invalid value
        $this->actingAs($this->room->owner)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), array_merge($this->settingsPayload(true), ['recap' => 'yes']))
            ->assertJsonValidationErrors(['recap']);
    }

    public function test_others_cannot_change_recap()
    {
        $coOwner = User::factory()->create(['oidc_sub' => 'fixture-sub-dup-0007']);
        $this->room->members()->attach($coOwner, ['role' => RoomUserRole::CO_OWNER]);

        $member = User::factory()->create();
        $this->room->members()->attach($member, ['role' => RoomUserRole::MODERATOR]);

        $admin = User::factory()->create(['oidc_sub' => 'fixture-sub-admin-0010']);
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'rooms.manage')->first());
        $admin->roles()->attach($role);

        // Co-owner and admin can update the settings, but the recap value is ignored
        foreach ([$coOwner, $admin] as $user) {
            $this->actingAs($user)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(true))
                ->assertSuccessful()
                ->assertJsonPath('data.recap', false);

            $this->assertFalse($this->room->refresh()->recap);
        }

        // Value set by the owner is kept, even if others send a different value
        $this->room->recap = true;
        $this->room->save();
        $this->actingAs($coOwner)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(false))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', true);
        $this->assertTrue($this->room->refresh()->recap);

        // Members cannot update the settings at all
        $this->actingAs($member)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(false))
            ->assertForbidden();
        $this->assertTrue($this->room->refresh()->recap);
    }

    public function test_recap_unavailable_without_oidc_sub()
    {
        $this->room->owner->oidc_sub = null;
        $this->room->owner->save();

        $this->actingAs($this->room->owner)->getJson(route('api.v1.rooms.settings', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.recap_available', false);

        // Value is ignored while the feature is unavailable
        $this->actingAs($this->room->owner)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(true))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', false);
        $this->assertFalse($this->room->refresh()->recap);
    }

    public function test_recap_unavailable_if_disabled()
    {
        config(['recap.enabled' => false]);

        $this->actingAs($this->room->owner)->getJson(route('api.v1.rooms.settings', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.recap_available', false);

        $this->actingAs($this->room->owner)->putJson(route('api.v1.rooms.update', ['room' => $this->room]), $this->settingsPayload(true))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', false);
        $this->assertFalse($this->room->refresh()->recap);
    }

    public function test_recap_enforced_off_by_room_type()
    {
        $roomType = RoomType::factory()->create([
            'recap_default' => false,
            'recap_enforced' => true,
        ]);
        $this->room->roomType()->associate($roomType);
        $this->room->recap = true;
        $this->room->save();

        $this->assertFalse($this->room->getRoomSetting('recap'));

        $this->actingAs($this->room->owner)->getJson(route('api.v1.rooms.settings', ['room' => $this->room]))
            ->assertSuccessful()
            ->assertJsonPath('data.recap', false)
            ->assertJsonPath('data.room_type.recap_default', false)
            ->assertJsonPath('data.room_type.recap_enforced', true);
    }

    public function test_new_room_uses_room_type_default()
    {
        $roomType = RoomType::factory()->create(['recap_default' => true]);
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'rooms.create')->first());
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->postJson(route('api.v1.rooms.store'), [
            'name' => RoomFactory::createValidRoomName(),
            'room_type' => $roomType->id,
        ])->assertCreated();

        $this->assertTrue(Room::find($response->json('data.id'))->recap);
    }
}
