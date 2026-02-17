<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'requirements' => 'array',
    ];

    /**
     * Requirements used for purchase metadata.
     *
     * Backward compatible:
     * - if requirements is empty, falls back to legacy requirement_key.
     */
    public function effectiveRequirements(): array
    {
        $req = $this->requirements;

        if (is_array($req)) {
            $req = array_values(array_filter($req, fn ($x) => is_string($x) && trim($x) !== ''));
        } else {
            $req = [];
        }

        if (count($req) > 0) {
            return $req;
        }

        if (!empty($this->requirement_key)) {
            return [trim((string) $this->requirement_key)];
        }

        return [];
    }
}
