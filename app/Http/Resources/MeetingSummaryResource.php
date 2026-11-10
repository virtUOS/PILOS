<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingSummaryResource extends JsonResource
{
    /**
     * Include the summary text
     */
    private bool $withContent = false;

    /**
     * Sets the flag to also include the summary text.
     *
     * @return $this
     */
    public function withContent(): static
    {
        $this->withContent = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'model_name' => $this->model_name,
            'start' => $this->start,
            'end' => $this->end,
            'delivered_at' => $this->delivered_at,
            'access' => $this->access,
            'type' => $this->document_url !== null ? 'document' : 'content',
            'document_url' => $this->document_url,
            'content' => $this->when($this->withContent, fn () => $this->content),
        ];
    }
}
