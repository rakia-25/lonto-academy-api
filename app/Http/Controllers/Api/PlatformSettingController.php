<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlatformSettingController extends Controller
{
    /** Paramètres publics (lecture pour tout le monde). */
    public function show()
    {
        return response()->json($this->normalize(PlatformSetting::current()->data ?? []));
    }

    /** Mise à jour réservée aux admins. */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'platformName'       => 'sometimes|string|max:120',
            'tagline'            => 'sometimes|nullable|string|max:255',
            'supportEmail'       => 'sometimes|nullable|email|max:255',
            'supportPhone'       => 'sometimes|nullable|string|max:50',
            'currencyLabel'      => 'sometimes|string|max:10',
            'language'           => 'sometimes|string|in:fr,en',
            'accentColor'        => 'sometimes|string|max:20',
            'accentDark'         => 'sometimes|string|max:20',
            'chromeBg'           => 'sometimes|string|max:20',
            'chromeBgDeep'       => 'sometimes|string|max:20',
            'chromeBgMid'        => 'sometimes|string|max:20',
            'brandBg'            => 'sometimes|string|max:20',
            'brandBgDeep'        => 'sometimes|string|max:20',
            'brandBgMid'         => 'sometimes|string|max:20',
            'sidebarStyle'       => 'sometimes|string|in:navy,charcoal',
            'density'            => 'sometimes|string|in:comfortable,compact',
            'roundedCorners'     => 'sometimes|boolean',
            'uiFont'             => 'sometimes|string|in:dm-sans,source-sans,nunito,plex',
            'uiTextScale'        => 'sometimes|string|in:sm,md,lg',
            'uiTextContrast'     => 'sometimes|string|in:soft,balanced,strong',
            'allowRegistration'  => 'sometimes|boolean',
            'showPrices'         => 'sometimes|boolean',
            'maintenanceMode'    => 'sometimes|boolean',
            'emailNotifications' => 'sometimes|boolean',
            'newEnrollmentAlert' => 'sometimes|boolean',
            'weeklyReport'       => 'sometimes|boolean',
            'showCertificateLogo'=> 'sometimes|boolean',
            'showCertificateQr'  => 'sometimes|boolean',
        ]);

        $boolKeys = [
            'roundedCorners', 'allowRegistration', 'showPrices', 'maintenanceMode',
            'emailNotifications', 'newEnrollmentAlert', 'weeklyReport',
            'showCertificateLogo', 'showCertificateQr',
        ];

        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $validated)) {
                $validated[$key] = filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $row = PlatformSetting::current();
        $row->update([
            'data' => array_merge($row->data ?? [], $validated),
        ]);

        Audit::log(
            'settings.update',
            'Mise à jour des paramètres de la plateforme',
            $row,
            ['changed_keys' => array_keys($validated)]
        );

        return response()->json([
            'message'  => 'Paramètres enregistrés.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    public function uploadSiteLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,webp,gif|max:4096',
        ]);

        $row = PlatformSetting::current();
        $data = $row->data ?? [];

        if (! empty($data['siteLogo']) && Storage::disk('public')->exists($data['siteLogo'])) {
            Storage::disk('public')->delete($data['siteLogo']);
        }

        $path = $request->file('logo')->store('site-logos', 'public');
        $data['siteLogo'] = $path;
        $row->update(['data' => $data]);

        return response()->json([
            'message'  => 'Logo du site enregistré.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    public function deleteSiteLogo()
    {
        $row = PlatformSetting::current();
        $data = $row->data ?? [];

        if (! empty($data['siteLogo']) && Storage::disk('public')->exists($data['siteLogo'])) {
            Storage::disk('public')->delete($data['siteLogo']);
        }

        unset($data['siteLogo']);
        $row->update(['data' => $data]);

        return response()->json([
            'message'  => 'Logo du site supprimé.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    public function uploadFavicon(Request $request)
    {
        $request->validate([
            'favicon' => 'required|file|mimes:png,jpg,jpeg,webp,gif,ico,svg|max:2048',
        ]);

        $row = PlatformSetting::current();
        $data = $row->data ?? [];

        if (! empty($data['favicon']) && Storage::disk('public')->exists($data['favicon'])) {
            Storage::disk('public')->delete($data['favicon']);
        }

        $path = $request->file('favicon')->store('favicons', 'public');
        $data['favicon'] = $path;
        $row->update(['data' => $data]);

        return response()->json([
            'message'  => 'Icône du navigateur enregistrée.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    public function deleteFavicon()
    {
        $row = PlatformSetting::current();
        $data = $row->data ?? [];

        if (! empty($data['favicon']) && Storage::disk('public')->exists($data['favicon'])) {
            Storage::disk('public')->delete($data['favicon']);
        }

        unset($data['favicon']);
        $row->update(['data' => $data]);

        return response()->json([
            'message'  => 'Icône du navigateur supprimée.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    public function uploadCertificateLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,webp,gif|max:4096',
        ]);

        $row = PlatformSetting::current();
        $data = $row->data ?? [];

        if (! empty($data['certificateLogo']) && Storage::disk('public')->exists($data['certificateLogo'])) {
            Storage::disk('public')->delete($data['certificateLogo']);
        }

        $path = $request->file('logo')->store('certificate-logos', 'public');
        $data['certificateLogo'] = $path;
        $data['showCertificateLogo'] = true;
        $row->update(['data' => $data]);

        return response()->json([
            'message'  => 'Logo du certificat enregistré.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    public function deleteCertificateLogo()
    {
        $row = PlatformSetting::current();
        $data = $row->data ?? [];

        if (! empty($data['certificateLogo']) && Storage::disk('public')->exists($data['certificateLogo'])) {
            Storage::disk('public')->delete($data['certificateLogo']);
        }

        unset($data['certificateLogo']);
        // Garde l'affichage actif si un logo site peut servir de secours
        if (empty($data['siteLogo'])) {
            $data['showCertificateLogo'] = false;
        }
        $row->update(['data' => $data]);

        return response()->json([
            'message'  => 'Logo du certificat supprimé.',
            'settings' => $this->normalize($row->fresh()->data ?? []),
        ]);
    }

    private function normalize(array $data): array
    {
        foreach ([
            'roundedCorners', 'allowRegistration', 'showPrices', 'maintenanceMode',
            'emailNotifications', 'newEnrollmentAlert', 'weeklyReport',
            'showCertificateLogo', 'showCertificateQr',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (! array_key_exists('showCertificateLogo', $data)) {
            $data['showCertificateLogo'] = ! empty($data['certificateLogo']) || ! empty($data['siteLogo']);
        }
        if (! array_key_exists('showCertificateQr', $data)) {
            $data['showCertificateQr'] = true;
        }
        if (! array_key_exists('siteLogo', $data)) {
            $data['siteLogo'] = null;
        }
        if (! array_key_exists('favicon', $data)) {
            $data['favicon'] = null;
        }

        return $data;
    }
}
