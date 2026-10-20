<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Releases\AppReleaseStore;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    public function __construct(private readonly AppReleaseStore $releases) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'android' => $this->releases->describe(AppReleaseStore::ANDROID),
            'ios' => $this->releases->describe(AppReleaseStore::IOS),
        ]]);
    }

    public function show(string $platform): StreamedResponse|JsonResponse
    {
        if (! $this->releases->known($platform)) {
            return response()->json(['message' => 'Plateforme inconnue.', 'code' => 'not_found'], 404);
        }

        if (! $this->releases->exists($platform)) {
            return response()->json([
                'message' => 'Aucune version n’est encore publiée.',
                'code' => 'release_missing',
            ], 404);
        }

        return $this->releases->download($platform);
    }
}
