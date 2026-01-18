<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MassiveInformativeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function build()
    {

        return $this->from('vinculacion.laboral@iu.org.mx', 'Impulso Universitario')
            ->subject('Información Importante: Tu Registro Institucional')
            ->view('email.users_registration')
            ->with(['data' => $this->user]);
    }
}
