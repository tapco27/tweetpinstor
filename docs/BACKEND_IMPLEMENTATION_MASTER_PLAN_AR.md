# الدليل التنفيذي الرئيسي لبناء Backend متجر المنتجات الرقمية (Laravel)

> هذا الملف يوثّق المتطلبات الشاملة المرسلة من صاحب المشروع، ويحوّلها إلى خطة تنفيذ عملية متوافقة مع الكود الحالي في هذا الريبو (Laravel API).
>
> **الحالة الحالية:** المشروع يحتوي Backend Laravel جاهز جزئياً (Auth/Catalog/Orders/Pricing/Providers/Digital Pins)، ويحتاج إكمال مراحل متقدمة للإدارة والتسعير والتقارير والاعتمادية.

---

## 1) الهدف

بناء Backend احترافي متكامل لمتجر منتجات رقمية يدعم:
- كتالوج عام بدون تسجيل دخول.
- تسجيل دخول عادي + Google + Apple.
- اختيار العملة بعد الدخول لأول مرة (TRY/SYP) عبر popup.
- إدارة فئات ومنتجات وباقات وتسعير متعدد المجموعات (Price Groups / Tiers).
- إدارة موردين API + مخزون Digital Pins.
- تنفيذ طلبات مع fallback بين المزودين + refund idempotent.
- Audit Logs + تقارير + واجهات Admin Web وFlutter مبنية على عقود API واضحة.

---

## 2) المبادئ المعتمدة

1. **Source of truth للتسعير = USD** عند كون المنتج USD mode.
2. **العملة الافتراضية = TRY** في الكتالوج العام.
3. **SYP فقط عند اختيار المستخدم** أثناء/بعد التسجيل لأول مرة.
4. **لا يوجد Dealers** في النسخة الحالية (Direct-to-Customer فقط).
5. **كل العمليات الحساسة تُسجّل Audit Log** مع before/after.
6. **idempotency إلزامية** في الدفع/الطلبات/refund.

---

## 3) نطاقات المنتج الأساسية

### A) Catalog & Auth
- Public catalog (categories/products/product details) بدون auth.
- Social login (Google/Apple).
- بعد login إذا currency null → popup إجباري لاختيار TRY/SYP → `POST /me/currency`.

### B) Admin Core
- Categories (مع purchase_mode + required fields).
- Products (fixed_package / flexible_quantity) + providers + display order.
- Digital Pins (bulk add + duplicate handling + stock counts).
- Provider Integrations (templates + credentials + test endpoints).

### C) Pricing Engine
- Price Groups/Tiers ديناميكية.
- Grid ديناميكي لكل SKU (product/package).
- إعادة تسعير جماعية من USD عبر FX.
- batch update للتير/الـ USD fields.
- دقة عالية للـ flexible quantity (10 خانات بعد الفاصلة).

### D) Orders & Reliability
- mark paid / retry / refund.
- slot1/slot2 fallback.
- auto-refund policy بعد فشل نهائي (configurable).
- حالة الطلب والدفع متسقة دائماً.

### E) Observability
- Audit logs موحدة.
- تقارير تشغيلية ومالية.
- Export CSV.

---

## 4) خطة التنفيذ المرحلية (Roadmap)

## Phase 1 — توحيد العقود (API Contracts)
- توحيد response shape لجميع endpoints الإدارية (خصوصاً products/pricing).
- اعتماد Resources/Transformers موحّدة بدل raw model payloads.
- توثيق payloads في `docs/api-contracts`.

**مخرجات:** واجهة Admin Web يمكنها الرسم بدون نداءات إضافية.

---

## Phase 2 — Product Catalog Admin Upgrade

### المطلوب
- `GET /admin/products` يقدّم أعمدة DataTable كاملة:
  - checkbox/select
  - drag handle / display_order
  - product name
  - cost/suggested (USD)
  - eligible providers
  - slot1 / slot2
- دعم:
  - `PUT /admin/products/order`
  - `PUT /admin/products/bulk-provider`
  - `POST /admin/products`
  - `PUT /admin/products/{id}`

### Validation
- fixed_package: لا تفعيل بدون packages صالحة.
- flexible_quantity: لا تفعيل بدون min/max + unit settings.

---

## Phase 3 — Pricing Engine (BE‑12)

### تغييرات DB
- إضافة `product_prices.unit_price_decimal DECIMAL(24,10) NULL`.
- (اختياري) جدول jobs للتتبّع: `pricing_runs`.

### قواعد الأعمال
- إذا `currency_mode = USD`:
  - flexible: المصدر `suggested_unit_usd` أو `cost_unit_usd`.
  - fixed: المصدر `product_packages.suggested_usd` أو `cost_usd`.
- التحويل عبر FX (USD_TRY / USD_SYP).
- TRY minor_unit=2 ، SYP minor_unit=0.
- للـ flexible: الحساب النهائي يعتمد `unit_price_decimal` وليس `unit_price_minor` إذا tiny.

### Endpoints
- `GET /admin/pricing/grid?currency=TRY|SYP`
- `POST /admin/pricing/recalculate-usd`
- `POST /admin/pricing/batch-update-tier`
- `POST /admin/pricing/batch-update-usd`
- `PUT /admin/pricing/row/{type}/{id}`

---

## Phase 4 — Price Groups Operations (BE‑13)

### Features
- إنشاء tier جديد عبر:
  - clone_from_id
  - generate_from: cost|suggested
  - adjustment: amount/percent
  - currency scope
- set default tier.
- delete tier مع سياسات أمان:
  - منع حذف default
  - reassign أو منع الحذف إذا tier مستخدم

---

## Phase 5 — Provider Drivers (BE‑15)

### الهدف
إضافة drivers حقيقية لـ:
- FreeKasa
- BSV
- (مزود ثالث حسب الخطة مثل Elux)

### المطلوب
- Profile/Products/NewOrder/Check.
- Error mapping موحد.
- Idempotency (أو lock داخلي على order_uuid).
- Admin test endpoints لكل مزود.

---

## Phase 6 — Reliability (BE‑16)

### المطلوب
- refund idempotent + منع double credit.
- توحيد status transitions بعد refund/failure.
- عند فشل slot1 نهائياً:
  - retry slot2 تلقائياً إن وجد.
  - وإلا auto-refund (حسب config) أو waiting_admin.

---

## Phase 7 — Audit Logs (BE‑17)

### DB المقترح
- `audit_logs`
  - actor_admin_id
  - action
  - entity_type/entity_id
  - before/after JSON
  - meta (ip, user_agent, request_id)

### Instrumentation إلزامي
- FX update
- pricing recalc/batch
- tier create/delete/default
- product/category/provider updates
- refunds
- digital pins bulk insert

### Endpoint
- `GET /admin/audit-logs` مع filters.

---

## Phase 8 — Reports (BE‑18)

### Dashboard
- orders today/week
- revenue TRY/SYP
- failures by provider
- digital pins stock

### Exports
- orders CSV
- wallet transactions CSV
- pricing snapshot CSV

---

## 5) متطلبات واجهات الإدارة (Admin Web)

- Product Catalog page:
  - DataTable + drag reorder + bulk provider + modal create/edit.
- Provider Integrations page.
- Digital Pins page (bulk add + duplicate report + stock).
- Pricing tiers grid page (dynamic columns + batch actions + SKU modal).
- Orders management page (mark paid, retry, refund, logs).

> ملاحظة: هذه الواجهات ليست موجودة في هذا الريبو حالياً، ويجب تنفيذها في مشروع front-end منفصل أو workspace إضافي.

---

## 6) متطلبات Flutter (تطبيق المستخدم)

- Public catalog بدون تسجيل.
- Auth عبر Google/Apple.
- Popup اختيار العملة عند `needsCurrencySelection=true`.
- Order flow:
  - fixed_package: package select
  - flexible_quantity: qty with min/max validation
- Wallet + topups.
- Orders list/details + delivery results.

---

## 7) اختبارات القبول المطلوبة

1. المنتجات تظهر للعامة بدون login.
2. social login ينشئ user بدون currency ويعيد `needsCurrencySelection=true`.
3. `POST /me/currency` تعمل مرة واحدة فقط.
4. recalculate-usd يعبّي tiers حسب FX بشكل صحيح.
5. flexible_quantity tiny unit لا تنتج total=0.
6. slot2 fallback يعمل عند فشل slot1.
7. refund idempotent (wallet transaction واحد فقط).
8. digital pins تمنع إدخال الكود المكرر وتعيد تفاصيل duplicates.

---

## 8) تعريف “Done” (DoD)

- جميع endpoints متوافقة مع العقود الموثقة.
- تغطية اختبارات Feature/Unit للحالات الحرجة.
- لا توجد تناقضات في status/payment_status.
- Audit logs فعّالة ومقروءة من endpoint مخصص.
- الأداء ضمن الحدود المتفق عليها (قراءة <200ms، كتابة <500ms في المتوسط).
- توثيق نهائي محدث في docs.

---

## 9) ملاحظات تنفيذية مرتبطة بالحالة الحالية للريبو

- الريبو الحالي Laravel، وليس NestJS/Prisma.
- يمكن تحقيق نفس المتطلبات بالكامل ضمن Laravel عبر:
  - Migrations + Eloquent
  - Form Requests / Policies
  - Jobs/Queues
  - Resources
  - Laravel Events/Notifications
- أي أجزاء تعتمد Node-specific stack في الوثيقة الأصلية تُعتبر مرجعاً مفاهيمياً وليست إلزاماً تقنياً لهذا الريبو.

---

## 10) القرار المعتمد للعملات

- العملات المدعومة: `TRY`, `SYP` فقط.
- default العام: `TRY`.
- التحويل إلى `SYP` بناءً على اختيار المستخدم بعد login.
- FX source: `fx_rates` (USD_TRY / USD_SYP).

---

## 11) القرار المعتمد لنموذج العمل

- Direct-to-customer.
- لا يوجد reseller/dealer tiers منفصلة عن price groups العامة إلا إذا تمت إضافتها لاحقاً كميزة مستقلة.

---

## 12) Backlog مختصر (Ready for Implementation)

1. توحيد عقود `/admin/products` + resources.
2. إعادة بناء `/admin/pricing/grid` ديناميكياً.
3. إضافة `PUT /admin/pricing/row/{type}/{id}`.
4. Migration `unit_price_decimal(24,10)` + تعديل PricingService.
5. استكمال `AdminPriceGroupController` (clone/generate/set-default/delete policy).
6. بناء drivers حقيقية FreeKasa/BSV/Elux.
7. توحيد audit schema + endpoint list.
8. تقارير dashboard + CSV exports.

> هذا الملف هو المرجع التنفيذي الأساسي قبل بدء أي patch كبيرة.
