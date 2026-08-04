<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyMessage,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $brand = e(config('app.name', 'Lonto Academy'));
        $message = nl2br(e($this->bodyMessage));
        $button = '';

        if ($this->actionUrl && $this->actionLabel) {
            $url = e($this->actionUrl);
            $label = e($this->actionLabel);
            $button = <<<HTML
                <p style="margin:24px 0;">
                  <a href="{$url}" style="display:inline-block;padding:12px 20px;background:#d4a017;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">
                    {$label}
                  </a>
                </p>
            HTML;
        }

        return <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#0f1f3d;">
          <div style="height:4px;background:#d4a017;border-radius:2px;margin-bottom:20px;"></div>
          <h1 style="font-size:18px;margin:0 0 12px;">{$brand}</h1>
          <div style="font-size:14px;line-height:1.6;color:#334155;">{$message}</div>
          {$button}
          <p style="font-size:12px;color:#94a3b8;margin-top:28px;">
            Cet e-mail a été envoyé automatiquement par {$brand}.
          </p>
        </div>
        HTML;
    }
}
