<?php

namespace App\Models;

use App\Enums\CycleClosureReason;
use App\Enums\ScholarshipType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipProfile extends Model
{
    use HasFactory;

    protected $table = 'scholarship_profiles';

    protected $fillable = [
        'user_id',
        'scholarship_type',
        'monthly_amount',
        'monto_apoyo',
        'iu_payment_amount',
        'temporary_increase_amount',
        'temporary_increase_valid_from',
        'temporary_increase_valid_until',
        'temporary_increase_reason',
        'temporary_increase_granted_by_id',
        'is_cert_unid',
        'cycle_closed_period',
        'cycle_closure_reason',
        'cycle_closed_refrend_id',
        'config_set_at',
        'active_discount_percentage',
        'discount_reason',
        'discount_valid_from',
        'discount_valid_until',
        'reticula_start_date',
        'reticula_end_date',
        'reticula_file_path',
        'reticula_original_name',
        // Legacy columns kept for backward compatibility with SQLite test migrations
        'payment_start_date',
        'payment_end_date',
    ];

    protected $casts = [
        'scholarship_type'           => ScholarshipType::class,
        'monthly_amount'                  => 'decimal:2',
        'monto_apoyo'                     => 'decimal:2',
        'iu_payment_amount'               => 'decimal:2',
        'temporary_increase_amount'       => 'decimal:2',
        'temporary_increase_valid_from'   => 'date',
        'temporary_increase_valid_until'  => 'date',
        'is_cert_unid'        => 'boolean',
        'cycle_closed_period'             => 'date',
        'cycle_closure_reason'            => CycleClosureReason::class,
        'config_set_at'                   => 'datetime',
        'active_discount_percentage'      => 'decimal:2',
        'discount_valid_from'             => 'date',
        'discount_valid_until'            => 'date',
        'reticula_start_date'             => 'date',
        'reticula_end_date'               => 'date',
    ];

    protected $appends = ['egreso_administrativo'];

    /**
     * Fecha límite administrativa (egreso): fin de retícula + 2 meses de
     * gracia, anclada al fin del mes resultante. Null si no hay fecha de fin
     * de retícula. No es columna de BD.
     *
     * sdd/egresado-status-timing, design D1: this is the ONE shared formula
     * for this boundary — assertPeriodWithinReticula() and the
     * advance-payment guard both call this method instead of duplicating
     * the calculation. startOfMonth() first makes day-of-month overflow
     * structurally impossible (day 1 exists in every month).
     */
    public function egresoAdministrativoDate(): ?Carbon
    {
        return $this->reticula_end_date
            ? Carbon::parse($this->reticula_end_date)->startOfMonth()->addMonths(2)->endOfMonth()
            : null;
    }

    public function getEgresoAdministrativoAttribute(): ?string
    {
        return $this->egresoAdministrativoDate()?->toDateString();
    }

    /**
     * True iff (year, month) IS the becario's retícula month+2 — the exact
     * period the automatic $0 egreso refrendo must generate for. Explicit
     * year/month comparison, NOT isSameMonth() — Carbon's year-sensitivity
     * default varies by version and this cannot regress silently.
     */
    public function isEgresoReticulaPeriod(int $year, int $month): bool
    {
        $cutoff = $this->egresoAdministrativoDate();

        return $cutoff !== null && $cutoff->year === $year && $cutoff->month === $month;
    }

    public static function createRules(): array
    {
        return [
            'user_id'                    => 'required|exists:users,id|unique:scholarship_profiles,user_id',
            'scholarship_type'           => 'required|string|in:IU,TELMEX',
            'monthly_amount'             => 'required|numeric|min:0',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_from'        => 'nullable|date',
            'discount_valid_until'       => 'nullable|date',
        ];
    }

    public static function updateRules(int $id): array
    {
        return [
            'scholarship_type'           => 'sometimes|string|in:IU,TELMEX',
            'monthly_amount'             => 'sometimes|numeric|min:0',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_from'        => 'nullable|date',
            'discount_valid_until'       => 'nullable|date',
        ];
    }

    // Relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function refrends(): HasMany
    {
        return $this->hasMany(ScholarshipRefrend::class, 'user_id', 'user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'temporary_increase_granted_by_id');
    }

    // Vigencia (shared inclusive range primitive — see design D1/D3)

    /**
     * Whether the fixed-amount temporary increase is active on the given
     * date (defaults to today). Requires amount > 0 and both range dates set;
     * no proration by days or months.
     */
    public function isTemporaryIncreaseActiveOn(?Carbon $on = null): bool
    {
        return $this->temporary_increase_amount !== null
            && (float) $this->temporary_increase_amount > 0
            && self::rangeCoversDate(
                $this->temporary_increase_valid_from,
                $this->temporary_increase_valid_until,
                $on
            );
    }

    /**
     * Whether this profile currently has a temporary increase that must
     * block granting a NEW one without an explicit replace confirmation
     * (design D-4.3 "un solo aumento vigente a la vez"). This is
     * intentionally BROADER than isTemporaryIncreaseActiveOn(): it also
     * blocks an increase scheduled for the future (valid_from > $on) as long
     * as its valid_until has not passed yet, because that increase WILL
     * become active and must not be silently overwritten. Requires
     * amount > 0 and valid_until >= $on (inclusive) — it deliberately does
     * NOT require valid_from to have been reached.
     */
    public function hasBlockingTemporaryIncrease(?Carbon $on = null): bool
    {
        if ($this->temporary_increase_amount === null || (float) $this->temporary_increase_amount <= 0) {
            return false;
        }

        if ($this->temporary_increase_valid_until === null) {
            return false;
        }

        $on = ($on ?? Carbon::today())->copy()->startOfDay();

        return $this->temporary_increase_valid_until->copy()->startOfDay()->gte($on);
    }

    /**
     * Whether the academic discount is active on the given date (defaults to
     * today), using the same inclusive range primitive as the temporary
     * increase.
     */
    public function isDiscountActiveOn(?Carbon $on = null): bool
    {
        return self::rangeCoversDate(
            $this->discount_valid_from,
            $this->discount_valid_until,
            $on
        );
    }

    /**
     * Shared vigencia primitive: both range bounds are required (a null bound
     * means "inactive", never "unbounded") and the range is INCLUSIVE on both
     * ends ($on >= $from AND $on <= $until).
     */
    private static function rangeCoversDate(?Carbon $from, ?Carbon $until, ?Carbon $on): bool
    {
        if ($from === null || $until === null) {
            return false;
        }

        $on = ($on ?? Carbon::today())->copy()->startOfDay();

        return $on->gte($from->copy()->startOfDay())
            && $on->lte($until->copy()->startOfDay());
    }

    /** Whether Administración has saved the beca amounts (sdd/perfil-beca-montos-admin). */
    public function isConfigSet(): bool
    {
        return $this->config_set_at !== null;
    }

    // Cycle closure (sdd/egresado-baja-definitiva, design D1/D2)

    /**
     * Whether the becario's cycle was already cut (stop marker set). Shared
     * by CloseScholarshipCycleAction's "already closed" guard and
     * AdvanceRevertEligibility (sdd/revertir-pago-adelantado).
     */
    public function hasClosedCycle(): bool
    {
        return $this->cycle_closed_period !== null;
    }

    /**
     * Whether (year, month) falls STRICTLY AFTER the stop marker
     * cycle_closed_period. The cut period itself (period == closed) is
     * deliberately NOT closed — the cut's own refrend is generated/updated
     * by the normal flow before the marker stops anything further.
     * Mirrors scopeOpenForPeriod()'s boundary; the two are tested together.
     */
    public function isCycleClosedFor(int $year, int $month): bool
    {
        if ($this->cycle_closed_period === null) {
            return false;
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $closed      = Carbon::parse($this->cycle_closed_period)->startOfDay();

        return $periodStart->gt($closed);
    }

    /**
     * Query-level counterpart of isCycleClosedFor(): a profile is "open" for
     * (year, month) when it has no stop marker, or the marker is on/after
     * the requested period start. Used by
     * GenerateMonthlyRefrendsService::generateForPeriod()'s bulk query so a
     * cut becario is silently excluded, the same way `active=false` already
     * is.
     */
    public function scopeOpenForPeriod($query, int $year, int $month)
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay()->toDateString();

        return $query->where(function ($q) use ($periodStart) {
            $q->whereNull('cycle_closed_period')
                ->orWhere('cycle_closed_period', '>=', $periodStart);
        });
    }

    /**
     * sdd/generaciones-activas-refrendos, R1: the ONE definition of a
     * "becario with pending payments". Mirrors the filter of
     * GenerateMonthlyRefrendsService::generateForPeriod() (user active, open
     * for the period, NO user_type filter, config_set_at ignored) and keeps
     * only profiles whose egresoAdministrativoDate() falls in the current
     * month or later.
     *
     * month(egreso) = month(startOfMonth(end) + 2) >= month(now)
     *   <=> end >= startOfMonth(now) - 2 months,
     * so the boundary is a plain date comparison (portable across MySQL and
     * SQLite) and a NULL reticula_end_date is excluded naturally.
     */
    public function scopePendingPaymentsWindow($query, ?Carbon $now = null)
    {
        $now = ($now ?? Carbon::now())->copy();
        $earliestEnd = $now->copy()->startOfMonth()->subMonths(2)->toDateString();

        return $query
            ->whereHas('user', fn ($q) => $q->where('users.active', true))
            ->openForPeriod($now->year, $now->month)
            ->where('scholarship_profiles.reticula_end_date', '>=', $earliestEnd);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->whereHas('user', fn($q) => $q->where('user_type', 'BEC_ACTIVE'));
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('scholarship_type', $type);
    }
}
