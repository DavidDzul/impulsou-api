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
        background-color: red;
    }

    .status-JUSTIFIED_LATE {
        background-color: blue;
    }

    .status-JUSTIFIED_ABSENCE {
        background-color: pink;
    }

    .status-PRESENT {
        background-color: green;
    }

    .status-LATE {
        background-color: orange;
    }

    /* Leyenda de estatus */
    .status-legend {
        margin-top: 10px;
        margin-bottom: 10px;
        font-size: 11px;
        /* Usamos display: flex para que funcione la alineación inline */
        display: flex;
        flex-wrap: wrap;
    }

    .status-legend span {
        display: inline-flex;
        align-items: center;
        margin-right: 15px;
        /* Evitar saltos de línea dentro de un span */
        white-space: nowrap;
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
    <header>
        <table class="header-table">
            <tr>
                <td style="width: 120px;">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSbyEMoD-R6QqnO_YdjNFGWpfMvcmiSCcRYrw&s"
                        alt="Logo" class="logo">
                </td>

                <td style="text-align: center;">
                    <h2 style="color: #FF7900;">REPORTE GENERAL DE ASISTENCIAS</h2>
                </td>

                <td style="width: 200px; text-align: right; font-size: 11px; color: #666;">
                    Impulso Universitario A.C.<br>
                    Calle 62 #383 por 45 y 47, Centro, 97000 Mérida, Yuc.<br>
                    (999) 9 45-27-10<br>
                    contacto@iu.org.mx
                </td>
            </tr>
        </table>

        <div style="margin-top: 10px; font-size: 13px;">
            <p style="margin: 2px 0;">
                <strong>Año:</strong> {{ $year }} &nbsp;|&nbsp;
                <strong>Semestre:</strong> {{ $semester === 1 ? 'Enero - Junio' : 'Agosto - Diciembre' }} &nbsp;|&nbsp;
                @php
                $campusNames = [
                'MERIDA' => 'Mérida',
                'TIZIMIN' => 'Tizimín',
                'VALLADOLID' => 'Valladolid',
                'OXKUTZCAB' => 'Oxkutzcab'
                ];
                @endphp
                <strong>Sede:</strong> {{ $campusNames[$campus] ?? $campus }}
            </p>
            <p style="margin: 2px 0;">
                <strong>Generación:</strong> {{ $generation }} <br>
            </p>
        </div>
    </header>

    <div style="margin-top: 15px;">
        <strong>Estatus de Asistencia:</strong>
        <div class="status-legend">
            <span><span class="status-circle status-ABSENT"></span> Falta</span>
            <span><span class="status-circle status-LATE"></span> Retardo</span>
            <span><span class="status-circle status-PRESENT"></span> Presente</span>
            <span><span class="status-circle status-JUSTIFIED_LATE"></span> Retardo Justificado</span>
            <span><span class="status-circle status-JUSTIFIED_ABSENCE"></span> Falta Justificada</span>
        </div>
    </div>


    <!-- <table>
        <thead>
            <tr>
                <th>#</th>
                <th style="width: 25%; text-align: left;">Alumno</th>
                <th style="width: 25%; text-align: left;">Clase</th>
                <th>Fecha</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Estatus</th>
                <th style="width: 20%;">Observaciones</th>
            </tr>
        </thead>

        <tbody>
            @foreach($reportData as $i => $row)
            <tr>
                <td>{{ $i+1 }}</td>
                <td class="text-left">{{ $row['user'] }}</td>
                <td class="text-left">{{ $row['class'] }}</td>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['check_in'] ?? '-' }}</td>
                <td>{{ $row['check_out'] ?? '-' }}</td>
                <td>
                    <span class="status-circle status-{{ $row['status'] }}"></span>
                </td>
                <td class="text-left">{{ $row['observations'] ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table> -->


    @foreach($reportDataGrouped as $className => $rows)

    <div>
        <strong>Sesión:</strong> {{ $className }}
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th style="width: 25%;" class="text-left">Alumno</th>
                <th>Fecha</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Estatus</th>
                <th style="width: 20%;">Observaciones</th>
            </tr>
        </thead>

        <tbody>
            @foreach($rows as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="text-left">{{ $row['user'] }}</td>
                <td>{{ \Carbon\Carbon::parse($row['date'])->format('d-m-Y') }}</td>
                <td>{{ $row['check_in'] ?? '-' }}</td>
                <td>{{ $row['check_out'] ?? '-' }}</td>
                <td>
                    <span class="status-circle status-{{ $row['status'] }}"></span>
                </td>
                <td class="text-left">{{ $row['observations'] ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Salto de página opcional --}}
    <div class="page-break"></div>

    @endforeach

    <footer>
        © {{ date('Y') }} Impulso Universitario A.C. — Reporte de asistencia generado automáticamente.
    </footer>
</body>

</html>
