<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RecordingAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingSummaryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access' => ['required', Rule::enum(RecordingAccess::class)],
        ];
    }
}
