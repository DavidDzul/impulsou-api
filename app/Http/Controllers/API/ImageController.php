<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function uploadImage(Request $request)
    {

        $request->validate([
            'url' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        $existingImage = Image::where('user_id', auth()->id())->first();

        $name = null;
        if ($request->hasFile('url')) {
            $name = $request->file('url')->store('images', 'public');

            if ($existingImage && $existingImage->url) {
                $destination = public_path("storage\\" . $existingImage->url);
                if (File::exists($destination)) {
                    File::delete($destination);
                }
            }
        }

        if ($existingImage) {
            $existingImage->update(['url' => $name]);
            $image = $existingImage;
        } else {
            $image = Image::create([
                'user_id' => auth()->id(),
                'url' => $name,
            ]);
        }

        return response()->json([
            'res' => true,
            'msg' => $existingImage ? "Imagen actualizada con éxito" : "Imagen agregada con éxito",
            'image' => $image,
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
