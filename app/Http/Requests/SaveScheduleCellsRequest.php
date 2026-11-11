<?php

namespace App\Http\Requests;

use App\Exceptions\ScheduleApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

#[FailOnUnknownFields]
class SaveScheduleCellsRequest extends ScheduleApiRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'expectedVersion' => ['required', 'integer:strict', 'min:1'],
            'changes' => ['present', 'array', 'list', 'max:2000'],
            'changes.*' => ['required', 'array:employeeId,workDate,shift', 'required_array_keys:employeeId,workDate,shift'],
            'changes.*.employeeId' => ['required', 'string', 'uuid'],
            'changes.*.workDate' => ['required', 'string', 'date_format:Y-m-d'],
            'changes.*.shift' => ['present', 'nullable', 'array:startTime,endTime,endDayOffset,sourceTemplateId', 'required_array_keys:startTime,endTime,endDayOffset,sourceTemplateId'],
            'changes.*.shift.startTime' => ['required_with:changes.*.shift', 'string', 'regex:/\A(?:[01]\d|2[0-3]):[0-5]\d\z/'],
            'changes.*.shift.endTime' => ['required_with:changes.*.shift', 'string', 'regex:/\A(?:[01]\d|2[0-3]):[0-5]\d\z/'],
            'changes.*.shift.endDayOffset' => ['required_with:changes.*.shift', 'integer:strict', 'in:0,1'],
            'changes.*.shift.sourceTemplateId' => ['nullable', 'string', 'uuid'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $unknown = array_diff(array_keys($this->json()->all()), ['expectedVersion', 'changes']);
            if ($unknown) {
                throw new ScheduleApiException('VALIDATION_FAILED', 'Неизвестное поле запроса.', details: ['field' => reset($unknown)]);
            }
            if ($this->input('changes') === []) {
                throw new ScheduleApiException('EMPTY_CHANGESET', 'Список изменённых ячеек не может быть пустым.');
            }
            $seen = [];
            foreach ($this->input('changes') as $index => $change) {
                $key = strtolower($change['employeeId']).':'.$change['workDate'];
                if (isset($seen[$key])) {
                    throw new ScheduleApiException('DUPLICATE_CELL_CHANGE', 'Ячейка повторяется в одном запросе.', details: [
                        'employeeId' => $change['employeeId'], 'workDate' => $change['workDate'], 'field' => "changes.$index",
                    ]);
                }
                $seen[$key] = true;
            }
        }];
    }
}
