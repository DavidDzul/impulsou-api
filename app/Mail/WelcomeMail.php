<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $isInformative;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user, $isInformative = false)
    {
        $this->user = $user;
        $this->isInformative = $isInformative;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        if ($this->isInformative) {
            $subject = 'Confirmación de Registro Institucional - Impulso Universitario';
            $view = 'email.users_registration';
        } else {
            $subject = 'Tu registro ha sido exitoso. ¡Comienza a conectar!';
            $view = 'email.welcome';
        }

        return $this->from('vinculacion.laboral@iu.org.mx', 'Impulso Universitario')
            ->subject($subject)
            ->view($view)
            ->with(['data' => $this->user]);
    }
}
