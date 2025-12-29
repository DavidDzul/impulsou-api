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

class AttendanceMatrixExport implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize, WithCustomStartCell
{
    protected $params;
    protected $classes;
    protected $statusColors = [
        'ABSENT'            => 'FF0000', // Rojo
        'LATE'              => 'FF5300', // Naranja
        'PRESENT'           => '00BA10', // Verde
        'JUSTIFIED_LATE'    => '0022FF', // Azul
        'JUSTIFIED_ABSENCE' => 'FF00F2', // Rosa
    ];

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
        // Los datos de los alumnos (Collection) comenzarán en A5
        return 'A5';
    }

    public function collection()
    {
        $classIds = $this->classes->pluck('id');
        $attendances = Attendance::with('user')->whereIn('class_id', $classIds)->get();

        // Agrupamos por usuario para crear una fila por alumno
        return $attendances->groupBy('user_id')->map(function ($userAttendances) {
            $user = $userAttendances->first()->user;
            $row = ['Nombre' => $user->first_name . ' ' . $user->last_name];

            foreach ($this->classes as $class) {
                $att = $userAttendances->firstWhere('class_id', $class->id);
                // Retornamos el código del estatus para procesar el color después
                $row[$class->date->format('d/m/Y')] = $att ? $att->status : '';
            }
            return $row;
        });
    }

    public function headings(): array
    {
        $headers = ['Nombre'];
        foreach ($this->classes as $class) {
            $headers[] = $class->date->format('d/m/Y');
        }
        return $headers;
    }

    public function styles(Worksheet $sheet)
    {
        // 1. Título y Leyenda (Filas 1 y 2)
        $sheet->setCellValue('A1', 'Estatus de Asistencia:');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $labels = [
            'Falta' => 'ABSENT',
            'Retardo' => 'LATE',
            'Presente' => 'PRESENT',
            'Retardo Just.' => 'JUSTIFIED_LATE',
            'Falta Just.' => 'JUSTIFIED_ABSENCE'
        ];

        $currentCol = 1;
        foreach ($labels as $text => $key) {
            $cell = $sheet->getCell([$currentCol, 2]); // Fila 2 para colores
            $cell->setValue($text);

            $sheet->getStyle($cell->getCoordinate())->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => $this->statusColors[$key]],
                ],
                'font' => ['color' => ['argb' => 'FFFFFF'], 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $currentCol++;
        }

        // 2. Estilo de Encabezados de Tabla (Fila 5 debido al startCell)
        $highestCol = $sheet->getHighestColumn();
        $sheet->getStyle("A5:{$highestCol}5")->getFont()->setBold(true);
        $sheet->getStyle("A5:{$highestCol}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 3. Pintar la Matriz de Datos (Fila 6 en adelante)
        $highestRow = $sheet->getHighestRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestCol);

        for ($row = 6; $row <= $highestRow; $row++) {
            // Recorremos desde la columna 2 (B) que son las fechas
            for ($col = 2; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $val = $cell->getValue();

                if ($val && isset($this->statusColors[$val])) {
                    $coordinate = $cell->getCoordinate();

                    $sheet->getStyle($coordinate)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB($this->statusColors[$val]);

                    // Colocamos el asterisco y limpiamos el texto del estatus
                    $cell->setValue('*');
                    $sheet->getStyle($coordinate)->getFont()->getColor()->setARGB('FFFFFF');
                    $sheet->getStyle($coordinate)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
            }
        }

        return [];
    }

    public function title(): string
    {
        return 'Reporte General de Asistencias';
    }
}
