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
            font-size: 20px;
            font-weight: 500;
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

        .academic,
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
    @if(isset($curriculum))
    <div class="container">
        <!-- Header con foto y datos personales -->
        <div class="header">
            <table class="info-table">
                <tr>
                    <!-- Primera columna: Foto -->
                    @if($photo)
                    <td class="photo-cell">
                        <img src='{{ "storage/$photo->url" }}' alt="Foto" class="photo">
                    </td>
                    @endif
                    <!-- Segunda columna: Datos personales -->
                    <td class="personal-info-cell">
                        <div class="name-title">
                            <h1>{{ $curriculum->first_name }} {{ $curriculum->last_name }}</h1>
                            <p>{{ $curriculum->professional_title }}</p>
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
            <ul class="academic">
                @foreach ($academic as $aca)
                <li class="list-item">
                    <strong>{{ $aca['postgraduate_name'] }}</strong> - {{ $aca['institute_name'] }} <br>
                    <small>{{ $aca['postgraduate_start_date'] }} - {{ $aca['postgraduate_end_date'] }}</small>
                    <p>- {{ $aca['highlight'] }}</p>
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
                    <strong>{{ $job['job_position'] }}</strong> - {{ $job['business_name'] }} <br>
                    <small>{{ $job['start_date'] }} - {{ $job['end_date'] }}</small>
                    <ul>
                        <li> <b>Funciones:</b> {{ $job['responsibility'] }}</li>
                        <li> <b>Logros:</b> {{ $job['achievement'] }}</li>
                    </ul>

                </li>
                @endforeach
            </ul>
        </div>

        <!-- Educación continua -->
        <div class="section">
            <h2>Educación continua</h2>
            <ul class="experience">
                @foreach ($education as $edu)
                <li class="list-item">
                    <strong>{{ $edu['course_name'] }}</strong> - {{ $edu['course_institute'] }} <br>
                    <small>{{ $edu['course_start_date'] }} - {{ $edu['course_end_date'] }}</small>
                </li>
                @endforeach
            </ul>
        </div>

        <!-- Habilidades y conocimientos -->
        <div class="section">
            <h2>Habilidades y Conocimientos</h2>
            <ul class="skills">
                @foreach ($skills as $skill)
                <li class="list-item">
                    <strong>
                        {{
                $skill['type'] === 'SOFTWARE' ? 'Software' :
                ($skill['type'] === 'LANGUAGE' ? 'Lenguaje' :
                ($skill['type'] === 'OTHER' ? $skill['other_knowledge'] : ""))
            }}
                    </strong> <br>
                    <small>{{ $skill['description_knowledge'] }} -



                        {{
                $skill['level'] === 'BEGINNER' ? 'Principiante' :
                ($skill['level'] === 'INTERMEDIATE' ? 'Intermedio' :
                ($skill['level'] === 'ADVANCED' ? 'Avanzado' : ""))
            }}

                    </small>

                </li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif
</body>

</html>