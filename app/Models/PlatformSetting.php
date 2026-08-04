<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['data'];

    protected $casts = [
        'data' => 'array',
    ];

    public static function current(): self
    {
        $row = static::query()->first();

        if (! $row) {
            $row = static::create([
                'data' => [
                    'platformName'       => 'Lonto Academy',
                    'tagline'            => 'Formation professionnelle certifiante',
                    'supportEmail'       => 'contact@lonto-academy.com',
                    'supportPhone'       => '',
                    'currencyLabel'      => 'F',
                    'language'           => 'fr',
                    'accentColor'        => '#6bcf3a',
                    'accentDark'         => '#3d8f28',
                    'chromeBg'           => '#ffffff',
                    'chromeBgDeep'       => '#ffffff',
                    'chromeBgMid'        => '#f8fafc',
                    'brandBg'            => '#0a3058',
                    'brandBgDeep'        => '#062040',
                    'brandBgMid'         => '#1a4e8a',
                    'sidebarStyle'       => 'navy',
                    'density'            => 'comfortable',
                    'roundedCorners'     => true,
                    'allowRegistration'  => true,
                    'showPrices'         => true,
                    'maintenanceMode'    => false,
                    'emailNotifications' => true,
                    'newEnrollmentAlert' => true,
                    'weeklyReport'       => false,
                    'siteLogo'           => null,
                    'certificateLogo'    => null,
                    'showCertificateLogo'=> true,
                    'showCertificateQr'  => true,
                ],
            ]);
        }

        return $row;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = static::current()->data ?? [];

        if (! array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (in_array($key, [
            'roundedCorners', 'allowRegistration', 'showPrices', 'maintenanceMode',
            'emailNotifications', 'newEnrollmentAlert', 'weeklyReport',
            'showCertificateLogo', 'showCertificateQr',
        ], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value;
    }

    /** Chemin absolu d'un fichier logo stocké sous storage/app/public, ou null. */
    public static function logoAbsolutePath(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }

        $full = storage_path('app/public/'.$relativePath);

        return is_file($full) ? $full : null;
    }

    /** Chemin absolu du logo certificat pour DomPDF (ou logo site en secours). */
    public static function certificateLogoAbsolutePath(): ?string
    {
        if (! static::get('showCertificateLogo', true)) {
            return null;
        }

        $cert = static::logoAbsolutePath(static::get('certificateLogo'));
        if ($cert) {
            return $cert;
        }

        return static::logoAbsolutePath(static::get('siteLogo'));
    }
}
