<?php

namespace App\Services\Media;

use App\Support\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stockage des images envoyees par les utilisateurs.
 *
 * L'image est DECODEE puis RE-ENCODEE (GD) : cela redimensionne, supprime les metadonnees EXIF (GPS...)
 * et neutralise tout contenu non-image deguise (polyglot).
 */
class ImageStorage
{
    public const DISK = 'public';

    /** @return string chemin relatif sur le disque (a stocker en base) */
    public function store(UploadedFile $file, string $directory): string
    {
        $binary = @file_get_contents($file->getRealPath());
        $image = $binary === false ? false : @imagecreatefromstring($binary);

        if ($image === false) {
            throw ValidationException::withMessages(['image' => ['Image illisible ou format non supporté.']]);
        }

        $image = $this->applyExifOrientation($image, $file);

        return $this->storeWithThumbnail($image, trim($directory, '/').'/'.Str::uuid());
    }

    /**
     * Stocke l'image deja decodee en pleine taille (1280 px) + une miniature `_thumb` (480 px) pour les listes.
     *
     * @return string chemin de l'image pleine taille
     */
    private function storeWithThumbnail(\GdImage $image, string $basePath): string
    {
        $useWebp = function_exists('imagewebp');
        $ext = $useWebp ? '.webp' : '.jpg';
        $path = $basePath.$ext;

        // La miniature est produite depuis une copie : resize() detruit l'image qu'on lui passe.
        $thumb = $this->resize($this->copy($image), (int) config('shop.thumb_dimension'));
        $full = $this->resize($image, (int) config('shop.image_max_dimension'));

        $disk = Storage::disk(self::DISK);
        $disk->put($path, $this->encode($full, $useWebp));
        $disk->put(Media::thumbPath($path), $this->encode($thumb, $useWebp, 76));

        return $path;
    }

    /**
     * (Re)genere la miniature d'une image deja stockee (donnees de demo, anciennes images).
     */
    public function ensureThumbnail(string $path): void
    {
        $disk = Storage::disk(self::DISK);
        $thumbPath = Media::thumbPath($path);

        if (str_starts_with($path, 'http') || ! $disk->exists($path) || $disk->exists($thumbPath)) {
            return;
        }

        $image = @imagecreatefromstring((string) $disk->get($path));
        if ($image === false) {
            return;
        }

        $useWebp = str_ends_with($path, '.webp');
        $thumb = $this->resize($image, (int) config('shop.thumb_dimension'));
        $disk->put($thumbPath, $this->encode($thumb, $useWebp, 76));
    }

    /** Supprime un fichier stocke et sa miniature (ignore les URL absolues). */
    public function delete(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk(self::DISK)->delete([$path, Media::thumbPath($path)]);
        }
    }

    private function encode(\GdImage $image, bool $webp, int $quality = 82): string
    {
        ob_start();
        $webp ? imagewebp($image, null, $quality) : imagejpeg($image, null, $quality + 3);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded;
    }

    private function copy(\GdImage $image): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagealphablending($copy, true);
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }

    private function resize(\GdImage $image, int $max): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($w <= $max && $h <= $max) {
            return $image;
        }

        $ratio = $w >= $h ? $max / $w : $max / $h;
        $resized = imagescale($image, (int) round($w * $ratio), (int) round($h * $ratio));
        imagedestroy($image);

        return $resized ?: throw ValidationException::withMessages(['image' => ['Impossible de traiter cette image.']]);
    }

    private function applyExifOrientation(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated !== false) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
