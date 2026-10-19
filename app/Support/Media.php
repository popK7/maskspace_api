<?php

namespace App\Support;

/** Construit l'URL publique d'un fichier stocke (ou renvoie telle quelle une URL absolue). */
class Media
{
    /** Chemin de la miniature associee : `dir/abc.webp` -> `dir/abc_thumb.webp`. */
    public static function thumbPath(string $path): string
    {
        $dot = strrpos($path, '.');

        return $dot === false ? $path.'_thumb' : substr($path, 0, $dot).'_thumb'.substr($path, $dot);
    }

    /** URL de la miniature (les URL absolues n'ont pas de miniature : on renvoie l'image elle-meme). */
    public static function thumbUrl(?string $path): ?string
    {
        if ($path === null || $path === '' || str_starts_with($path, 'http')) {
            return self::url($path);
        }

        return self::url(self::thumbPath($path));
    }

    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // MEDIA_URL (CDN / S3) en production ; sinon chemin relatif /storage/... que le client prefixe
        // avec l'URL de l'API (evite de figer "localhost" dans les reponses).
        $base = config('shop.media_url');

        return $base ? rtrim($base, '/').'/'.ltrim($path, '/') : '/storage/'.ltrim($path, '/');
    }
}
