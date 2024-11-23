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

    .container {
        width: 100%;
        padding: 20px;
    }

    .header {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #333;
        padding-bottom: 10px;
    }

    .photo {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 20px;
    }

    .personal-info {
        flex-grow: 1;
    }

    .personal-info h1 {
        font-size: 24px;
        margin: 0;
    }

    .personal-info p {
        margin: 5px 0;
        font-size: 14px;
    }

    .section {
        margin-bottom: 20px;
    }

    .section h2 {
        font-size: 20px;
        border-bottom: 2px solid #333;
        margin-bottom: 10px;
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
    </style>
</head>

<body>
    <div class="container">
        <!-- Header con foto y datos personales -->
        <div class="header">
            <img src="storage/{{ $photo->url }}" alt="Foto" class="photo">
            <div class="personal-info">
                <h1>{{ $curriculum->first_name }} {{ $curriculum->last_name }}</h1>
                <p><strong>Email:</strong> {{ $curriculum->email }}</p>
                <p><strong>Teléfono:</strong> {{ $curriculum->phone_num ?? 'No registrado' }}</p>
                <p><strong>Ubicación:</strong> {{ $curriculum->locality }}, {{ $curriculum->state }},
                    {{ $curriculum->country }}
                </p>
                <p><strong>LinkedIn:</strong> {{ $curriculum->linkedin }}</p>
            </div>
        </div>

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