<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\RoomService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Context;

class StartMeetingRequest extends FormRequest
{
    public function rules(): array
    {
        $personalizedLink = Context::getHidden("room.{$this->room->id}.personalized_link");

        $rules = [
            'name' => auth()->check() || $personalizedLink ? [] : ValidateParticipantNameRequest::participantNameValidationRules(),
            'dark_mode' => ['sometimes', 'boolean'],
        ];

        $rules += $this->getAttendanceRecordingRules();
        $rules += $this->getRecordingRules();
        $rules += $this->getStreamingRules();

        return $rules;

    }

    private function getAttendanceRecordingRules(): array
    {
        if ($this->room->getRoomSetting('record_attendance')) {
            return [
                'consent_record_attendance' => ['required', 'boolean', 'accepted'],
            ];
        }

        return [];
    }

    private function getRecordingRules(): array
    {
        if ($this->room->getRoomSetting('record')) {
            return [
                'consent_record' => ['required', 'boolean', 'accepted'], // Consent to join meeting with recording enabled
                'consent_record_video' => ['required', 'boolean'], // Permission to record own video
            ];
        }

        // Meeting summary without recording, the camera video is not recorded
        if ($this->isSummaryOnly()) {
            return [
                'consent_record' => ['required', 'boolean', 'accepted'], // Consent to join meeting with meeting summary enabled
            ];
        }

        return [];
    }

    /**
     * Custom messages for the meeting summary consent, which shares the field with the recording consent
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        if ($this->isSummaryOnly()) {
            return [
                'consent_record.accepted' => __('validation.custom.consent_recap.accepted'),
            ];
        }

        return [];
    }

    /**
     * Custom attribute names for the meeting summary consent, which shares the field with the recording consent
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        if ($this->isSummaryOnly()) {
            return [
                'consent_record' => __('validation.attributes.consent_recap'),
            ];
        }

        return [];
    }

    /**
     * Whether the meeting creates a meeting summary without a recording
     */
    private function isSummaryOnly(): bool
    {
        if ($this->room->getRoomSetting('record')) {
            return false;
        }

        return (new RoomService($this->room))->isRecapActive();
    }

    private function getStreamingRules(): array
    {
        $streaming = $this->room->streaming;
        if ($streaming->enabled) {
            return [
                'consent_streaming' => ['required', 'boolean', 'accepted'],
            ];
        }

        return [];
    }
}
