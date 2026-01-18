<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Mail\MassiveInformativeMail;
use Illuminate\Support\Facades\Mail;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ejecutar-envio/{campus}/{type}', function ($campus, $type) {

    $campusValidos = ['MERIDA', 'VALLADOLID', 'TIZIMIN', 'OXKUTZCAB'];
    $campusUpper = strtoupper($campus);

    if (!in_array($campusUpper, $campusValidos)) {
        return "El campus '$campusUpper' no es válido.";
    }

    $user_type = ($type == 1) ? 'BEC_ACTIVE' : 'BEC_INACTIVE';

    set_time_limit(600);

    $users = User::where('campus', $campusUpper)
        ->where('active', true)
        ->where('user_type', $user_type)
        ->whereNotNull('email')
        ->get();

    if ($users->isEmpty()) {
        return "No hay usuarios activos de tipo $user_type en el campus $campusUpper.";
    }

    $sentCount = 0;
    $errors = [];

    foreach ($users as $user) {
        try {
            Mail::to($user->email)->send(new MassiveInformativeMail($user));
            $sentCount++;
        } catch (\Exception $e) {
            $errors[] = [
                'email' => $user->email,
                'error' => $e->getMessage()
            ];
        }
    }

    return response()->json([
        'resultado' => 'Proceso Finalizado',
        'campus' => $campusUpper,
        'tipo' => $user_type,
        'exitosos' => $sentCount,
        'fallidos' => count($errors),
        'detalle_errores' => $errors
    ]);
});
