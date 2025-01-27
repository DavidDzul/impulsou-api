<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currículum Vitae</title>

    <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

    body {
        /* font-family: Arial, sans-serif; */
        font-family: 'Poppins', sans-serif;
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
        width: 180px;
        height: 180px;
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
        color: #FF7900;
    }

    .personal-info-cell p {
        margin: 5px 0;
        font-size: 20px;
        font-weight: 500;
    }

    .container {
        font-family: 'Poppins', sans-serif;
        width: 100%;
        padding: 0px 10px 10px 10px
    }

    .header {
        margin-bottom: 5px;
        border-bottom: 2px;
        padding-bottom: 10px;
    }

    .section {
        margin-bottom: 20px;
        padding-top: 0px;
    }

    .section h2 {
        font-size: 18px;
        border-bottom: 2px solid #275FFC;
        margin-bottom: 10px;
        color: #275FFC;
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
        font-family: 'Poppins', sans-serif;
        text-align: center;
        /* Centra el nombre y título */
        margin-bottom: 20px;
    }

    .name-title h1 {
        font-family: 'Poppins', sans-serif;
        font-size: 26px;
    }

    .name-title h2 {
        font-family: 'Poppins', sans-serif;
        font-size: 20px;
    }

    .name-title p {
        font-family: 'Poppins', sans-serif;
        font-size: 18px;
        font-weight: 300;
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

    .img-title {
        margin-right: 5px;
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
                            <h1>{{ $curriculum->first_name }}
                                {{ $curriculum->last_name }}
                            </h1>
                            <br>
                            <!-- <h2>Datos de contacto</h2> -->
                            <label>
                                {{ $curriculum->email }}</label> <br>
                            <label> {{ $curriculum->phone_num }} -
                                {{ $curriculum->locality }}, {{ $curriculum->state }},
                                {{ $curriculum->country }}
                            </label>
                            @if($curriculum->linkedin)
                            <br>
                            <label> {{ $curriculum->linkedin }}
                            </label>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- <table class="aligned-table">
            <tr>
                <td class="left-cell">
                    <img class="img-title" src="https://iu.org.mx/wp-content/uploads/2025/01/email-icon.png" width="15">
                    {{ $curriculum->email }}
                </td>
                <td class="right-cell">
                    <img src="https://iu.org.mx/wp-content/uploads/2025/01/location-icon.png" width="20">
                    {{ $curriculum->locality }}, {{ $curriculum->state }}, {{ $curriculum->country }}
                </td>

            </tr>
            <tr>
                <td class="left-cell">
                    <img class="img-title" src="https://iu.org.mx/wp-content/uploads/2025/01/phone.png" width="15">
                    {{ $curriculum->phone_num }}
                </td>
                <td class="right-cell">{{ $curriculum->linkedin }}</td>
            </tr>
        </table> -->


        <!-- Resumen profesional -->
        <div class="section">
            <h2>Resumen Profesional</h2>
            <p>{{ $curriculum->professional_summary }}</p>
        </div>

        <!-- Educación -->
        @if(!empty($academic) && count($academic) > 0)
        <div class="section">
            <h2>Formación académica</h2>
            <ul class="academic">
                @foreach ($academic as $aca)
                <li class="list-item">
                    <strong>{{ $aca['postgraduate_name'] }}</strong> - {{ $aca['institute_name'] }} <br>
                    <small>{{ $aca['postgraduate_start_date'] }} - {{ $aca['postgraduate_end_date'] }}</small>

                </li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Experiencia laboral -->
        @if(!empty($workExperiences) && count($workExperiences) > 0)
        <div class="section">
            <h2>Experiencia Laboral</h2>
            <ul class="experience">
                @foreach ($workExperiences as $job)
                <li class="list-item">
                    <strong>{{ $job['job_position'] }}</strong> - {{ $job['business_name'] }} <br>
                    <small>{{ $job['start_date'] }} - {{ $job['end_date'] }}</small>
                    <ul>
                        <li> <b>Funciones:</b> {{ $job['responsibility'] }}</li>
                    </ul>

                </li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Educación continua -->
        @if(!empty($education) && count($education) > 0)
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
        @endif

        <!-- Conocimientos -->
        @if(!empty($skills) && count($skills) > 0)
        <div class="section">
            <h2>Conocimientos</h2>
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
        @endif

        <!-- Habilidades -->
        <div class="section">
            <h2>Habilidades</h2>
            <ul class="skills">
                @if($curriculum->skill_1)
                <li class="list-item">
                    <strong>
                        {{ $curriculum->skill_1 }}
                    </strong>
                </li>
                @endif

                @if($curriculum->skill_2)
                <li class="list-item">
                    <strong>
                        {{ $curriculum->skill_2 }}
                    </strong>
                </li>
                @endif

                @if($curriculum->skill_3)
                <li class="list-item">
                    <strong>
                        {{ $curriculum->skill_3 }}
                    </strong>
                </li>
                @endif

                @if($curriculum->skill_4)
                <li class="list-item">
                    <strong>
                        {{ $curriculum->skill_4 }}
                    </strong>
                </li>
                @endif

                @if($curriculum->skill_5)
                <li class="list-item">
                    <strong>
                        {{ $curriculum->skill_5 }}
                    </strong>
                </li>
                @endif
            </ul>
        </div>

    </div>
    @endif
</body>

</html>