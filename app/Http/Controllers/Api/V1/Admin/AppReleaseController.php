<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Releases\AppReleaseStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppReleaseController extends Controller
{
    public function __construct(private readonly AppReleaseStore $releases) {}

    public function store(Request $request, string $platform): JsonResponse
    {
        if (! $this->releases->known($platform)) {
            return response()->json(['message' => 'Plateforme inconnue.', 'code' => 'not_found'], 404);
        }

        $extension = $this->releases->platforms()[$platform]['extension'];
        $data = $request->validate([
            'file' => ['required', 'file', 'max:131072', 'extensions:'.$extension],
        ], [
            'file.required' => 'Choisissez le fichier à publier.',
            'file.max' => 'Le fichier dépasse 128 Mo.',
            'file.extensions' => 'Le fichier doit se terminer par .'.$extension.'.',
        ]);

        $this->releases->store($platform, $data['file']);

        return response()->json(['data' => $this->releases->describe($platform)]);
    }
}
