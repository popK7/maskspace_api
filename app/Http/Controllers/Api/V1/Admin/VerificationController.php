<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderDocument;
use App\Services\Verification\ProviderVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Revue des pieces justificatives par un admin. */
class VerificationController extends Controller
{
    public function __construct(private readonly ProviderVerificationService $verification) {}

    /** GET /admin/verification-documents?status=pending&per_page= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([ProviderDocument::PENDING, ProviderDocument::APPROVED, ProviderDocument::REJECTED])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = ProviderDocument::with('user:id,name,email,phone')
            ->where('status', $filters['status'] ?? ProviderDocument::PENDING)
            ->oldest('updated_at')
            ->paginate($filters['per_page'] ?? 30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ProviderDocument $d) => $this->present($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /admin/verification-documents/{document}/file — le fichier n'est jamais public. */
    public function file(ProviderDocument $document): StreamedResponse
    {
        $disk = Storage::disk(ProviderDocument::DISK);
        abort_unless($disk->exists($document->path), 404, 'Fichier introuvable.');

        return $disk->response($document->path, null, [
            'Content-Type' => $document->mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** PATCH /admin/verification-documents/{document} { decision: approve|reject, reason? } */
    public function review(Request $request, ProviderDocument $document): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $document = $this->verification->review(
            $request->user(),
            $document,
            $data['decision'] === 'approve',
            $data['reason'] ?? null,
        );

        return response()->json(['data' => $this->present($document->load('user:id,name,email,phone'))]);
    }

    /** @return array<string, mixed> */
    private function present(ProviderDocument $d): array
    {
        return [
            'id' => $d->id,
            'role' => $d->role,
            'type' => $d->type,
            'label' => config("kyc.types.{$d->type}", $d->type),
            'status' => $d->status,
            'rejection_reason' => $d->rejection_reason,
            'mime' => $d->mime,
            'size' => $d->size,
            'original_name' => $d->original_name,
            'uploaded_at' => $d->updated_at?->toIso8601String(),
            'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            'user' => $d->user ? [
                'id' => $d->user->id,
                'name' => $d->user->name,
                'email' => $d->user->email,
                'phone' => $d->user->phone,
            ] : null,
        ];
    }
}
