<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScholarshipPaymentDataRequest;
use App\Http\Requests\UpdateScholarshipPaymentDataRequest;
use App\Models\ScholarshipPaymentData;

class ScholarshipPaymentDataController extends Controller
{
    public function show(int $userId)
    {
        $paymentData = ScholarshipPaymentData::where('user_id', $userId)->first();

        if (!$paymentData) {
            return response()->json(['res' => false, 'data' => null, 'msg' => 'Datos de pago no configurados.'], 404);
        }

        return response()->json(['res' => true, 'data' => $paymentData]);
    }

    public function store(StoreScholarshipPaymentDataRequest $request)
    {
        $paymentData = ScholarshipPaymentData::create($request->safe()->toArray());

        return response()->json(['res' => true, 'data' => $paymentData->fresh()], 201);
    }

    public function update(UpdateScholarshipPaymentDataRequest $request, int $userId)
    {
        $paymentData = ScholarshipPaymentData::where('user_id', $userId)->firstOrFail();

        $paymentData->update($request->safe()->toArray());

        return response()->json(['res' => true, 'data' => $paymentData->fresh()]);
    }
}
