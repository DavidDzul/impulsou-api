<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessAgreement;
use App\Models\User;
use Illuminate\Http\Request;

class BusinessAgreementController extends Controller
{
    public function show(Request $request, $id)
    {
        // find()+abort_if: Handler.php maps ModelNotFoundException to 400.
        abort_if(User::businessVisibleTo($request->user())->find($id) === null, 404);

        $agreements = BusinessAgreement::where('user_id', $id)->get();

        return response()->json([
            'agreements' => $agreements
        ]);
    }
}
