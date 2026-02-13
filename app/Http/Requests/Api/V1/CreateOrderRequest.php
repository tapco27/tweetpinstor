<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateOrderRequest extends FormRequest
{
  public function authorize(): bool { return true; }

  public function rules(): array
  {
    return [
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
        ->with('category:id,requirement_key')
        ->find($productId);

      if (!$product || !$product->category) return;

      $key = $product->category->requirement_key;
      if (!$key) return;

      $meta = (array)($this->input('metadata', []));

      if (!array_key_exists($key, $meta) || trim((string)$meta[$key]) === '') {
        $v->errors()->add("metadata.$key", "Required field: $key");
      }

      // تنسيقات إضافية بسيطة
      if ($key === 'email' && isset($meta['email']) && !filter_var($meta['email'], FILTER_VALIDATE_EMAIL)) {
        $v->errors()->add('metadata.email', 'Invalid email');
      }

      if ($key === 'phone' && isset($meta['phone'])) {
        $phone = preg_replace('/\s+/', '', (string)$meta['phone']);
        // تحقق بسيط: رقم فقط + ممكن يبدأ +
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
          $v->errors()->add('metadata.phone', 'Invalid phone number');
        }
      }
    });
  }
}
