<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 10px 40px;
        }

        /* Fuente Roboto */
        @font-face {
            font-family: 'Roboto';
            font-style: normal;
            font-weight: 400;
            src: url("{{ public_path('fonts/Roboto-Regular.ttf') }}") format('truetype');
        }

        @font-face {
            font-family: 'Roboto';
            font-style: bold;
            font-weight: 700;
            src: url("{{ public_path('fonts/Roboto-Bold.ttf') }}") format('truetype');
        }

        body {
            font-family: 'Roboto', sans-serif;
            font-size: 12px;
            color: #000;
        }

        header {
            width: 100%;
            margin-bottom: 15px;
            border-bottom: 1px solid #e4e4e4ff;
            padding-bottom: 10px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            border: none !important;
            vertical-align: top;
        }

        .logo {
            width: 120px;
        }

        h2,
        h3 {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #e2e2e2;
            text-align: center;
            padding: 8px;
            font-size: 13px;
        }

        td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
            font-size: 12px;
        }

        td.text-left {
            text-align: left;
        }

        /* Círculos de estatus */
        .status-circle {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
        }

        .status-ABSENT {
            background-color: #FF4A4A;
        }

        .status-JUSTIFIED_LATE {
            background-color: #FF7900;
        }

        .status-JUSTIFIED_ABSENCE {
            background-color: #FFCE00;
        }

        .status-PRESENT {
            background-color: #275FFC;
        }

        .status-LATE {
            background-color: #A327FC;
        }

        /* Leyenda de estatus */
        .status-legend {
            margin-top: 10px;
            margin-bottom: 10px;
            font-size: 11px;
        }

        .status-legend span {
            display: inline-flex;
            align-items: center;
            margin-right: 15px;
        }

        .status-legend span .status-circle {
            margin-right: 5px;
        }

        footer {
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 11px;
            color: #666;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header>
        <table class="header-table">
            <tr>
                <!-- Logo -->
                <td style="width: 120px;">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSbyEMoD-R6QqnO_YdjNFGWpfMvcmiSCcRYrw&s"
                        alt="Logo" class="logo">
                </td>

                <!-- Título central -->
                <td style="text-align: center;">
                    <h3>REPORTE DE ASISTENCIAS</h3>
                </td>

                <!-- Información discreta institución -->
                <td style="width: 200px; text-align: right; font-size: 11px; color: #666;">
                    Impulso Universitario A.C.<br>
                    Calle 62 #383 por 45 y 47, Centro, 97000 Mérida, Yuc.<br>
                    (999) 9 45-27-10<br>
                    contacto@iu.org.mx
                </td>
            </tr>
        </table>

        <!-- Información de la clase -->
        <div style="margin-top: 10px;">
            <h2 style="color: #FF7900;">{{ $class->name }}</h2>
            <p style="margin: 2px 0; font-size: 13px; color: #333;">
                <strong>Fecha:</strong> {{ \Carbon\Carbon::parse($class->date)->format('d/m/Y') }}<br>
                <strong>Horario:</strong> {{ \Carbon\Carbon::parse($class->start_time)->format('H:i') }} hrs -
                {{ \Carbon\Carbon::parse($class->end_time)->format('H:i') }} hrs<br>
                @php
                $campusNames = [
                'MERIDA' => 'Mérida',
                'TIZIMIN' => 'Tizimín',
                'VALLADOLID' => 'Valladolid',
                'OXKUTZCAB' => 'Oxkutzcab'
                ];
                @endphp
                <strong>Sede:</strong> {{ $campusNames[$class->campus] ?? $class->campus }}
            </p>
        </div>

    </header>

    <!-- Tabla de asistencias -->
    <!-- Leyenda de estatus -->
    <strong>Estatus:</strong>
    <br />
    <div class="status-legend">

        <span><span class="status-circle status-ABSENT"></span> Falta</span>
        <span><span class="status-circle status-JUSTIFIED_LATE"></span> Retardo Justificado</span>
        <span><span class="status-circle status-JUSTIFIED_ABSENCE"></span> Falta Justificada</span>
        <span><span class="status-circle status-PRESENT"></span> Presente</span>
        <span><span class="status-circle status-LATE"></span> Retardo</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Nombre</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Estatus</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($attendances as $index => $attendance)
            @php
            $statusTranslations = [
            'ABSENT' => 'Falta',
            'JUSTIFIED_LATE' => 'Retardo Justificado',
            'JUSTIFIED_ABSENCE' => 'Falta Justificada',
            'PRESENT' => 'Presente',
            'LATE' => 'Retardo'
            ];
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="text-left">{{ $attendance->user->first_name }} {{ $attendance->user->last_name }}</td>
                <td>{{ $attendance->check_in ?? '-' }}</td>
                <td>{{ $attendance->check_out ?? '-' }}</td>
                <td>
                    <span class="status-circle status-{{ $attendance->status }}"></span>
                </td>
                <td class="text-left">{{ $attendance->observations ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>



    <footer>
        © {{ date('Y') }} Impulso Universitario A.C. — Reporte de asistencia generado automáticamente.
    </footer>
</body>

</html>