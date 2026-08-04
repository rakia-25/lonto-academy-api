<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $resetUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('app.name', 'Lonto Academy');

        return (new MailMessage)
            ->subject("Réinitialisation de votre mot de passe — {$brand}")
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Vous avez demandé la réinitialisation de votre mot de passe.')
            ->action('Choisir un nouveau mot de passe', $this->resetUrl)
            ->line('Ce lien expire dans 60 minutes.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail.');
    }
}
