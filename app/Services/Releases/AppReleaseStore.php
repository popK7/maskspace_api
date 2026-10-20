<?php

namespace App\Services\Releases;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppReleaseStore
{
    public const ANDROID = 'android';

    public const IOS = 'ios';

    /** @return array<string, array{filename: string, download_name: string, mime: string, extension: string}> */
    public function platforms(): array
    {
        return [
            self::ANDROID => [
                'filename' => 'makspace.apk',
                'download_name' => 'MakSpace.apk',
                'mime' => 'application/vnd.android.package-archive',
                'extension' => 'apk',
            ],
            self::IOS => [
                'filename' => 'makspace.ipa',
                'download_name' => 'MakSpace.ipa',
                'mime' => 'application/octet-stream',
                'extension' => 'ipa',
            ],
        ];
    }

    public function known(string $platform): bool
    {
        return isset($this->platforms()[$platform]);
    }

    public function store(string $platform, UploadedFile $file): void
    {
        $meta = $this->platforms()[$platform];
        $file->storeAs('', $meta['filename'], 'releases');
    }

    public function exists(string $platform): bool
    {
        return Storage::disk('releases')->exists($this->path($platform));
    }

    public function download(string $platform): StreamedResponse
    {
        $meta = $this->platforms()[$platform];

        return Storage::disk('releases')->download($this->path($platform), $meta['download_name'], [
            'Content-Type' => $meta['mime'],
        ]);
    }

    /** @return array{platform: string, available: bool, url: string, bytes: int|null, updated_at: string|null} */
    public function describe(string $platform): array
    {
        $path = $this->path($platform);
        $disk = Storage::disk('releases');
        $available = $disk->exists($path);

        return [
            'platform' => $platform,
            'available' => $available,
            'url' => url('/api/v1/downloads/'.$platform),
            'bytes' => $available ? $disk->size($path) : null,
            'updated_at' => $available ? date(DATE_ATOM, $disk->lastModified($path)) : null,
        ];
    }

    private function path(string $platform): string
    {
        return $this->platforms()[$platform]['filename'];
    }
}
