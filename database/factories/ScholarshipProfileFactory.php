<?php

namespace Database\Factories;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipProfileFactory extends Factory
{
    protected $model = ScholarshipProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id'                    => User::factory(),
            'scholarship_type'           => 'IU',
            'monthly_amount'             => 2500,
            'monto_apoyo'                => null,
            'is_cert_unid'   => false,
            'active_discount_percentage' => null,
            'discount_reason'            => null,
            'discount_valid_until'       => null,
            // NOT NULL on the SQLite test schema — the column-drop migration
            // is skipped for the sqlite driver (see
            // RemovePaymentDatesFromScholarshipProfilesTable). Kept here only
            // to satisfy that legacy constraint in tests; unused elsewhere.
            'payment_start_date'         => now()->toDateString(),
            // sdd/perfil-beca-montos-admin: configured by default so
            // factory-based suites keep generating; use configPending().
            'config_set_at'              => now(),
            // sdd/egresar-boton-corte-ciclo: generation requires BOTH reticula
            // dates. Wide window by default so factory-based suites keep
            // generating; use reticulaMissing() for the NULL case.
            'reticula_start_date'        => '2000-01-01',
            'reticula_end_date'          => '2099-12-31',
        ];
    }

    /** Amounts never saved by Administración (config_set_at NULL). */
    public function configPending(): static
    {
        return $this->state(fn () => ['config_set_at' => null]);
    }

    /** Retícula dates never loaded (both NULL). */
    public function reticulaMissing(): static
    {
        return $this->state(fn () => ['reticula_start_date' => null, 'reticula_end_date' => null]);
    }
}
