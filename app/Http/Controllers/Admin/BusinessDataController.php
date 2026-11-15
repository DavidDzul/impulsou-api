<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessData;
use App\Models\User;
use Illuminate\Http\Request;

class BusinessDataController extends Controller
{
    /**
     * Owner resolved through the actor's business scope; out-of-scope, non-BUSINESS and missing
     * all yield the same 404. find()+abort_if because Handler.php maps ModelNotFoundException to 400.
     */
    public function show(Request $request, $id)
    {
        abort_if(User::businessVisibleTo($request->user())->find($id) === null, 404);

        $data = BusinessData::where('user_id', $id)->first();
        abort_if($data === null, 404);

        return response()->json([
            'data' => $data
        ]);
    }

    public function update(Request $request, $id)
    {
        $info = BusinessData::whereKey($id)
            ->whereIn('user_id', User::businessVisibleTo($request->user())->select('id'))
            ->first();
        abort_if($info === null, 404);

        $data = $request->validate(BusinessData::updateRules());

        $info->update($data);

        return response()->json([
            "res" => true,
            'message' => 'Terreno actualizado correctamente',
            'updateData' => $info,
        ]);
    }
}
