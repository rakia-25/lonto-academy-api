<?php

namespace App\Support;

use App\Models\Exam;
use App\Models\PlatformSetting;

class CertificateDesign
{
    public const TEMPLATES = ['classic', 'elegant', 'modern', 'seal'];

    public static function templatesMeta(): array
    {
        return [
            [
                'id'          => 'classic',
                'label'       => 'Classique',
                'description' => 'Cadre doré, style académique traditionnel.',
            ],
            [
                'id'          => 'elegant',
                'label'       => 'Élégant',
                'description' => 'Double bordure et composition formelle.',
            ],
            [
                'id'          => 'modern',
                'label'       => 'Moderne',
                'description' => 'Bandeau latéral, mise en page épurée.',
            ],
            [
                'id'          => 'seal',
                'label'       => 'Sceau',
                'description' => 'Cachet décoratif et disposition solennelle.',
            ],
        ];
    }

    public static function defaults(?string $type = 'certificat'): array
    {
        $isAttestation = $type === 'attestation';
        $brand = PlatformSetting::get('platformName', 'Lonto Academy');
        $accent = PlatformSetting::get('accentColor', '#d4a017');

        return [
            'template'               => 'classic',
            'brand_name'             => $brand,
            'title'                  => $isAttestation ? 'Attestation de réussite' : 'Certificat de réussite',
            'subtitle'               => $isAttestation
                ? 'Attestation officielle de formation professionnelle'
                : 'Certificat officiel de formation professionnelle',
            'awarded_label'          => 'Décerné à',
            'course_label'           => 'Pour avoir terminé avec succès le cours',
            'footer'                 => "Vérifiez l'authenticité de ce document sur la plateforme {$brand}.",
            'accent_color'           => $accent ?: '#d4a017',
            'text_color'             => '#0f1f3d',
            'signer_name'            => '',
            'signer_title'           => '',
            'show_date'              => true,
            'show_verification_code' => true,
            'show_signer'            => false,
        ];
    }

    public static function normalize(?array $design, ?string $type = 'certificat'): array
    {
        $base = self::defaults($type);
        $design = is_array($design) ? $design : [];

        $merged = array_merge($base, array_intersect_key($design, $base));

        $template = (string) ($merged['template'] ?? 'classic');
        $merged['template'] = in_array($template, self::TEMPLATES, true) ? $template : 'classic';

        foreach (['brand_name', 'title', 'subtitle', 'awarded_label', 'course_label', 'footer', 'signer_name', 'signer_title'] as $key) {
            $merged[$key] = trim((string) ($merged[$key] ?? ''));
            if (mb_strlen($merged[$key]) > 255) {
                $merged[$key] = mb_substr($merged[$key], 0, 255);
            }
        }

        $merged['accent_color'] = self::sanitizeHex($merged['accent_color'] ?? '#d4a017', '#d4a017');
        $merged['text_color'] = self::sanitizeHex($merged['text_color'] ?? '#0f1f3d', '#0f1f3d');

        foreach (['show_date', 'show_verification_code', 'show_signer'] as $key) {
            $merged[$key] = filter_var($merged[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        if ($merged['title'] === '') {
            $merged['title'] = self::defaults($type)['title'];
        }

        return $merged;
    }

    public static function forExam(?Exam $exam): array
    {
        $type = $exam?->certificate_type ?? 'certificat';

        return self::normalize($exam?->certificate_design, $type);
    }

    public static function sanitizeHex(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);
        if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value)) {
            return strtolower($value);
        }

        return $fallback;
    }
}
