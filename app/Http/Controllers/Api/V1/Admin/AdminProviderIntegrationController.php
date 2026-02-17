<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderIntegration;
use App\Support\ProviderTemplates;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminProviderIntegrationController extends Controller
{
    /**
     * GET /admin/provider-templates
     */
    public function templates()
    {
        $all = ProviderTemplates::all();
        $out = [];

        foreach ($all as $code => $tpl) {
            if (!is_array($tpl)) continue;
            $out[] = [
                'code' => (string) ($tpl['code'] ?? $code),
                'type' => (string) ($tpl['type'] ?? 'api'),
                'names' => $tpl['names'] ?? null,
                'credential_fields' => $tpl['credential_fields'] ?? [],
                'notes' => $tpl['notes'] ?? null,
            ];
        }

        return response()->json([
            'templates' => $out,
        ]);
    }

    /**
     * GET /admin/provider-integrations
     */
    public function index()
    {
        return ProviderIntegration::query()
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * GET /admin/provider-integrations/{id}
     */
    public function show($id)
    {
        return ProviderIntegration::findOrFail($id);
    }

    /**
     * POST /admin/provider-integrations
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'template_code' => ['required','string','max:191'],
            'name' => ['required','string','max:255'],
            'is_active' => ['nullable','boolean'],
            'credentials' => ['nullable','array'],
            'meta' => ['nullable','array'],
        ]);

        $code = trim((string) $data['template_code']);
        if (!ProviderTemplates::exists($code)) {
            throw ValidationException::withMessages([
                'template_code' => ['Unknown template_code'],
            ]);
        }

        $integration = new ProviderIntegration();
        $integration->template_code = $code;
        $integration->name = (string) $data['name'];
        $integration->is_active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $integration->meta = $data['meta'] ?? null;

        if (array_key_exists('credentials', $data)) {
            $integration->credentials = $data['credentials'];
        }

        try {
            $integration->created_by = (int) (auth('api')->id() ?? null);
        } catch (\Throwable $e) {
            $integration->created_by = null;
        }

        $integration->save();

        return $integration;
    }

    /**
     * PUT /admin/provider-integrations/{id}
     */
    public function update(Request $r, $id)
    {
        $integration = ProviderIntegration::findOrFail($id);

        $data = $r->validate([
            'name' => ['sometimes','string','max:255'],
            'is_active' => ['sometimes','boolean'],
            'credentials' => ['sometimes','nullable','array'],
            'meta' => ['sometimes','nullable','array'],
        ]);

        if (array_key_exists('name', $data)) {
            $integration->name = (string) $data['name'];
        }
        if (array_key_exists('is_active', $data)) {
            $integration->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('meta', $data)) {
            $integration->meta = $data['meta'];
        }
        if (array_key_exists('credentials', $data)) {
            $integration->credentials = $data['credentials'];
        }

        $integration->save();

        return $integration;
    }

    /**
     * DELETE /admin/provider-integrations/{id}
     */
    public function destroy($id)
    {
        $integration = ProviderIntegration::findOrFail($id);
        $integration->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
