<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** GET /merchant/reviews */
    public function index(Request $request): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        $reviews = Review::with(['customer:id,name', 'order:id,number'])->where('shop_id', $shop->id)->latest('id')->paginate(20);

        return response()->json([
            'summary' => ['rating_avg' => $shop->rating_avg, 'rating_count' => $shop->rating_count],
            'data' => $reviews->getCollection()->map(fn (Review $r) => $this->present($r))->values(),
            'meta' => ['current_page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage(), 'total' => $reviews->total()],
        ]);
    }

    /** POST /merchant/reviews/{review}/reply — le marchand repond publiquement a un avis. */
    public function reply(Request $request, int $review): JsonResponse
    {
        $data = $request->validate(['reply' => ['required', 'string', 'max:1000']]);

        $model = Review::where('shop_id', ShopController::shopOf($request)->id)->findOrFail($review);
        $model->update(['reply' => $data['reply'], 'replied_at' => now()]);

        return response()->json(['data' => $this->present($model->load(['customer:id,name', 'order:id,number']))]);
    }

    private function present(Review $r): array
    {
        return [
            'id' => $r->id,
            'order_number' => $r->order?->number,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'author' => $r->customer?->name,
            'reply' => $r->reply,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
