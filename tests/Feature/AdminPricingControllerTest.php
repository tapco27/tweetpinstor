<?php

namespace Tests\Feature;

use App\Models\FxRate;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPricingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): User
    {
        Role::findOrCreate('admin', 'api');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin, 'api');

        return $admin;
    }

    private function createPricingFixture(array $overrides = []): array
    {
        $product = Product::query()->create(array_merge([
            'product_type' => 'fixed_package',
            'name_ar' => 'منتج',
            'currency_mode' => 'TRY',
            'cost_unit_usd' => 1.5,
            'suggested_unit_usd' => 2.0,
        ], $overrides));

        $price = ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_group_id' => 1,
            'currency' => 'TRY',
            'minor_unit' => 2,
            'unit_price_minor' => 1000,
            'is_active' => true,
        ]);

        $package = ProductPackage::query()->create([
            'product_price_id' => $price->id,
            'name_ar' => 'باقة',
            'value_label' => '50K',
            'price_minor' => 2000,
            'cost_usd' => 3.0,
            'suggested_usd' => 4.0,
            'is_active' => true,
        ]);

        return compact('product', 'price', 'package');
    }

    public function test_grid_success_returns_snapshot_rows(): void
    {
        $this->asAdmin();
        $fixture = $this->createPricingFixture();

        $response = $this->getJson('/api/v1/admin/pricing/grid?price_group_id=1&currency=TRY');

        $response->assertOk()
            ->assertJsonPath('data.affected_count', 0)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.snapshot.rows.0.id', $fixture['price']->id);
    }

    public function test_grid_failure_on_invalid_filters(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v1/admin/pricing/grid?price_group_id=9999&currency=EUR');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price_group_id', 'currency']);
    }

    public function test_recalculate_usd_success_updates_prices_and_packages(): void
    {
        $this->asAdmin();
        $fixture = $this->createPricingFixture();

        FxRate::query()->create([
            'pair' => 'USD_TRY',
            'base_currency' => 'USD',
            'quote_currency' => 'TRY',
            'rate' => 40,
        ]);

        $response = $this->postJson('/api/v1/admin/pricing/recalculate-usd', [
            'scope' => 'all',
            'price_group_id' => 1,
            'currency' => 'TRY',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.affected_count', 2)
            ->assertJsonPath('data.errors', []);

        $this->assertEquals(8000, $fixture['price']->fresh()->unit_price_minor); // 2.0 * 40 * 100
        $this->assertEquals(16000, $fixture['package']->fresh()->price_minor); // 4.0 * 40 * 100
    }

    public function test_recalculate_usd_failure_on_invalid_scope(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v1/admin/pricing/recalculate-usd', [
            'scope' => 'invalid',
            'currency' => 'EUR',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scope', 'currency']);
    }

    public function test_batch_update_tier_success_updates_target_and_reports_item_errors(): void
    {
        $this->asAdmin();
        $fixture = $this->createPricingFixture();

        $response = $this->postJson('/api/v1/admin/pricing/batch-update-tier', [
            'target' => 'price',
            'ids' => [$fixture['price']->id, 999999],
            'action' => 'increase_amount_minor',
            'value' => 500,
            'currency' => 'TRY',
            'price_group_id' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.affected_count', 1)
            ->assertJsonCount(1, 'data.errors')
            ->assertJsonPath('data.errors.0.id', 999999);

        $this->assertEquals(1500, $fixture['price']->fresh()->unit_price_minor);
    }

    public function test_batch_update_tier_failure_on_invalid_payload(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v1/admin/pricing/batch-update-tier', [
            'target' => 'x',
            'ids' => [],
            'action' => 'unknown',
            'value' => -1,
            'currency' => 'EUR',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target', 'ids', 'action', 'value', 'currency']);
    }

    public function test_batch_update_usd_success_updates_products_and_reports_scope_mismatch_errors(): void
    {
        $this->asAdmin();
        $fixtureTry = $this->createPricingFixture(['currency_mode' => 'TRY', 'cost_unit_usd' => 1, 'suggested_unit_usd' => 2]);
        $fixtureUsd = $this->createPricingFixture(['currency_mode' => 'USD', 'cost_unit_usd' => 3, 'suggested_unit_usd' => 5]);

        $response = $this->postJson('/api/v1/admin/pricing/batch-update-usd', [
            'target' => 'product',
            'ids' => [$fixtureTry['product']->id, $fixtureUsd['product']->id],
            'action' => 'increase_amount',
            'field' => 'suggested',
            'value' => 0.5,
            'currency_scope' => 'TRY',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.affected_count', 1)
            ->assertJsonCount(1, 'data.errors')
            ->assertJsonPath('data.errors.0.reason', 'currency_scope_mismatch');

        $this->assertEquals(2.5, (float) $fixtureTry['product']->fresh()->suggested_unit_usd);
        $this->assertEquals(5.0, (float) $fixtureUsd['product']->fresh()->suggested_unit_usd);
    }

    public function test_batch_update_usd_failure_on_invalid_payload(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/api/v1/admin/pricing/batch-update-usd', [
            'target' => 'none',
            'ids' => ['x'],
            'action' => 'wrong',
            'field' => 'invalid',
            'currency_scope' => 'SYP',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target', 'ids.0', 'action', 'field', 'currency_scope']);
    }
}
