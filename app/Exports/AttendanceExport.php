<?php

namespace App\Exports;

use App\Models\Attendance;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths
{
    protected $classId;

    public function __construct($classId)
    {
        $this->classId = $classId;
    }

    public function collection()
    {
        $attendances = Attendance::with('user')
            ->where('class_id', $this->classId)
            ->get();

        return $attendances->map(function ($attendance) {
            // Simulamos el círculo con el nombre del estatus
            $statusColors = [
                'ABSENT' => 'FF4A4A',           // rojo
                'JUSTIFIED_LATE' => 'FF7900',   // naranja
                'JUSTIFIED_ABSENCE' => 'FFCE00', // amarillo
                'PRESENT' => '275FFC',          // azul
                'LATE' => 'A327FC',             // morado
            ];

            $color = $statusColors[$attendance->status] ?? 'FFFFFF';

            return [
                'Nombre' => $attendance->user->first_name . ' ' . $attendance->user->last_name,
                'Entrada' => $attendance->check_in ?? '-',
                'Salida' => $attendance->check_out ?? '-',
                'Estatus' => $attendance->status,
                'Color' => $color, // solo para aplicar fondo
                'Observaciones' => $attendance->observations ?? '-',
            ];
        });
    }

    public function headings(): array
    {
        return ['Nombre', 'Entrada', 'Salida', 'Estatus', 'Color', 'Observaciones'];
    }

    public function styles(Worksheet $sheet)
    {
        // Encabezados en negrita y centrados
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal('center');

        // Color de fondo para columna "Estatus" usando la columna de "Color"
        foreach (range(2, $sheet->getHighestRow()) as $row) {
            $color = $sheet->getCell('E' . $row)->getValue();
            $sheet->getStyle('D' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB($color);
            $sheet->getStyle('D' . $row)->getFont()->getColor()->setARGB('FFFFFF'); // texto blanco
        }

        // Ocultar columna "Color" auxiliar
        $sheet->getColumnDimension('E')->setVisible(false);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 25,
            'B' => 12,
            'C' => 12,
            'D' => 15,
            'E' => 5, // auxiliar
            'F' => 25,
        ];
    }
}
