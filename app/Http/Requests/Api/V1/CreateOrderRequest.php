<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\PurchaseRequirements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateOrderRequest extends FormRequest
{
  public function authorize(): bool { return true; }

  public function rules(): array
  {
    return [
      'order_uuid' => ['required','uuid'],

      'product_id' => ['required','integer','exists:products,id'],
      'package_id' => ['nullable','integer','exists:product_packages,id'],
      'quantity' => ['nullable','integer','min:1'],
      'metadata' => ['nullable','array'],
    ];
  }

  public function withValidator(Validator $validator): void
  {
    $validator->after(function (Validator $v) {

      $productId = $this->input('product_id');
      if (!$productId) return;

      $product = Product::query()
        ->with(['category:id,requirements,requirement_key,purchase_mode', 'providerSlots.integration'])
        ->find($productId);

      if (!$product || !$product->category) return;

      // Validate package_id / quantity based on product_type
      if ((string) $product->product_type === 'fixed_package') {
        if ($this->input('package_id') === null || $this->input('package_id') === '') {
          $v->errors()->add('package_id', 'package_id is required for fixed_package products');
        }
      } elseif ((string) $product->product_type === 'flexible_quantity') {
        if ($this->input('package_id') !== null && $this->input('package_id') !== '') {
          $v->errors()->add('package_id', 'package_id is not allowed for flexible_quantity products');
        }

        if ($this->input('quantity') === null || $this->input('quantity') === '') {
          $v->errors()->add('quantity', 'quantity is required for flexible_quantity products');
        } else {
          // Validate min/max quantity using active price for current currency (avoid 500 from PricingService)
          $currency = app()->bound('user_currency') ? app('user_currency') : null;
          $priceGroupId = app()->bound('price_group_id') ? (int) app('price_group_id') : 1;
          if ($priceGroupId <= 0) $priceGroupId = 1;
          if ($currency) {
            $price = ProductPrice::query()
              ->where('product_id', $product->id)
              ->where('currency', $currency)
              ->where('price_group_id', $priceGroupId)
              ->where('is_active', true)
              ->first();

            $qty = (int) $this->input('quantity');
            if ($price) {
              if ($price->min_qty !== null && $qty < (int) $price->min_qty) {
                $v->errors()->add('quantity', 'Quantity below min');
              }
              if ($price->max_qty !== null && $qty > (int) $price->max_qty) {
                $v->errors()->add('quantity', 'Quantity above max');
              }
            }
          }
        }
      }

      $requiredKeys = method_exists($product->category, 'effectiveRequirements')
        ? $product->category->effectiveRequirements()
        : (is_array($product->category->requirements ?? null) ? $product->category->requirements : []);

      if (!$requiredKeys || count($requiredKeys) === 0) return;

      $meta = (array)($this->input('metadata', []));

      foreach ($requiredKeys as $key) {
        $err = PurchaseRequirements::validateValue((string) $key, $meta[$key] ?? null);
        if ($err !== null) {
          $msg = $err === 'Required field' ? "Required field: $key" : $err;
          $v->errors()->add("metadata.$key", $msg);
        }
      }

      // ✅ Phase 7: Tweet-Pin mapping & qty_values constraints (prevent avoidable provider errors)
      $this->validateTweetPinConstraints($v, $product);
    });
  }

  private function validateTweetPinConstraints(Validator $v, Product $product): void
  {
    // Pick first active slot (1 then 2)
    $slots = $product->providerSlots ?? null;
    if (!is_iterable($slots)) return;

    $active = [];
    foreach ($slots as $s) {
      if (!is_object($s)) continue;
      $sn = (int) ($s->slot ?? 0);
      if (!in_array($sn, [1,2], true)) continue;
      if ((bool) ($s->is_active ?? true) !== true) continue;
      if (!isset($s->integration)) continue;
      $active[$sn] = $s;
    }
    if (count($active) === 0) return;
    ksort($active);
    $chosen = $active[array_key_first($active)] ?? null;

    if (!$chosen) return;

    $integration = $chosen->integration ?? null;
    if (!$integration) return;

    $template = (string)($integration->template_code ?? '');
    if (!in_array($template, ['tweet_pin','tweetpin','tweet-pin'], true)) {
      return;
    }

    $cfg = [];
    if (is_array($product->fulfillment_config ?? null)) {
      $cfg = $product->fulfillment_config;
    }
    if (is_array($chosen->override_config ?? null)) {
      $cfg = array_merge($cfg, $chosen->override_config);
    }

    // Mapping presence
    $pid = $cfg['provider_product_id'] ?? $cfg['tweetpin_product_id'] ?? $cfg['remote_product_id'] ?? null;
    $pkgMap = $cfg['tweetpin_package_map'] ?? $cfg['package_map'] ?? null;

    if ((string)$product->product_type === 'flexible_quantity') {
      if (!is_numeric($pid) || (int)$pid <= 0) {
        $v->errors()->add('product_id', 'Tweet-Pin mapping missing: provider_product_id');
        return;
      }
    }

    if ((string)$product->product_type === 'fixed_package') {
      $packageId = $this->input('package_id');
      if ($packageId !== null && $packageId !== '') {
        $packageId = (int)$packageId;
        $usesPackageMap = false;
        if (is_array($pkgMap)) {
          $key = (string)$packageId;
          if (array_key_exists($key, $pkgMap) && is_numeric($pkgMap[$key])) {
            $usesPackageMap = true;
          }
          if (array_key_exists($packageId, $pkgMap) && is_numeric($pkgMap[$packageId])) {
            $usesPackageMap = true;
          }
        }
        if (!$usesPackageMap && (!is_numeric($pid) || (int)$pid <= 0)) {
          $v->errors()->add('package_id', 'Tweet-Pin mapping missing for this package');
          return;
        }

        // If this package does NOT map to a dedicated remote product id, we will use provider_product_id.
        // In that case, validate the provider qty_values using the qty that will be sent (default=1, or
        // from tweetpin_package_qty_map).
        if (!$usesPackageMap) {
          $qtyValues = $cfg['tweetpin_qty_values'] ?? $cfg['qty_values'] ?? null;

          // qty used for provider call (FulfillmentService): fixed_package defaults to 1
          $providerQty = 1;
          $qmap = $cfg['tweetpin_package_qty_map'] ?? $cfg['package_qty_map'] ?? null;
          if (is_array($qmap)) {
            $key = (string)$packageId;
            $val = null;
            if (array_key_exists($key, $qmap)) {
              $val = $qmap[$key];
            } elseif (array_key_exists($packageId, $qmap)) {
              $val = $qmap[$packageId];
            }
            if (is_numeric($val) && (int)$val > 0) {
              $providerQty = (int)$val;
            }
          }

          // Validate qtyValues only when configured (or when providerQty != 1)
          // - null => qty must be 1
          if ($qtyValues === null) {
            if ($providerQty !== 1) {
              $v->errors()->add('package_id', 'Provider quantity must be 1 for this package');
            }
          } elseif (is_array($qtyValues)) {
            // list
            if (array_is_list($qtyValues)) {
              $allowed = [];
              foreach ($qtyValues as $val2) {
                if (is_numeric($val2)) $allowed[] = (int)$val2;
              }
              $allowed = array_values(array_unique($allowed));
              if (count($allowed) > 0 && !in_array((int)$providerQty, $allowed, true)) {
                $v->errors()->add('package_id', 'Provider quantity not allowed for this package');
              }
            } else {
              // object {min,max}
              $min = $qtyValues['min'] ?? null;
              $max = $qtyValues['max'] ?? null;
              if (is_numeric($min) && is_numeric($max)) {
                if ($providerQty < (int)$min) {
                  $v->errors()->add('package_id', 'Provider quantity below min for this package');
                }
                if ($providerQty > (int)$max) {
                  $v->errors()->add('package_id', 'Provider quantity above max for this package');
                }
              }
            }
          }
        }
      }
    }

    // qty_values constraints for flexible_quantity
    if ((string)$product->product_type !== 'flexible_quantity') {
      return;
    }

    $qty = $this->input('quantity');
    $qty = $qty === null || $qty === '' ? null : (int)$qty;
    if ($qty === null) return;

    $qtyValues = $cfg['tweetpin_qty_values'] ?? $cfg['qty_values'] ?? null;

    // null -> must be 1
    if ($qtyValues === null) {
      if ($qty !== 1) {
        $v->errors()->add('quantity', 'Quantity must be 1 for this provider product');
      }
      return;
    }

    if (is_array($qtyValues)) {
      // list
      if (array_is_list($qtyValues)) {
        $allowed = [];
        foreach ($qtyValues as $val) {
          if (is_numeric($val)) $allowed[] = (int)$val;
        }
        $allowed = array_values(array_unique($allowed));
        if (count($allowed) > 0 && !in_array((int)$qty, $allowed, true)) {
          $v->errors()->add('quantity', 'Quantity not allowed for this provider product');
        }
        return;
      }

      // object {min,max}
      $min = $qtyValues['min'] ?? null;
      $max = $qtyValues['max'] ?? null;
      if (is_numeric($min) && is_numeric($max)) {
        if ($qty < (int)$min) {
          $v->errors()->add('quantity', 'Quantity below provider min');
        }
        if ($qty > (int)$max) {
          $v->errors()->add('quantity', 'Quantity above provider max');
        }
      }
    }
  }
}
