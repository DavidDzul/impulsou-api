<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currículum Vitae</title>

    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        color: #333;
    }

    .info-table {
        width: 100%;
        border-spacing: 20px;
        table-layout: fixed;
    }

    .photo-cell {
        width: 30%;
        vertical-align: middle;
        text-align: center;
    }

    .photo {
        width: 200px;
        height: 200px;
        border-radius: 50%;
        object-fit: cover;
    }

    .personal-info-cell {
        vertical-align: middle;
        /* Centra verticalmente los datos */
        color: #333;
    }

    .personal-info-cell h1 {
        font-size: 24px;
        margin: 0;
        color: #0056b3;
    }

    .personal-info-cell p {
        margin: 5px 0;
        font-size: 14px;
    }

    .container {
        width: 100%;
        padding: 0px 10px 10px 10px
    }

    .header {
        margin-bottom: 20px;
        border-bottom: 2px;
        padding-bottom: 10px;
    }

    .section {
        margin-bottom: 20px;
    }

    .section h2 {
        font-size: 20px;
        border-bottom: 2px solid #0056b3;
        margin-bottom: 10px;
        color: #0056b3;
    }

    .list-item {
        margin: 5px 0;
        font-size: 14px;
    }

    .education,
    .experience,
    .skills {
        padding-left: 15px;
    }

    .name-title {
        text-align: center;
        /* Centra el nombre y título */
        margin-bottom: 20px;
    }

    .aligned-table {
        width: 100%;
        /* La tabla ocupa todo el ancho */
        border-spacing: 0;
        /* Sin espacio entre celdas */
        table-layout: fixed;
        /* Ancho fijo para distribuir columnas equitativamente */
    }

    .left-cell {
        text-align: left;
        /* Alinea la primera columna a la izquierda */
        padding: 5px;
        width: 50%;
        /* Ocupa el 50% del ancho */
    }

    .right-cell {
        text-align: right;
        /* Alinea la segunda columna a la derecha */
        padding: 5px;
        width: 50%;
        /* Ocupa el 50% del ancho */
    }
    </style>
</head>

<body>

    <div class="container">
        <!-- Header con foto y datos personales -->
        <div class="header">
            <table class="info-table">
                <tr>
                    <!-- Primera columna: Foto -->
                    <td class="photo-cell">
                        <img src="storage/{{ $photo->url }}" alt="Foto" class="photo">
                    </td>
                    <!-- Segunda columna: Datos personales -->
                    <td class="personal-info-cell">
                        <div class="name-title">
                            <h1>{{ $curriculum->first_name }} {{ $curriculum->last_name }}</h1>
                            <h2>{{ $curriculum->professional_title }}</h2>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="aligned-table">
            <tr>
                <td class="left-cell">{{ $curriculum->email }}</td>
                <td class="right-cell">{{ $curriculum->locality }}, {{ $curriculum->state }}, {{ $curriculum->country }}
                </td>

            </tr>
            <tr>
                <td class="left-cell">{{ $curriculum->phone_num ?? 'No registrado' }}</td>
                <td class="right-cell">{{ $curriculum->linkedin }}</td>
            </tr>
        </table>


        <!-- Resumen profesional -->
        <div class="section">
            <h2>Resumen Profesional</h2>
            <p>{{ $curriculum->professional_summary }}</p>
        </div>

        <!-- Educación -->
        <div class="section">
            <h2>Educación</h2>
            <ul class="education">
                @foreach ($education as $edu)
                <li class="list-item">
                    <strong>{{ $edu['degree'] }}</strong> - {{ $edu['institution'] }} <br>
                    <small>{{ $edu['start_year'] }} - {{ $edu['end_year'] }}</small>
                </li>
                @endforeach
            </ul>
        </div>

        <!-- Experiencia laboral -->
        <div class="section">
            <h2>Experiencia Laboral</h2>
            <ul class="experience">
                @foreach ($workExperiences as $job)
                <li class="list-item">
                    <strong>{{ $job['job_title'] }}</strong> - {{ $job['company'] }} <br>
                    <small>{{ $job['start_date'] }} - {{ $job['end_date'] ?? 'Actualidad' }}</small>
                    <p>{{ $job['description'] }}</p>
                </li>
                @endforeach
            </ul>
        </div>

        <!-- Habilidades y conocimientos -->
        <div class="section">
            <h2>Habilidades y Conocimientos</h2>
            <ul class="skills">
                @foreach ($skills as $skill)
                <li class="list-item">{{ $skill }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</body>

</html>