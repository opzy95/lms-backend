<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordCode extends Notification
{
    use Queueable;
    
    public $code;

    public function __construct($code)
    {
        $this->code = $code;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Your EduGrowth Password Reset Code')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your password reset code is:')
            ->line('')
            ->line('**' . $this->code . '**')
            ->line('')
            ->line('This code expires in 60 minutes.')
            ->line('If you did not request a password reset, ignore this email.')
            ->salutation('Best regards, EduGrowth Team');
    }
}