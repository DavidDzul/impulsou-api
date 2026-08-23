<?php

namespace App\Http\Requests;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScholarshipProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scholarship_type'           => ['sometimes', Rule::in(array_column(ScholarshipType::cases(), 'value'))],
            'monthly_amount'             => 'sometimes|numeric|min:0',
            'monto_apoyo'                => 'nullable|numeric|min:0|max:99999.99',
            'advance_payment_eligible'   => 'sometimes|boolean',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_from'        => 'nullable|date|required_with:active_discount_percentage|before_or_equal:discount_valid_until',
            'discount_valid_until'       => 'nullable|date|required_with:active_discount_percentage',
            'discount_reason'            => 'nullable|string|max:200',

            'temporary_increase_amount'      => 'nullable|numeric|min:0.01|max:99999.99|required_with:temporary_increase_valid_from,temporary_increase_valid_until,temporary_increase_reason',
            'temporary_increase_valid_from'  => 'nullable|date|required_with:temporary_increase_amount',
            'temporary_increase_valid_until' => 'nullable|date|after:temporary_increase_valid_from|required_with:temporary_increase_amount',
            'temporary_increase_reason'      => 'nullable|string|max:200|required_with:temporary_increase_amount',
            // Not persisted — consumed only by withValidator() below to allow
            // explicitly overwriting an already-valid temporary increase.
            'replace_temporary_increase'     => 'sometimes|boolean',
        ];
    }

    /**
     * Enforce "only one active temporary increase at a time" (design D-4.3).
     * This needs the persisted profile state, so it cannot be a static rule.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            if (! $this->has('temporary_increase_amount')) {
                return;
            }

            // Clearing the increase (amount null) is always allowed.
            if ($this->input('temporary_increase_amount') === null) {
                return;
            }

            $profile = ScholarshipProfile::where('user_id', $this->route('userId'))->first();
            if (! $profile) {
                return;
            }

            // Use hasBlockingTemporaryIncrease() (design D-4.3), NOT
            // isTemporaryIncreaseActiveOn(): the uniqueness check must also
            // block a future-scheduled increase (valid_from not yet
            // reached), not only one that is active today. It still
            // requires amount > 0 to avoid the original bug of matching on
            // an orphaned date alone.
            if (! $profile->hasBlockingTemporaryIncrease()) {
                return;
            }

            $existingUntil = $profile->temporary_increase_valid_until;

            if ($this->isSameTemporaryIncreaseAsStored($profile)) {
                return; // Resubmitting identical values is a no-op.
            }

            if ($this->boolean('replace_temporary_increase')) {
                return; // Explicit replace confirmed.
            }

            $validator->errors()->add(
                'temporary_increase_amount',
                'Ya existe un aumento vigente hasta ' . Carbon::parse($existingUntil)->format('d/m/Y')
                    . '. Confirmá el reemplazo para sobrescribirlo.'
            );
        });
    }

    private function isSameTemporaryIncreaseAsStored(ScholarshipProfile $profile): bool
    {
        $storedAmount = $profile->temporary_increase_amount !== null
            ? (float) $profile->temporary_increase_amount
            : null;
        $storedFrom  = $profile->temporary_increase_valid_from?->toDateString();
        $storedUntil = $profile->temporary_increase_valid_until?->toDateString();
        $storedReason = $profile->temporary_increase_reason;

        return $storedAmount === (float) $this->input('temporary_increase_amount')
            && $storedFrom === $this->input('temporary_increase_valid_from')
            && $storedUntil === $this->input('temporary_increase_valid_until')
            && $storedReason === $this->input('temporary_increase_reason');
    }
}
