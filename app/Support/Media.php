<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Media
{
    public static function disk(): Filesystem
    {
        return Storage::disk('public');
    }

    public static function usingCloud(): bool
    {
        return config('filesystems.disks.public.driver') === 's3';
    }

    /** Téléchargement (local ou R2/S3). */
    public static function download(string $path, ?string $name = null): StreamedResponse|BinaryFileResponse
    {
        return static::disk()->download($path, $name ?? basename($path));
    }

    /** Affichage inline (local ou R2/S3). */
    public static function inline(string $path, ?string $name = null, ?string $mime = null): StreamedResponse|BinaryFileResponse
    {
        $headers = [];
        if ($mime) {
            $headers['Content-Type'] = $mime;
        }

        return static::disk()->response($path, $name ?? basename($path), $headers);
    }

    /**
     * Chemin local lisible (fichier déjà sur disque, ou copie temporaire depuis R2).
     * Utile pour DomPDF / intervention/image.
     */
    public static function localPath(string $path): ?string
    {
        $disk = static::disk();

        if (! $disk->exists($path)) {
            return null;
        }

        if (! static::usingCloud()) {
            $absolute = $disk->path($path);

            return is_file($absolute) ? $absolute : null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        $tmp = tempnam(sys_get_temp_dir(), 'lonto_media_');
        if ($tmp === false) {
            return null;
        }

        $local = $tmp.'.'.$ext;
        @unlink($tmp);

        $contents = $disk->get($path);
        if ($contents === null || file_put_contents($local, $contents) === false) {
            return null;
        }

        return $local;
    }
}
