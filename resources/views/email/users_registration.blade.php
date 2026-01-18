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
                                alt="Impulso Universitario" title="Impulso Universitario" width="50%"
                                style="margin: 30px;" />
                        </td>
                    </tr>
                </table>

                <table class="container">
                    <tr>
                        <td class="header">
                            Confirmación de Registro Institucional
                        </td>
                    </tr>

                    <tr>
                        <td class="subheader">
                            Hola, {{ $data->first_name }} {{ $data->last_name }}
                        </td>
                    </tr>

                    <tr>
                        <td class="paragraph">
                            Te informamos que tu registro ha sido procesado correctamente. Ahora formas parte del
                            <b>Ecosistema Digital de Impulso Universitario</b>, integrando tu perfil en todas nuestras
                            plataformas de vinculación y seguimiento.
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding: 10px 6.25%;">
                            <table width="100%"
                                style="background-color: #F9F9F9; border-radius: 8px; border: 1px solid #E0E0E0;">
                                <tr>
                                    <td style="padding: 20px; text-align: center;">
                                        <span
                                            style="font-size: 14px; color: #666666; font-family: sans-serif; text-transform: uppercase; letter-spacing: 1px;">
                                            Matrícula Institucional
                                        </span><br>
                                        <span
                                            style="font-size: 24px; font-weight: bold; color: #000000; font-family: sans-serif; line-height: 150%;">
                                            {{ $data->enrollment }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="paragraph">
                            Esta matrícula es tu identificador único ante la institución. Este es un correo <b>meramente
                                informativo</b>, por lo que no es necesario realizar ninguna acción adicional por el
                            momento.
                        </td>
                    </tr>

                    <tr>
                        <td class="line">
                            <hr />
                        </td>
                    </tr>

                    @php
                    $contacto = match($data->campus) {
                    'MERIDA' => 'maria.may@iu.org.mx',
                    'VALLADOLID' => 'gilberto.rodriguez@iu.org.mx',
                    'TIZIMIN' => 'magaly.ac@iu.org.mx',
                    'OXKUTZCAB' => 'mervin.canche@iu.org.mx',
                    default => 'maria.may@iu.org.mx',
                    };
                    @endphp

                    <tr>
                        <td class="paragraph">
                            Si tienes dudas sobre tu registro o el alcance de nuestras plataformas, por favor contacta
                            al área de personal correspondiente o escríbenos a:
                            <br><br>
                            <a href="mailto:{{ $contacto }}"
                                style="text-decoration: underline; color: #ff7900; font-weight: bold;">
                                {{ $contacto }}
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td class="paragraph" style="font-size: 14px; color: #777777; padding-top: 0;">
                            Atentamente,<br>
                            <b>Equipo de Impulso Universitario A.C.</b>
                        </td>
                    </tr>

                    <tr>
                        <td
                            style="background-color: #F0F0F0; padding: 20px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                            <p
                                style="font-family: sans-serif; font-size: 12px; color: #999999; margin: 0; line-height: 140%;">
                                <b>Impulso Universitario A.C.</b><br>
                                Calle 62 #499 x 61 y 59, Centro. <br>
                                Mérida, Yucatán, México. C.P. 97000
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <br>
</body>

</html>