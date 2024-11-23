<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ImageController extends Controller
{
    public function uploadImage(Request $request)
    {

        $image = new Image();
        $request->validate([
            'url' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
        ]);

        $name = null;
        if ($request->hasFile('url')) {
            $name = $request->file('url')->store('images', 'public');
        }

        $image->user_id = auth()->id();
        $image->url = $name;
        $image->save();
        return response()->json([
            'res' => true,
            "msg" => "Agregado con éxito",
            "image" => $image
        ], 200);
    }


    public function removeImage($id)
    {
        $image = Image::findOrFail($id);
        $destination = public_path("storage\\" . $image->url);

        if (File::exists($destination)) {
            File::delete($destination);
        }
        $image->delete();
        return response()->json([
            'res' => true,
            "msg" => "Eliminado con éxito",
            "image" => $image
        ], 200);
    }
}