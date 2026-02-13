<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoCatalogSeeder extends Seeder
{
  public function run(): void
  {
    DB::transaction(function () {

      // تنظيف (للـ demo/local)
      DB::table('banners')->delete();
      DB::table('product_packages')->delete();
      DB::table('product_prices')->delete();
      DB::table('products')->delete();
      DB::table('categories')->delete();

      // ===== Categories (مع requirement_key) =====
      $appsId = DB::table('categories')->insertGetId([
        'name_ar' => 'شحن التطبيقات',
        'name_tr' => 'Uygulama Yükleme',
        'name_en' => 'Apps Top Up',
        'is_active' => true,
        'sort_order' => 1,
        'requirement_key' => 'uid',
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $gamesId = DB::table('categories')->insertGetId([
        'name_ar' => 'شحن الألعاب',
        'name_tr' => 'Oyun Yükleme',
        'name_en' => 'Games Top Up',
        'is_active' => true,
        'sort_order' => 2,
        'requirement_key' => 'player_id',
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $giftCardsId = DB::table('categories')->insertGetId([
        'name_ar' => 'بطاقات الهدايا',
        'name_tr' => 'Hediye Kartları',
        'name_en' => 'Gift Cards',
        'is_active' => true,
        'sort_order' => 3,
        'requirement_key' => 'email',
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $mobileTopupId = DB::table('categories')->insertGetId([
        'name_ar' => 'شحن رصيد الهاتف المحمول',
        'name_tr' => 'Mobil Hat Yükleme',
        'name_en' => 'Mobile Top Up',
        'is_active' => true,
        'sort_order' => 4,
        'requirement_key' => 'phone',
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // ===== Products =====
      // 1) Soulchill (fixed_package) => Apps Top Up (uid)
      $soulchillId = DB::table('products')->insertGetId([
        'category_id' => $appsId,
        'product_type' => 'fixed_package',

        // fulfillment
        'fulfillment_type' => 'api',
        'provider_code' => 'soulchill_crystal',
        'fulfillment_config' => json_encode(['path' => '/fulfill/soulchill']),

        'name_ar' => 'Soulchill - شحن كريستال',
        'name_tr' => 'Soulchill - Crystal Yükleme',
        'name_en' => 'Soulchill - Crystal Top Up',
        'description_ar' => 'شحن كريستال Soulchill بشكل سريع وآمن.',
        'description_tr' => 'Soulchill Crystal hızlı ve güvenli yükleme.',
        'description_en' => 'Fast and secure Soulchill crystal top up.',
        'image_url' => null,
        'is_active' => true,
        'is_featured' => true,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // 2) PUBG (fixed_package) => Games Top Up (player_id)
      $pubgId = DB::table('products')->insertGetId([
        'category_id' => $gamesId,
        'product_type' => 'fixed_package',

        // fulfillment
        'fulfillment_type' => 'api',
        'provider_code' => 'pubg_uc',
        'fulfillment_config' => json_encode(['path' => '/fulfill/pubg']),

        'name_ar' => 'PUBG Mobile - شحن UC',
        'name_tr' => 'PUBG Mobile - UC Yükleme',
        'name_en' => 'PUBG Mobile - UC Top Up',
        'description_ar' => 'شحن UC لـ PUBG Mobile.',
        'description_tr' => 'PUBG Mobile için UC yükleme.',
        'description_en' => 'PUBG Mobile UC top up.',
        'image_url' => null,
        'is_active' => true,
        'is_featured' => true,
        'sort_order' => 2,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // 3) Netflix (fixed_package) => Gift Cards (email)
      $netflixId = DB::table('products')->insertGetId([
        'category_id' => $giftCardsId,
        'product_type' => 'fixed_package',

        // fulfillment
        'fulfillment_type' => 'api',
        'provider_code' => 'netflix_sub',
        'fulfillment_config' => json_encode(['path' => '/fulfill/netflix']),

        'name_ar' => 'Netflix - اشتراك',
        'name_tr' => 'Netflix - Abonelik',
        'name_en' => 'Netflix - Subscription',
        'description_ar' => 'اشتراكات Netflix (حسب التوفر).',
        'description_tr' => 'Netflix abonelikleri (stok durumuna göre).',
        'description_en' => 'Netflix subscriptions (subject to availability).',
        'image_url' => null,
        'is_active' => true,
        'is_featured' => true,
        'sort_order' => 3,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // (اختياري) مثال منتج للـ Mobile Top Up (phone)
      $mobileTopupProductId = DB::table('products')->insertGetId([
        'category_id' => $mobileTopupId,
        'product_type' => 'fixed_package',

        // fulfillment
        'fulfillment_type' => 'api',
        'provider_code' => 'mobile_topup',
        'fulfillment_config' => json_encode(['path' => '/fulfill/mobile-topup']),

        'name_ar' => 'شحن رصيد الهاتف',
        'name_tr' => 'Mobil Hat Yükleme',
        'name_en' => 'Mobile Balance Top Up',
        'description_ar' => 'شحن رصيد الهاتف المحمول.',
        'description_tr' => 'Mobil hat bakiyesi yükleme.',
        'description_en' => 'Mobile balance top up.',
        'image_url' => null,
        'is_active' => true,
        'is_featured' => false,
        'sort_order' => 4,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // ===== Product Prices (channels) =====
      // TRY channel (minor_unit=2)
      $soulTry = DB::table('product_prices')->insertGetId([
        'product_id' => $soulchillId,
        'currency' => 'TRY',
        'minor_unit' => 2,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $pubgTry = DB::table('product_prices')->insertGetId([
        'product_id' => $pubgId,
        'currency' => 'TRY',
        'minor_unit' => 2,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $netflixTry = DB::table('product_prices')->insertGetId([
        'product_id' => $netflixId,
        'currency' => 'TRY',
        'minor_unit' => 2,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $mobileTry = DB::table('product_prices')->insertGetId([
        'product_id' => $mobileTopupProductId,
        'currency' => 'TRY',
        'minor_unit' => 2,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // SYP channel (minor_unit=0)
      $soulSyp = DB::table('product_prices')->insertGetId([
        'product_id' => $soulchillId,
        'currency' => 'SYP',
        'minor_unit' => 0,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $pubgSyp = DB::table('product_prices')->insertGetId([
        'product_id' => $pubgId,
        'currency' => 'SYP',
        'minor_unit' => 0,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $netflixSyp = DB::table('product_prices')->insertGetId([
        'product_id' => $netflixId,
        'currency' => 'SYP',
        'minor_unit' => 0,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $mobileSyp = DB::table('product_prices')->insertGetId([
        'product_id' => $mobileTopupProductId,
        'currency' => 'SYP',
        'minor_unit' => 0,
        'unit_price_minor' => null,
        'min_qty' => null,
        'max_qty' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      // ===== Packages (fixed_package) =====
      // Soulchill TRY
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $soulTry,
          'name_ar' => '50,000 كريستال',
          'name_tr' => '50.000 Crystal',
          'name_en' => '50,000 Crystal',
          'value_label' => '50,000 CRYSTAL',
          'price_minor' => 3950 * 100,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $soulTry,
          'name_ar' => '100,000 كريستال',
          'name_tr' => '100.000 Crystal',
          'name_en' => '100,000 Crystal',
          'value_label' => '100,000 CRYSTAL',
          'price_minor' => 7800 * 100,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // Soulchill SYP (مثال)
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $soulSyp,
          'name_ar' => '50,000 كريستال',
          'name_tr' => '50.000 Crystal',
          'name_en' => '50,000 Crystal',
          'value_label' => '50,000 CRYSTAL',
          'price_minor' => 950000,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $soulSyp,
          'name_ar' => '100,000 كريستال',
          'name_tr' => '100.000 Crystal',
          'name_en' => '100,000 Crystal',
          'value_label' => '100,000 CRYSTAL',
          'price_minor' => 1900000,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // PUBG TRY
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $pubgTry,
          'name_ar' => '60 UC',
          'name_tr' => '60 UC',
          'name_en' => '60 UC',
          'value_label' => '60 UC',
          'price_minor' => 120 * 100,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $pubgTry,
          'name_ar' => '325 UC',
          'name_tr' => '325 UC',
          'name_en' => '325 UC',
          'value_label' => '325 UC',
          'price_minor' => 590 * 100,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // PUBG SYP (مثال)
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $pubgSyp,
          'name_ar' => '60 UC',
          'name_tr' => '60 UC',
          'name_en' => '60 UC',
          'value_label' => '60 UC',
          'price_minor' => 65000,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $pubgSyp,
          'name_ar' => '325 UC',
          'name_tr' => '325 UC',
          'name_en' => '325 UC',
          'value_label' => '325 UC',
          'price_minor' => 290000,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // Netflix TRY
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $netflixTry,
          'name_ar' => 'شهر واحد',
          'name_tr' => '1 Ay',
          'name_en' => '1 Month',
          'value_label' => '1 Month',
          'price_minor' => 250 * 100,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $netflixTry,
          'name_ar' => '3 أشهر',
          'name_tr' => '3 Ay',
          'name_en' => '3 Months',
          'value_label' => '3 Months',
          'price_minor' => 700 * 100,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // Netflix SYP (مثال)
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $netflixSyp,
          'name_ar' => 'شهر واحد',
          'name_tr' => '1 Ay',
          'name_en' => '1 Month',
          'value_label' => '1 Month',
          'price_minor' => 350000,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $netflixSyp,
          'name_ar' => '3 أشهر',
          'name_tr' => '3 Ay',
          'name_en' => '3 Months',
          'value_label' => '3 Months',
          'price_minor' => 950000,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // Mobile Top Up TRY (مثال)
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $mobileTry,
          'name_ar' => 'شحن 50 ليرة',
          'name_tr' => '50 TL Yükleme',
          'name_en' => '50 TRY Top Up',
          'value_label' => '50',
          'price_minor' => 50 * 100,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $mobileTry,
          'name_ar' => 'شحن 100 ليرة',
          'name_tr' => '100 TL Yükleme',
          'name_en' => '100 TRY Top Up',
          'value_label' => '100',
          'price_minor' => 100 * 100,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // Mobile Top Up SYP (مثال)
      DB::table('product_packages')->insert([
        [
          'product_price_id' => $mobileSyp,
          'name_ar' => 'شحن 50,000 ل.س',
          'name_tr' => '50.000 SYP Yükleme',
          'name_en' => '50,000 SYP Top Up',
          'value_label' => '50000',
          'price_minor' => 50000,
          'is_popular' => true,
          'is_active' => true,
          'sort_order' => 1,
          'created_at' => now(),
          'updated_at' => now(),
        ],
        [
          'product_price_id' => $mobileSyp,
          'name_ar' => 'شحن 100,000 ل.س',
          'name_tr' => '100.000 SYP Yükleme',
          'name_en' => '100,000 SYP Top Up',
          'value_label' => '100000',
          'price_minor' => 100000,
          'is_popular' => false,
          'is_active' => true,
          'sort_order' => 2,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);

      // ===== Banners =====
      DB::table('banners')->insert([
        [
          'image_url' => 'https://placehold.co/1200x400?text=SOULCHILL',
          'link_type' => 'product',
          'link_value' => (string) $soulchillId,
          'is_active' => true,
          'sort_order' => 1,
          'currency' => null,
          'created_at' => now(),
          'updated_at' => now(),
        ],
      ]);
    });
  }
}
