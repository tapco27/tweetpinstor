<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPaymentMethodController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $limit = (int) request('limit', 50);
        $limit = $limit > 0 ? min($limit, 200) : 50;

        $p = PaymentMethod::query()->orderByDesc('id')->paginate($limit);

        return $this->ok(
            PaymentMethodResource::collection($p->getCollection()),
            $this->paginationMeta($p)
        );
    }

    public function show($id)
    {
        $pm = PaymentMethod::query()->findOrFail($id);
        return $this->ok(new PaymentMethodResource($pm));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:payment_methods,code'],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:manual,gateway'],
            'scope' => ['required', 'in:topup,order,both'],
            'currency' => ['nullable', 'in:TRY,SYP'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
            'config' => ['nullable', 'array'],
        ]);

        $pm = new PaymentMethod();
        foreach ($data as $k => $v) {
            $pm->{$k} = $v;
        }
        $pm->save();

        return $this->ok(new PaymentMethodResource($pm), [], 201);
    }

    public function update(Request $request, $id)
    {
        $pm = PaymentMethod::query()->findOrFail($id);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('payment_methods', 'code')->ignore($pm->id)],
            'name' => ['sometimes', 'string', 'max:200'],
            'type' => ['sometimes', 'in:manual,gateway'],
            'scope' => ['sometimes', 'in:topup,order,both'],
            'currency' => ['nullable', 'in:TRY,SYP'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'],
        ]);

        foreach ($data as $k => $v) {
            $pm->{$k} = $v;
        }
        $pm->save();

        return $this->ok(new PaymentMethodResource($pm));
    }

    public function destroy($id)
    {
        $pm = PaymentMethod::query()->findOrFail($id);
        $pm->delete();
        return $this->ok(['message' => 'Deleted']);
    }
}
