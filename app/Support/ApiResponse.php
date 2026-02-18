<?php

namespace App\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function requestId(): ?string
    {
        return request()->attributes->get('request_id')
            ?? request()->header('X-Request-Id');
    }

    protected function ok(mixed $data = null, array $meta = [], int $status = 200)
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        $rid = $this->requestId();
        if ($rid) {
            $meta = array_merge(['request_id' => $rid], $meta);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => $meta ?: (object)[],
            'errors' => null,
        ], $status);
    }

    protected function fail(string $message, int $status = 422, array $details = [], array $meta = [])
    {
        $rid = $this->requestId();
        if ($rid) {
            $meta = array_merge(['request_id' => $rid], $meta);
        }

        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => $meta ?: (object)[],
            'errors' => [
                'message' => $message,
                'details' => $details ?: (object)[],
            ],
        ], $status);
    }

    protected function paginationMeta(LengthAwarePaginator $p): array
    {
        return [
            'page' => $p->currentPage(),
            'limit' => $p->perPage(),
            'total' => $p->total(),
            'last_page' => $p->lastPage(),
        ];
    }
}
