<?php

declare(strict_types=1);

namespace Tests\Backend\Feature\api\v1;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Backend\TestCase;

/**
 * Tests for the meeting summary (recap) settings of the room types
 */
class RoomTypeRecapTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config(['recap.enabled' => true]);

        $this->user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'roomTypes.update')->first());
        $this->user->roles()->attach($role);

        $this->roomType = RoomType::factory()->create()->refresh();
    }

    /**
     * Build a valid update payload for the room type
     */
    private function payload(array $overrides = []): array
    {
        $data = [
            'name' => $this->roomType->name,
            'color' => $this->roomType->color,
            'description' => $this->roomType->description,
            'server_pool' => $this->roomType->serverPool->id,
            'restrict' => false,
            'max_duration' => null,
            'max_participants' => null,
            'has_access_code_default' => false,
            'has_access_code_enforced' => false,
            'create_parameters' => null,
            'join_parameters' => null,
            'recap_type' => 'lecture',
            'recap_sink' => 'docs',
            'updated_at' => $this->roomType->updated_at,
        ];

        foreach (Room::ROOM_SETTINGS_DEFINITION as $setting => $config) {
            $data[$setting.'_default'] = $this->roomType[$setting.'_default'];
            $data[$setting.'_enforced'] = false;
        }

        return array_merge($data, $overrides);
    }

    private function update(array $overrides = [])
    {
        $response = $this->actingAs($this->user)->putJson(route('api.v1.roomTypes.update', ['roomType' => $this->roomType->id]), $this->payload($overrides));
        $this->roomType->refresh();

        return $response;
    }

    public function test_recap_settings_saved()
    {
        $this->update(['recap_default' => true, 'recap_enforced' => false])
            ->assertSuccessful()
            ->assertJsonPath('data.recap_default', true)
            ->assertJsonPath('data.recap_enforced', false)
            ->assertJsonPath('data.recap_type', 'lecture')
            ->assertJsonPath('data.recap_sink', 'docs');

        $this->assertTrue($this->roomType->recap_default);
        $this->assertEquals('lecture', $this->roomType->recap_type);
        $this->assertEquals('docs', $this->roomType->recap_sink);

        $this->actingAs($this->user)->getJson(route('api.v1.roomTypes.show', ['roomType' => $this->roomType->id]))
            ->assertSuccessful()
            ->assertJsonPath('data.recap_type', 'lecture')
            ->assertJsonPath('data.recap_sink', 'docs');
    }

    public function test_recap_cannot_be_enforced_on()
    {
        $this->update(['recap_default' => true, 'recap_enforced' => true])
            ->assertJsonValidationErrors(['recap_default']);

        $this->assertFalse($this->roomType->recap_enforced);

        // Enforced off is allowed, type and sink are not required then
        $this->update(['recap_default' => false, 'recap_enforced' => true, 'recap_type' => null, 'recap_sink' => null])
            ->assertSuccessful()
            ->assertJsonPath('data.recap_enforced', true);
    }

    public function test_recap_type_and_sink_validation()
    {
        // Required if recap is offered
        $this->update(['recap_type' => null, 'recap_sink' => null])
            ->assertJsonValidationErrors(['recap_type', 'recap_sink']);

        // Invalid names
        foreach (['Lecture', '1lecture', '-lecture', 'lec_ture', 'lec ture', 'lécture', 'a'.str_repeat('b', 63)] as $invalid) {
            $this->update(['recap_type' => $invalid, 'recap_sink' => $invalid])
                ->assertJsonValidationErrors(['recap_type', 'recap_sink']);
        }

        // Valid names (maximum length 63)
        $maxLength = 'a'.str_repeat('b', 62);
        $this->update(['recap_type' => 'seminar-2', 'recap_sink' => $maxLength])
            ->assertSuccessful();
        $this->assertEquals('seminar-2', $this->roomType->recap_type);
        $this->assertEquals($maxLength, $this->roomType->recap_sink);

        // Not required if the feature is disabled
        config(['recap.enabled' => false]);
        $this->update(['recap_type' => null, 'recap_sink' => null])
            ->assertSuccessful();
    }

    public function test_reserved_create_parameters_rejected()
    {
        foreach (['meta_recap-v=1', 'META_RECAP-ENABLED=true', "meetingLayout=PRESENTATION_FOCUS\nmeta_Recap-type=lecture"] as $parameters) {
            $this->update(['create_parameters' => $parameters])
                ->assertJsonValidationErrors(['create_parameters']);
        }

        // Other meta parameters are allowed
        $this->update(['create_parameters' => "meta_recapitulation=1\nmeta_other-key=1"])
            ->assertSuccessful();

        // Prefix comes from the config
        config(['recap.meta_prefix' => 'summary']);
        $this->update(['create_parameters' => 'meta_summary-v=1'])
            ->assertJsonValidationErrors(['create_parameters']);
    }
}
