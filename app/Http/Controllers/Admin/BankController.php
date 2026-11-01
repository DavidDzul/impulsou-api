<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBankRequest;
use App\Models\Bank;

/**
 * Control > Bancos (administration-panel). Authorization is enforced by the
 * route-level ADM_READ_BANKS / ADM_MANAGE_BANKS middleware.
 *
 * The system bank (BBVA) keeps its code, stays active and cannot be deleted;
 * a bank referenced by payment data is deactivated instead of deleted.
 */
class BankController extends Controller
{
    private const SYSTEM_BANK_MSG = 'BBVA es un banco del sistema y no se puede eliminar, desactivar ni cambiar su código.';

    public function index()
    {
        $banks = Bank::orderByRaw('LOWER(name)')->get();

        return response()->json(['res' => true, 'banks' => $banks]);
    }

    public function store(SaveBankRequest $request)
    {
        $data = $request->validated();

        $bank = Bank::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['res' => true, 'bank' => $bank->fresh()], 201);
    }

    public function update(SaveBankRequest $request, Bank $bank)
    {
        $data = $request->validated();

        if ($bank->is_system && (
            $data['code'] !== $bank->code
            || (array_key_exists('is_active', $data) && !$data['is_active'])
        )) {
            return $this->systemBankLocked();
        }

        $bank->update([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $bank->is_active,
        ]);

        return response()->json(['res' => true, 'bank' => $bank->fresh()]);
    }

    public function destroy(Bank $bank)
    {
        if ($bank->is_system) {
            return $this->systemBankLocked();
        }

        if ($bank->isReferenced()) {
            $bank->update(['is_active' => false]);

            return response()->json([
                'res' => true,
                'deleted' => false,
                'msg' => 'El banco está en uso, por lo que se desactivó en lugar de eliminarse.',
                'bank' => $bank->fresh(),
            ]);
        }

        $bank->delete();

        return response()->json(['res' => true, 'deleted' => true, 'msg' => 'Banco eliminado.']);
    }

    private function systemBankLocked()
    {
        return response()->json(['res' => false, 'msg' => self::SYSTEM_BANK_MSG], 422);
    }
}
