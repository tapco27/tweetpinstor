<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTopupResource;
use App\Models\WalletTopup;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminWalletTopupController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $limit = (int) request('limit', 50);
        $limit = $limit > 0 ? min($limit, 200) : 50;

        $q = WalletTopup::query()->with(['paymentMethod', 'user', 'reviewer']);

        if ($status = request('status')) {
            $q->where('status', $status);
        }
        if ($currency = request('currency')) {
            $q->where('currency', strtoupper((string) $currency));
        }

        $p = $q->orderByDesc('id')->paginate($limit);

        return $this->ok(
            WalletTopupResource::collection($p->getCollection()),
            $this->paginationMeta($p)
        );
    }

    public function show($id)
    {
        $topup = WalletTopup::query()
            ->with(['paymentMethod', 'user', 'reviewer'])
            ->findOrFail($id);

        return $this->ok(new WalletTopupResource($topup));
    }

    /**
     * Returns a short-lived signed URL for the receipt image (admin-only).
     */
    public function receiptUrl($id)
    {
        $topup = WalletTopup::query()->findOrFail($id);

        $path = (string) ($topup->receipt_image_path ?? '');
        if ($path === '') {
            return $this->fail('Receipt not found', 404);
        }

        $expiresAt = now()->addMinutes(10);
        $url = Storage::disk('local')->temporaryUrl($path, $expiresAt);

        return $this->ok([
            'url' => $url,
            'expiresAt' => $expiresAt,
        ]);
    }

    public function approve(Request $request, WalletService $wallets, $id)
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $wallets->postTopup((int) $id, (int) auth('api')->id(), $data['review_note'] ?? null);

        // Reload for response
        $topup = WalletTopup::query()->with(['paymentMethod', 'user', 'reviewer'])->findOrFail((int) $id);

        return $this->ok([
            'message' => 'Topup posted',
            'topup' => (new WalletTopupResource($topup))->resolve(request()),
            'transaction' => $result['transaction'],
        ]);
    }

    public function reject(Request $request, WalletService $wallets, $id)
    {
        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:5000'],
        ]);

        $wallets->rejectTopup((int) $id, (int) auth('api')->id(), (string) $data['review_note']);

        $topup = WalletTopup::query()->with(['paymentMethod', 'user', 'reviewer'])->findOrFail((int) $id);

        return $this->ok([
            'message' => 'Topup rejected',
            'topup' => (new WalletTopupResource($topup))->resolve(request()),
        ]);
    }
}
