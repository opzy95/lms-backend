<?php

namespace App\Notifications;

use App\Services\ResendMailService;
use Illuminate\Notifications\Notification;

class ResetPasswordCode extends Notification
{
    public $code;

    public function __construct($code)
    {
        $this->code = $code;
    }

    /**
     * Don't use Laravel's mail driver - use Resend service directly
     */
    public function via($notifiable)
    {
        return [];
    }

    /**
     * Send email directly via Resend (not through Laravel mail driver)
     */
    public function send($notifiable)
    {
        $html = $this->getHtmlTemplate($notifiable->name);
        
        try {
            $service = new ResendMailService();
            $service->send(
                $notifiable->email,
                'Your EduGrowth Password Reset Code',
                $html,
                $this->getTextTemplate($notifiable->name)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to send password reset code email: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function getHtmlTemplate($name)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f0f0f0; padding: 20px; text-align: center; border-radius: 5px; }
        .content { padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-top: 20px; }
        .code { font-size: 24px; font-weight: bold; text-align: center; padding: 15px; background-color: #f9f9f9; border-radius: 5px; letter-spacing: 2px; }
        .footer { font-size: 12px; color: #999; margin-top: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>EduGrowth Password Reset</h1>
        </div>
        <div class="content">
            <p>Hello {$name},</p>
            <p>Your password reset code is:</p>
            <div class="code">{$this->code}</div>
            <p>This code expires in 15 minutes.</p>
            <p>If you did not request a password reset, please ignore this email.</p>
            <p>Best regards,<br>EduGrowth Team</p>
        </div>
        <div class="footer">
            <p>&copy; 2026 EduGrowth. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    protected function getTextTemplate($name)
    {
        return <<<TEXT
Hello {$name},

Your password reset code is: {$this->code}

This code expires in 15 minutes.

If you did not request a password reset, please ignore this email.

Best regards,
EduGrowth Team
TEXT;
    }
}

