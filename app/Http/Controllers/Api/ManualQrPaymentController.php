<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmManualQrRequest;
use App\Http\Resources\OrderResource;
use App\Services\ManualQrPaymentService;
use Illuminate\Http\JsonResponse;

class ManualQrPaymentController extends Controller
{
    public function store(ConfirmManualQrRequest $request, string $uuid, ManualQrPaymentService $service): JsonResponse
    {
        [$order, $created] = $service->confirm($uuid, $request->validated(), $request->user());

        return response()->json(['data' => (new OrderResource($order))->resolve($request), 'replayed' => ! $created], $created ? 201 : 200);
    }
}
