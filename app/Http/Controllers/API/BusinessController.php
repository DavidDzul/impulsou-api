<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePracticeVacantRequest;
use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\BusinessData;
use App\Http\Requests\UpdateBusinessInformationRequest;
use App\Http\Requests\UpdatePracticeVacantRequest;
use App\Http\Requests\UpdateVacantPositionRequest;
use App\Models\VacantPosition;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;
use App\Models\JobApplication;

class BusinessController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        $businessData = BusinessData::where('user_id', $userId)->first();
        $images = Image::where('user_id', $userId)->get();

        return response()->json([
            'images' => $images,
            'businessData' => $businessData
        ]);
    }

    public function getBusinessVacancies()
    {
        $userId = auth()->id();
        $result = VacantPosition::where('user_id', $userId)
            // ->where('status', true)
            ->get();

        return response()->json([
            'vacancies' => $result
        ]);
    }

    public function updateBusinessInformation(UpdateBusinessInformationRequest $request)
    {
        $business = BusinessData::find($request->id);
        $business->bs_name = $request->bs_name;
        $business->bs_director = $request->bs_director;
        $business->bs_rfc = $request->bs_rfc;
        $business->bs_country = $request->bs_country;
        $business->bs_state = $request->bs_state;
        $business->bs_locality = $request->bs_locality;
        $business->bs_adrress = $request->bs_adrress;
        $business->bs_telphone = $request->bs_telphone;
        $business->bs_line = $request->bs_line;
        $business->bs_description = $request->bs_description;
        $business->bs_website = $request->bs_website;
        $business->bs_other_line = $request->bs_other_line;

        $business->save();
        return response()->json([
            'res' => true,
            "msg" => "Actualización con  éxito",
            "updateBusiness" => $business
        ], 200);
    }
}
