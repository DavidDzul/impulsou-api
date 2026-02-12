<?php

namespace App\Exports;

use App\Models\ClassModel;
use App\Models\Attendance;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;

class AttendanceMatrixExport implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize, WithCustomStartCell
{
    protected $params;
    protected $classes;
    protected $counter = 1;

    public function __construct($params)
    {
        $this->params = $params;
        $this->classes = ClassModel::where('campus', $params['campus'])
            ->where('generation_id', $params['generation_id'])
            ->whereBetween('date', [$params['startDate'], $params['endDate']])
            ->orderBy('date')
            ->get();
    }

    public function startCell(): string
    {
        return 'A3'; // Encabezados en fila 3, Datos en fila 4
    }

    public function collection()
    {
        $classIds = $this->classes->pluck('id');

        $attendances = Attendance::with('user')->whereIn('class_id', $classIds)->get();

        return $attendances->groupBy('user_id')
            // ->sortBy(function ($userAttendances) {
            //     return $userAttendances->first()->user->last_name;
            // })
            ->sortBy(function ($userAttendances) {
                $lastName = $userAttendances->first()->user->last_name;
                return transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $lastName);
            })
            ->map(function ($userAttendances) {
                $user = $userAttendances->first()->user;

                $row = [
                    'index'      => $this->counter++,
                    'name'       => $user->last_name . ' ' . $user->first_name,
                    'enrollment' => $user->enrollment ?? 'N/A',
                ];

                foreach ($this->classes as $class) {
                    $att = $userAttendances->firstWhere('class_id', $class->id);
                    $cellValue = '';

                    if ($att) {
                        switch ($att->status) {
                            case 'PRESENT':
                                $cellValue = '*';
                                break;
                            case 'LATE':
                                $cellValue = "R " . ($att->check_in ? date('G:i', strtotime($att->check_in)) : '');
                                break;
                            case 'JUSTIFIED_LATE':
                                $cellValue = "RJ " . ($att->check_in ? date('G:i', strtotime($att->check_in)) : '');
                                break;
                            case 'JUSTIFIED_ABSENCE':
                                $cellValue = '1J';
                                break;
                            case 'ABSENT':
                                $cellValue = '1';
                                break;
                        }
                    }
                    $row[$class->date->format('d/m/Y')] = $cellValue;
                }
                return $row;
            });
    }

    public function headings(): array
    {
        $headers = ['#', 'Nombre', 'Matrícula'];
        foreach ($this->classes as $class) {
            $headers[] = $class->date->format('d/m/Y');
        }
        return $headers;
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestCol);

        // 1. INMOVILIZAR PANELES: Congela hasta la columna C (Nombre) y la fila 3 (Encabezados)
        $sheet->freezePane('C3');

        // 2. ESTILO ENCABEZADOS (Fila 3)
        // Primero, el azul oscuro para #, Matrícula y Nombre (A, B, C)
        $sheet->getStyle("A3:C3")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0070C0'], // Azul Oscuro (Navy)
            ],
        ]);

        // Segundo, el azul cyan para las fechas (D en adelante)
        $sheet->getStyle("D3:{$highestCol}3")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF000000']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF00B0F0'], // Azul Fechas
            ],
        ]);

        // Alineación y bordes para todos los encabezados
        $sheet->getStyle("A3:{$highestCol}3")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // 3. ESTILO DE LA MATRIZ DE DATOS (Fila 4 en adelante)
        $sheet->getStyle("A4:{$highestCol}{$highestRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
        ]);

        // Pintar los retardos "R" únicamente
        for ($row = 4; $row <= $highestRow; $row++) {
            // Centrar columnas de control (ID y Matrícula)
            $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            for ($col = 4; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $val = (string)$cell->getValue();
                $coordinate = $cell->getCoordinate();

                $sheet->getStyle($coordinate)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Condición estricta para retardos "R" (gris con texto blanco)
                if (str_starts_with($val, 'R ') && !str_starts_with($val, 'RJ')) {
                    $sheet->getStyle($coordinate)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => '757575'],
                        ],
                        'font' => ['color' => ['argb' => 'FFFFFF']],
                    ]);
                }
            }
        }

        $sheet->getRowDimension(3)->setRowHeight(30);

        return [];
    }

    public function title(): string
    {
        return 'Reporte General de Asistencias';
    }
}
