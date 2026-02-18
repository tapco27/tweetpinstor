# Currency Selection Flow (Frontend Contract)

This project now uses a single unified flow:

1. Register/login succeeds **without forcing currency in register payload**.
2. Backend returns `needsCurrencySelection=true` when `user.currency` is still null.
3. Frontend **must open a blocking currency popup** right after login/register/refresh when `needsCurrencySelection=true`.
4. Frontend submits the selected currency to `POST /api/v1/me/currency`.
5. Currency is immutable after first successful selection (second call returns `409`).

## Required Frontend Behavior

- Check `needsCurrencySelection` from auth responses:
  - `POST /v1/auth/register`
  - `POST /v1/auth/login`
  - `POST /v1/auth/refresh`
- If true, prevent wallet/orders flow until the user picks one of `TRY` or `SYP`.
- Call `POST /v1/me/currency` with:

```json
{
  "currency": "TRY"
}
```

- On success, update local user state and close popup.
- On `409`, treat currency as already set and refetch `/v1/me`.

## Notes

- Wallet is created lazily when currency is selected.
- Legacy users cleanup is handled by migration `2026_02_18_030000_cleanup_wallets_for_currency_selection_flow.php`.
