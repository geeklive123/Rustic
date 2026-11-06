<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseCashShiftRequest;
use App\Http\Requests\OpenCashShiftRequest;
use App\Http\Resources\CashShiftResource;
use App\Models\CashShift;
use App\Services\CashShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashShiftController extends Controller
{
    public function open(OpenCashShiftRequest $request, CashShiftService $service): JsonResponse
    {
        [$shift, $replayed] = $service->open($request->user(), $request->validated());

        return response()->json(['data' => (new CashShiftResource($shift))->resolve($request), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function current(Request $request): JsonResponse
    {
        $shift = CashShift::where('active_user_id', $request->user()->id)->first();

        return response()->json(['data' => $shift ? (new CashShiftResource($shift))->resolve($request) : null]);
    }

    public function close(CloseCashShiftRequest $request, int $id, CashShiftService $service): JsonResponse
    {
        [$shift, $replayed] = $service->close($request->user(), $id, $request->validated());

        return response()->json(['data' => (new CashShiftResource($shift))->resolve($request), 'replayed' => $replayed]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = CashShift::query()->withSum(['payments as live_cash_cents' => fn ($q) => $q->where('method', 'CASH')->where('status', 'cash_collected')], 'amount_cents');
        if ($request->user()->role !== Role::Administrator) {
            $query->where('user_id', $request->user()->id);
        }
        $shifts = $query->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $shifts->getCollection()->map(fn ($s) => (new CashShiftResource($s))->resolve($request))->all(),
            'meta' => ['current_page' => $shifts->currentPage(), 'last_page' => $shifts->lastPage(), 'per_page' => $shifts->perPage(), 'total' => $shifts->total()]]);
    }
}
