<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\BusinessData;

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
}
