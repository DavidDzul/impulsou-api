<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0;">
    <meta name="format-detection" content="telephone=no" />

    <style>
        /* Reset styles */
        body {
            margin: 0;
            padding: 0;
            min-width: 100%;
            width: 100% !important;
            height: 100% !important;
            background-color: #F0F0F0;
            color: #000000;
            -webkit-font-smoothing: antialiased;
            text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            -webkit-text-size-adjust: 100%;
            line-height: 100%;
        }

        table {
            border-collapse: collapse !important;
            border-spacing: 0;
        }

        img {
            border: 0;
            line-height: 100%;
            outline: none;
            text-decoration: none;
            -ms-interpolation-mode: bicubic;
        }

        .background {
            width: 100%;
        }

        .wrapper {
            max-width: 560px;
            width: 100%;
        }

        .container {
            background-color: #FFFFFF;
            max-width: 560px;
            width: 100%;
            border-radius: 8px;
            text-align: center;
        }

        .preheader {
            display: none;
            visibility: hidden;
            overflow: hidden;
            opacity: 0;
            font-size: 1px;
            line-height: 1px;
            height: 0;
            max-height: 0;
            max-width: 0;
            color: #F0F0F0;
        }

        .header {
            font-size: 24px;
            font-weight: bold;
            line-height: 130%;
            color: #000000;
            font-family: sans-serif;
            padding: 25px 6.25% 0 6.25%;
        }

        .subheader {
            font-size: 18px;
            font-weight: 300;
            line-height: 150%;
            color: #000000;
            font-family: sans-serif;
            padding: 5px 6.25% 3px 6.25%;
        }

        .paragraph {
            font-size: 17px;
            font-weight: 400;
            line-height: 160%;
            color: #000000;
            font-family: sans-serif;
            padding: 25px 6.25%;
        }

        .credentials {
            font-size: 17px;
            font-weight: 400;
            line-height: 0%;
            color: #000000;
            font-family: sans-serif;
            padding: 15px 6.25%;
        }

        .button-container {
            padding: 25px 6.25% 5px 6.25%;

        }

        .button {
            padding: 12px 24px;
            margin: 0;
            text-decoration: none;
            border-radius: 4px;
            background-color: #ff7900;
            color: #FFFFFF;
            font-family: sans-serif;
            font-size: 17px;
            font-weight: 400;
            line-height: 120%;
        }

        .line {
            padding: 25px 6.25% 0px;
        }

        .line hr {
            border: none;
            height: 1px;
            background-color: #E0E0E0;
        }
    </style>

    <title>Geofile MX</title>
</head>

<body>
    <table class="background">
        <tr>
            <td align="center">
                <table class="wrapper">
                    <tr>
                        <td align="center">
                            <img src="https://iu.org.mx/wp-content/uploads/2024/11/Impulso_Universitario_Logotipo_Alternativo_RGB_Positivo.png"
                                alt="Logo Geofile" title="Geofile Mx" width="50%" style="margin: 30px;" />
                        </td>
                    </tr>
                </table>

                <table class="container">
                    <tr>
                        <td class="header">
                            ¡Bienvenido a la Plataforma de Vinculación Laboral, {{ $data->first_name }}
                            {{ $data->last_name }}!
                        </td>
                    </tr>

                    <tr>
                        <td class="paragraph">
                            Nos alegra tenerte con nosotros. Ahora puedes acceder a la plataforma y
                            comenzar a explorar todas nuestras funcionalidades.
                        </td>
                    </tr>

                    <tr>
                        <td class="paragraph">
                            Haz clic en el siguiente botón para ingresar a tu cuenta.
                        </td>
                    </tr>
                    <tr>
                        <td class="credentials">
                            @if($data->user_type == 'BUSINESS')
                            <b>Correo electrónico:</b> {{ $data->email }}
                            @else
                            <b>Matrícula:</b> {{ $data->enrollment }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="credentials">
                            <b>Contraseña: </b> Agentedecambio
                        </td>
                    </tr>
                    <tr>
                        <td class="button-container">
                            <a class="button" style="color: white;"
                                href="https://develop.iu.org.mx/login?redirect=/inicio">
                                Iniciar sesión
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td class="line">
                            <hr />
                        </td>
                    </tr>

                    <tr>
                        <td class="paragraph">
                            Si tienes alguna pregunta o necesitas ayuda, contáctanos en
                            <a href="mailto:vinculacion.laboral@iu.org.mx"
                                style="text-decoration: underline; color: inherit;">
                                vinculacion.laboral@iu.org.mx
                            </a>.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <br>
</body>

</html>