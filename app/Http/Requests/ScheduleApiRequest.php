<?php

namespace App\Http\Requests;

use App\Exceptions\ScheduleApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class ScheduleApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isMethod('GET') || $this->isMethod('HEAD')
            ? $this->query->all()
            : $this->json()->all();
    }

    protected function failedValidation(Validator $validator): void
    {
        $field = $validator->errors()->keys()[0] ?? null;
        $details = ['fields' => $validator->errors()->toArray(), 'field' => $field];
        if ($field && preg_match('/^changes\.(\d+)/', $field, $match)) {
            $details['employeeId'] = $this->input('changes.'.$match[1].'.employeeId');
            $details['workDate'] = $this->input('changes.'.$match[1].'.workDate');
        }

        throw new ScheduleApiException('VALIDATION_FAILED', 'Проверьте формат и обязательные поля запроса.', details: $details);
    }
}
