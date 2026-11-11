<?php

namespace App\Http\Requests;

use App\Exceptions\ScheduleApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

#[FailOnUnknownFields]
class PublishScheduleRequest extends CalendarRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [...parent::rules(), 'expectedVersion' => ['required', 'integer:strict', 'min:1']];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $unknown = array_diff(array_keys($this->json()->all()), ['expectedVersion', 'from', 'to']);
            if ($unknown) {
                throw new ScheduleApiException('VALIDATION_FAILED', 'Неизвестное поле запроса.', details: ['field' => reset($unknown)]);
            }
        }];
    }
}
