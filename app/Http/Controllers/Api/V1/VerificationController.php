<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Verification\ProviderVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pieces justificatives du prestataire connecte (role courant). */
class VerificationController extends Controller
{
    public function __construct(private readonly ProviderVerificationService $verification) {}

    /** GET /me/verification */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->verification->overview($request->user())]);
    }

    /** POST /me/verification/documents/{type} (multipart, champ `file`) */
    public function upload(Request $request, string $type): JsonResponse
    {
        $request->merge(['type' => $type]);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(config('kyc.types')))],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('kyc.mimes')), 'max:'.(int) config('kyc.max_kb')],
        ]);

        $this->verification->upload($request->user(), $data['type'], $request->file('file'));

        return response()->json(['data' => $this->verification->overview($request->user())], 201);
    }
}
