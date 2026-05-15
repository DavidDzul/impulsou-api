<?php

namespace App\Console\Commands;

use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMonthlyRefrends extends Command
{
    protected $signature = 'scholarships:generate-refrends
                            {--year= : Año del periodo (default: año actual)}
                            {--month= : Mes del periodo (default: mes actual)}';

    protected $description = 'Genera refrendos DRAFT para todos los becarios activos del periodo indicado.';

    private GenerateMonthlyRefrendsService $service;

    public function __construct(GenerateMonthlyRefrendsService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $year  = (int) ($this->option('year')  ?? now()->year);
        $month = (int) ($this->option('month') ?? now()->month);

        if ($month < 1 || $month > 12) {
            $this->error("Mes inválido: {$month}. Debe estar entre 1 y 12.");
            return self::FAILURE;
        }

        $periodLabel = Carbon::create($year, $month, 1)->translatedFormat('F Y');
        $this->info("Generando refrendos para: {$periodLabel}");

        $stats = $this->service->generateForPeriod($year, $month);

        $this->table(
            ['Creados', 'Omitidos (ya existían)', 'Errores'],
            [[$stats['created'], $stats['skipped'], $stats['errors']]]
        );

        if ($stats['errors'] > 0) {
            $this->warn("Hubo {$stats['errors']} error(es). Revisa los logs de Laravel.");
        }

        return self::SUCCESS;
    }
}
