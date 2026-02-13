# State Machine — TweetPin Store (Backend)

هذه الوثيقة هي “العقد” الذي يُبنى عليه:
- شحن المحفظة (Auto/Manual)
- الدفع على الطلب (Gateway/Manual/Wallet)
- التسليم (API / Manual / Digital Pin Email)
- الاسترجاع/الرفض/الفشل بشكل آمن بدون تكرار أو خصم مزدوج

---

## 1) مبادئ أساسية (Invariants)

### 1.1 العملة Currency
- كل مستخدم لديه عملة واحدة ثابتة `user.currency` بعد أول اختيار.
- كل العمليات المالية للمستخدم (TopUp / Orders / Wallet) يجب أن تكون **بنفس العملة**.
- أي طلب/شحن بعملة مختلفة → **مرفوض** (422).

### 1.2 المبالغ Amounts
- جميع المبالغ تُخزن داخلياً كـ **Minor Units** (مثلاً cents) `*_amount_minor` لتجنب مشاكل float.
- عند العرض في API يتم تحويلها إلى شكل مناسب للواجهة.

### 1.3 مصدر الحقيقة للأموال
- لا يوجد تعديل مباشر على الرصيد.
- الرصيد = ناتج Ledger (wallet_transactions) أو حقول balance_minor يتم تعديلها فقط عبر معاملات مؤتمتة ومحكومة (DB transaction + row lock).

### 1.4 Idempotency (منع التكرار)
- كل عملية مالية يجب أن تكون Idempotent:
  - إنشاء Order: `order_uuid` (UUIDv4 من العميل) أو Server-generated مع Idempotency-Key.
  - إنشاء TopUp: `topup_uuid`.
  - استدعاء مزود التسليم (API Fulfillment): `provider_order_uuid`.
  - Webhook Events: `provider_event_id` (مثل Stripe event id).
- أي إعادة إرسال لنفس العملية بنفس UUID → لا تنشئ سجل جديد، تعيد نفس النتيجة.

### 1.5 Queues & Side Effects
- إرسال الإيميلات، تنفيذ التسليم، معالجة webhooks، polling لمزود التسليم… كلها عبر Jobs في Queue.
- لا تُنفّذ Side Effects داخل Request HTTP إلا للضرورة.

---

## 2) الكيانات (Entities) وحالاتها

### 2.1 Order
#### الحقول المنطقية (مقترحة/مرجعية)
- `orders.status`
- `orders.payment_status`
- `orders.payment_provider` (gateway/manual/wallet)
- `orders.currency`
- `orders.total_amount_minor`
- `orders.order_uuid` (idempotency)

#### Order.status (حالة الطلب)
القيم المعتمدة:
- `pending_payment`  : تم إنشاء الطلب بانتظار الدفع
- `paid`             : مدفوع وجاهز للبدء بالتسليم
- `awaiting_manual_approval` : مدفوع لكن يحتاج إجراء/موافقة أدمن (منتج manual)
- `delivering`       : جارٍ التنفيذ/التسليم (API أو تجهيز pin)
- `delivered`        : تم التسليم بنجاح
- `failed`           : فشل غير قابل للإكمال حالياً
- `canceled`         : ملغى قبل الدفع/أثناء الدفع
- `refunded`         : تم إرجاع المبلغ (لـ wallet أو وفق سياسة المزود)

#### transitions (مسموح فقط)
- pending_payment → paid | canceled
- paid → delivering | awaiting_manual_approval
- awaiting_manual_approval → delivering | failed
- delivering → delivered | failed
- failed → refunded (إذا قرار السياسة = refund)
- paid → refunded (في حالات استثنائية: قرار إداري)

> ملاحظة: لا يُسمح بالعودة للخلف (delivered → delivering) نهائياً.

---

### 2.2 Payment (على الطلب) — orders.payment_status
القيم المعتمدة:
- `unpaid`           : لم يبدأ الدفع (مناسب للـ manual)
- `requires_action`  : يحتاج إجراء من المستخدم (PaymentSheet/3DS)
- `pending`          : بانتظار تأكيد خارجي/مراجعة
- `paid`             : مؤكد
- `failed`           : فشل
- `canceled`         : أُلغي من المستخدم
- `refunded`         : تم ردّ المبلغ

#### قواعد ربط Order.status مع Payment.status
- إذا payment_status = paid → order.status لا يمكن أن يبقى pending_payment
- إذا order.status = delivered → payment_status يجب أن يكون paid (أو refunded مع توضيح خاص غير مسموح افتراضياً)

---

### 2.3 Delivery — deliveries.status
القيم المعتمدة:
- `not_started`      : لم يبدأ التسليم بعد (قبل الدفع)
- `pending`          : جاهز للبدء (بعد الدفع)
- `processing`       : جارٍ التنفيذ
- `waiting_provider` : بانتظار مزود API (polling)
- `waiting_admin`    : يحتاج إجراء أدمن (manual)
- `delivered`        : تم التسليم
- `failed`           : فشل
- `canceled`         : ألغي

#### transitions
- not_started → pending
- pending → processing | waiting_admin
- processing → waiting_provider | delivered | failed
- waiting_provider → delivered | failed | processing
- waiting_admin → delivered | failed
- أي حالة → canceled (إذا تم إلغاء الطلب ضمن سياسة واضحة)

---

### 2.4 Wallet TopUp — wallet_topups.status (للمرحلة القادمة)
القيم المعتمدة:
- `initiated`        : تم إنشاء طلب شحن
- `requires_action`  : يحتاج إجراء عبر بوابة (3DS/confirm)
- `pending_review`   : شحن يدوي بانتظار موافقة أدمن
- `approved`         : تم اعتماده
- `rejected`         : تم رفضه
- `failed`           : فشل تقني
- `posted`           : تم إضافة الرصيد للـ wallet (Ledger posted)

#### transitions
- initiated → requires_action | pending_review
- requires_action → approved | failed | canceled
- pending_review → approved | rejected
- approved → posted

> قاعدة: لا يعتبر الشحن "منتهياً" إلا عند posted.

---

### 2.5 Wallet Transaction — wallet_transactions.status
القيم المعتمدة:
- `pending`   : محجوز/بانتظار اكتمال العملية
- `posted`    : مثبت في الرصيد
- `reversed`  : تم عكسه (refund/reversal)

#### قواعد مهمة
- أي خصم لشراء من المحفظة:
  - إما `posted` فوراً داخل DB transaction عند تأكيد الطلب
  - أو `pending` ثم `posted` (إذا اعتمدت نظام الحجز)
- لا يُسمح بتعديل سجل posted إلا بإنشاء reversal.

---

## 3) تدفقات العمل (Flows)

### 3.1 شراء منتج عبر Wallet
1) Create Order (idempotent بـ order_uuid)
2) تحقق رصيد wallet مع Row Lock
3) إنشاء WalletTransaction (debit) + تخفيض balance_minor داخل نفس DB transaction
4) orders.payment_status = paid
5) orders.status = paid
6) deliveries.status = pending
7) Dispatch Fulfillment Job

---

### 3.2 شراء منتج عبر Manual Payment (حوالة/محفظة خارجية)
1) Create Order
2) orders.payment_status = unpaid أو pending
3) orders.status = pending_payment
4) user يرفع إيصال (اختياري)
5) Admin action: Mark Paid
6) عند mark-paid:
   - orders.payment_status = paid
   - orders.status = paid
   - deliveries.status = pending
   - Dispatch Fulfillment Job

---

### 3.3 شحن المحفظة عبر Manual
1) Create TopUp
2) topup.status = pending_review
3) Admin approves:
   - topup.status = approved
4) Posting:
   - create wallet_transaction (credit) posted
   - increment wallet.balance_minor
   - topup.status = posted

---

### 3.4 تسليم API (مزود خارجي)
حالاتنا الداخلية مرتبطة بنتيجة المزود:

**البدء**
- delivery: pending → processing
- إنشاء fulfillment_request يحمل:
  - provider_name
  - provider_order_uuid (UUIDv4)
  - request payload
  - status = sent

**ردود المزود (مثال tweet-pin):**
- status = accept → delivery.delivered + order.delivered
- status = wait   → delivery.waiting_provider (وتفعيل polling job)
- status = reject → delivery.failed + order.failed (ثم تقييم refund)

---

### 3.5 تسليم Manual (أدمن)
- بعد الدفع:
  - order.status = awaiting_manual_approval
  - delivery.status = waiting_admin
- Admin approves/executes:
  - delivery.status = delivered
  - order.status = delivered
- Admin rejects:
  - delivery.status = failed
  - order.status = failed (ثم تقييم refund)

---

### 3.6 Digital Pin Email
- بعد الدفع:
  - delivery.status = processing
- حجز pin من المخزون (reserve)
- إرسال Email عبر Job:
  - نجاح: delivery.delivered + order.delivered
  - فشل إرسال: delivery.failed (أو retry) + order.failed حسب السياسة

---

## 4) سياسة الفشل والاسترجاع (Failure & Refund Policy)

### 4.1 متى نعمل Refund؟
قرار افتراضي مقترح:
- إذا فشل التسليم بعد الدفع بسبب:
  - reject من المزود
  - أو خطأ تقني دائم
  → **نعمل refund تلقائي فقط إذا الدفع كان Wallet**
- إذا الدفع كان Gateway: refund عبر Stripe/بوابة (قرار لاحق)
- إذا الدفع Manual: قرار إداري

### 4.2 منع الخصم المزدوج
- أي عملية wallet debit/credit يجب أن تكون محكومة بـ unique reference:
  - (reference_type, reference_id, direction, type)

---

## 5) Mapping خاص بمزود tweet-pin (مرجعي)

### 5.1 Authentication
- Header: `api-token: YOUR_API_TOKEN`

### 5.2 Order idempotency
- يجب إرسال `order_uuid` لكل طلب للمزود.
- إعادة نفس `order_uuid` يجب أن تعيد نفس order (لا تكرر).

### 5.3 Mapping حالات المزود
- provider `accept` → internal: delivered
- provider `wait`   → internal: waiting_provider (poll)
- provider `reject` → internal: failed (with reason_code)

### 5.4 Error codes mapping (اختياري)
- 100 insufficient balance → failed + reason = provider_insufficient_balance
- 105/106/112/113 qty issues → failed + reason = invalid_quantity
- 109/110 product issues → failed + reason = product_unavailable
- 111 try again → waiting_provider + retry policy

---

## 6) قائمة تطبيق (Implementation Checklist)

### 6.1 كود
- إنشاء Enums في Laravel:
  - OrderStatus, PaymentStatus, DeliveryStatus, TopupStatus, WalletTxStatus
- Validation rules: أي update للحالات يمر عبر Service واحد (StateTransitionService)
- تسجيل Audit Log لكل انتقال حالة حساس (paid/approved/rejected/refunded)

### 6.2 قاعدة البيانات
- إضافة unique indexes:
  - orders.order_uuid
  - wallet_topups.topup_uuid
  - fulfillment_requests.provider_order_uuid
  - webhooks.provider_event_id (أو جدول events)
- (اختياري) CHECK constraints في PostgreSQL لضمان القيم

### 6.3 التشغيل
- Queue Worker لازم يعمل دائماً
- Mailer production = smtp (ولا تستخدم log في الإنتاج)

---
