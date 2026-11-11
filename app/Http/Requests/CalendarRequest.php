<?php

namespace App\Http\Requests;

use App\Exceptions\ScheduleApiException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\Validator;

class CalendarRequest extends ScheduleApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'date'],
            'to' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'date'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
            throw new ScheduleApiException('INVALID_DATE_RANGE', 'Укажите существующие даты from и to в формате YYYY-MM-DD.', 422, [
                'fields' => $validator->errors()->toArray(),
            ]);
        }

        parent::failedValidation($validator);
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('from'), 'UTC');
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('to'), 'UTC');
            $days = intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
            if ($days < 1 || $days > 62) {
                throw new ScheduleApiException('INVALID_DATE_RANGE', 'Диапазон должен включать от 1 до 62 дней; дата to не включается.', 422, [
                    'from' => $this->input('from'),
                    'to' => $this->input('to'),
                ]);
            }
        }];
    }
}
