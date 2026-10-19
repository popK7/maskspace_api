<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Shop\CourierTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Suivi en direct du livreur cote CLIENT. */
class TrackingController extends Controller
{
    /**
     * GET /orders/{order}/tracking — position du livreur, etape et arrivee estimee.
     * Un client ne voit que ses propres commandes : 404 sinon.
     */
    public function __invoke(Request $request, CourierTracker $tracker, int $order): JsonResponse
    {
        $mine = Order::with(['shop', 'livreur.profile'])
            ->where('customer_id', $request->user()->id)->findOrFail($order);

        return response()->json(['data' => $tracker->snapshot($mine)]);
    }
}
