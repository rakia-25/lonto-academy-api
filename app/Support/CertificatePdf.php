<?php

namespace App\Support;

use App\Models\Certificate;
use App\Models\PlatformSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class CertificatePdf
{
    /**
     * Prépare les variables de vue PDF (logo/QR uniquement si GD est dispo).
     *
     * @return array{0:\Barryvdh\DomPDF\PDF,1:array}
     */
    public static function make(Certificate $certificate, $learner, $course, array $design): array
    {
        $canImages = extension_loaded('gd');
        $verifyUrl = rtrim((string) config('app.frontend_url'), '/').'/verifier/'.$certificate->verification_code;

        // DomPDF casse souvent sur les chemins Windows avec espaces :
        // on embarque le logo en data-URI base64.
        $logoPath = $canImages ? self::logoDataUri() : null;

        $showQr = $canImages && (bool) PlatformSetting::get('showCertificateQr', true);

        $viewData = [
            'certificate' => $certificate,
            'learner'     => $learner,
            'course'      => $course,
            'design'      => $design,
            'logoPath'    => $logoPath,
            'verifyUrl'   => $verifyUrl,
            'showQr'      => $showQr,
            'issuedLabel' => self::formatIssuedAt($certificate->issued_at),
        ];

        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => $showQr,
            'isHtml5ParserEnabled' => true,
            'chroot' => public_path(),
        ])->loadView('certificates.pdf', $viewData)
            ->setPaper('a4', 'landscape');

        return [$pdf, $viewData];
    }

    public static function formatIssuedAt(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('d/m/Y');
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return now()->format('d/m/Y');
        }
    }

    /** Data-URI du logo certificat pour DomPDF, ou null. */
    public static function logoDataUri(): ?string
    {
        $path = PlatformSetting::certificateLogoAbsolutePath();
        if (! $path || ! is_readable($path)) {
            return null;
        }

        $mime = @mime_content_type($path) ?: 'image/png';
        if (! str_starts_with($mime, 'image/')) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            };
        }

        $binary = @file_get_contents($path);
        if ($binary === false || $binary === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }
}
