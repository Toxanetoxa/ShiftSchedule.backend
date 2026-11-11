<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveScheduleCellsRequest;
use App\Services\Schedule\SaveScheduleCells;
use Illuminate\Http\JsonResponse;

class SaveScheduleCellsController extends Controller
{
    public function __invoke(SaveScheduleCellsRequest $request, string $scheduleId, SaveScheduleCells $save): JsonResponse
    {
        $input = $request->validated();

        return response()->json($save->execute($scheduleId, $input['expectedVersion'], $input['changes']));
    }
}
