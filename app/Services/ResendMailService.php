<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class ResendMailService
{
    protected $apiKey;
    protected $fromEmail;
    protected $baseUrl = 'https://api.resend.com';

    public function __construct()
    {
        $this->apiKey = env('RESEND_API_KEY');
        $this->fromEmail = env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev');

        if (!$this->apiKey) {
            throw new Exception('RESEND_API_KEY is not set in environment variables');
        }
    }

    /**
     * Send email via Resend API
     */
    public function send($to, $subject, $html, $text = null)
    {
        try {
            \Log::info('Sending email via Resend', [
                'to' => $to,
                'subject' => $subject,
                'from' => $this->fromEmail
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/emails', [
                'from' => $this->fromEmail,
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
            ]);

            \Log::info('Resend API Response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->failed()) {
                $error = $response->json('message') ?? $response->body();
                throw new Exception('Resend API error: ' . $error);
            }

            return $response->json();
        } catch (Exception $e) {
            \Log::error('Resend mail service error: ' . $e->getMessage());
            throw $e;
        }
    }
}
