<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecordingAccess;
use App\Models\Meeting;
use App\Models\MeetingSummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingSummary>
 */
class MeetingSummaryFactory extends Factory
{
    protected $model = MeetingSummary::class;

    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'room_id' => fn (array $attributes) => Meeting::find($attributes['meeting_id'])->room_id,
            'access' => RecordingAccess::OWNER,
            'start' => fn (array $attributes) => Meeting::find($attributes['meeting_id'])->start,
            'end' => fn (array $attributes) => Meeting::find($attributes['meeting_id'])->end,
            'document_url' => null,
            'content' => "# Meeting summary\n\nFixture summary text.\n",
            'delivered_at' => now(),
        ];
    }

    /**
     * Summary delivered as a link to a document in the sink
     */
    public function document(): static
    {
        return $this->state(fn () => [
            'document_url' => 'https://docs.recap.test/docs/'.$this->faker->uuid().'/',
            'content' => null,
        ]);
    }
}
