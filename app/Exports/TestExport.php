<?php

namespace App\Exports;

use App\Models\ClassModel;
use App\Models\Attendance;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class TestExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithCustomStartCell, WithTitle
{
    protected $class;

    public function __construct($classId)
    {
        $this->class = ClassModel::with('attendances.user')->findOrFail($classId);
    }

    public function startCell(): string
    {
        // Comenzamos debajo del encabezado
        return 'A8';
    }

    public function collection()
    {
        $statusTranslations = [
            'ABSENT' => 'Falta',
            'JUSTIFIED_LATE' => 'Retardo Justificado',
            'JUSTIFIED_ABSENCE' => 'Falta Justificada',
            'PRESENT' => 'Presente',
            'LATE' => 'Retardo',
        ];

        $statusColors = [
            'ABSENT' => 'FF4A4A',           // rojo
            'JUSTIFIED_LATE' => 'FF7900',   // naranja
            'JUSTIFIED_ABSENCE' => 'FFCE00', // amarillo
            'PRESENT' => '275FFC',          // azul
            'LATE' => 'A327FC',             // morado
        ];

        return $this->class->attendances->map(function ($attendance, $index) use ($statusTranslations, $statusColors) {
            return [
                '#' => $index + 1,
                'Nombre' => $attendance->user->first_name . ' ' . $attendance->user->last_name,
                'Entrada' => $attendance->check_in ?? '-',
                'Salida' => $attendance->check_out ?? '-',
                'Estatus' => $statusTranslations[$attendance->status] ?? $attendance->status,
                'Color' => $statusColors[$attendance->status] ?? 'FFFFFF',
                'Observaciones' => $attendance->observations ?? '-',
            ];
        });
    }

    public function headings(): array
    {
        return ['#', 'Nombre', 'Entrada', 'Salida', 'Estatus', 'Color', 'Observaciones'];
    }

    public function styles(Worksheet $sheet)
    {
        // 🔹 Encabezado general
        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', 'REPORTE DE ASISTENCIAS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 🔹 Datos de la clase
        $sheet->setCellValue('A3', 'Clase:');
        $sheet->setCellValue('B3', $this->class->name);
        $sheet->setCellValue('A4', 'Fecha:');
        $sheet->setCellValue('B4', $this->class->date);
        $sheet->setCellValue('A5', 'Horario:');
        $sheet->setCellValue('B5', $this->class->start_time->format('H:i') . ' - ' . $this->class->end_time->format('H:i') . ' hrs');
        $sheet->setCellValue('A6', 'Sede:');
        $sheet->setCellValue('B6', $this->class->campus);

        // 🔹 Encabezado de tabla (A8:G8)
        $sheet->getStyle('A8:G8')->getFont()->setBold(true);
        $sheet->getStyle('A8:G8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A8:G8')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('E2E2E2');

        // 🔹 Filas de estatus
        foreach (range(9, $sheet->getHighestRow()) as $row) {
            $color = $sheet->getCell('F' . $row)->getValue(); // color oculto
            if ($color) {
                $sheet->getStyle('E' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($color);
                $sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB('FFFFFF');
                $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        // 🔹 Ocultar columna auxiliar de color
        $sheet->getColumnDimension('F')->setVisible(false);

        // $lastRow = $sheet->getHighestRow() + 2;
        // $legend = [
        //     ['Falta', 'FF4A4A'],
        //     ['Retardo Justificado', 'FF7900'],
        //     ['Falta Justificada', 'FFCE00'],
        //     ['Presente', '275FFC'],
        //     ['Retardo', 'A327FC'],
        // ];
        // $sheet->setCellValue('A' . $lastRow, 'Estatus:');
        // foreach ($legend as $i => [$label, $color]) {
        //     $cell = 'B' . ($lastRow + $i);
        //     $sheet->setCellValue($cell, $label);
        //     $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
        //         ->getStartColor()->setARGB($color);
        //     $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFF');
        // }

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 25,
            'C' => 12,
            'D' => 12,
            'E' => 20,
            'F' => 8,
            'G' => 25,
        ];
    }

    public function title(): string
    {
        return 'Reporte Asistencias';
    }
}
