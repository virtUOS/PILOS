<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\MeetingService;
use BigBlueButton\Parameters\CreateMeetingParameters;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

class CustomCreateMeetingParameters implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $meetingParams = new CreateMeetingParameters('demo', 'demo');
        $errors = MeetingService::setCustomCreateMeetingParameters($meetingParams, $value);
        foreach ($errors as $error) {
            $fail($error);
        }

        // Meta parameters of the meeting summary (recap) are reserved, they are set per room by the application
        $reservedPrefix = 'meta_'.Str::lower(config('recap.meta_prefix')).'-';
        foreach (preg_split('/\n|\r\n?/', $value) as $createParameter) {
            $parameter = trim(explode('=', $createParameter, 2)[0]);
            if (Str::startsWith(Str::lower($parameter), $reservedPrefix)) {
                $fail(__('validation.custom_parameter_reserved', ['parameter' => $parameter]));
            }
        }
    }
}
