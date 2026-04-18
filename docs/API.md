# Wallos programmatic API (self-hosted)

All endpoints return **JSON** and expect your user **`api_key`**. You can send the key as `api_key` or `apiKey` (same as the other `api/` routes).

Generate or rotate the key in the Wallos UI (user profile). Treat it like a password.

**Base URL:** `https://your-wallos-host/` — call paths such as `https://your-wallos-host/api/income/get_income.php`.

---

## Income (Einnahmenverwaltung)

### List or fetch one record — `api/income/get_income.php`

**Methods:** `GET` or `POST`

| Parameter | Required | Description |
|-----------|----------|-------------|
| `api_key` | yes | Authentication |
| `type` | no | `all` (default), `entry` (one-time only), or `recurring` |
| `household_id` | no | Filter by household member id |
| `start_date` | no | For one-time entries: minimum `income_date` (`YYYY-MM-DD`) |
| `end_date` | no | For one-time entries: maximum `income_date` (`YYYY-MM-DD`) |
| `id` | no | If set with `item_type` / `type`, returns a **single** row instead of lists |
| `item_type` or `type` | when `id` set | `entry` or `recurring` (when using `id`, prefer `item_type` to avoid clashing with list `type`) |

**Response (list mode):** `success`, `title`, `entries`, `recurring`, `notes`. Rows include joined `household_name`, `subscription_name`, `currency_code` where applicable.

**Response (single mode):** `success`, `title`, `item`, `item_type`, `notes`.

---

### Create or update — `api/income/add_income.php`

**Method:** `POST` only. Body may be **JSON** (`Content-Type: application/json`) or form fields.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `api_key` | yes | Authentication |
| `type` | no | `entry` (default) or `recurring` |
| `household_id` | yes | Member id |
| `amount` | yes | Decimal ≥ 0 |
| `currency_id` | yes | Your currency row id |
| `subscription_id` | no | Link to a subscription id or omit |
| `note` | no | Free text |

**One-time (`type=entry`):**

| Parameter | Required | Description |
|-----------|----------|-------------|
| `income_date` | yes | `YYYY-MM-DD` |
| `id` | no | If set, updates that entry |

**Recurring (`type=recurring`):**

| Parameter | Required | Description |
|-----------|----------|-------------|
| `cycle` | yes | `1`–`4` (same meaning as subscriptions) |
| `frequency` | yes | Positive integer |
| `start_date` | yes | `YYYY-MM-DD` |
| `end_date` | no | Optional end |
| `active` | no | `1`/`0`, `true`/`false` (default `1` if omitted) |
| `id` | no | If set, updates that recurring row |

**Response:** `success`, `title`, `message`, `notes`.

---

### Delete — `api/income/delete_income.php`

**Method:** `POST` only. JSON or form body.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `api_key` | yes | Authentication |
| `id` | yes | Record id |
| `type` | no | `entry` (default) or `recurring` |

**Response:** `success`, `title`, `message`, `notes`.

---

## Household ledger (Personenabrechnung)

### Aggregated totals — `api/ledger/get_ledger.php`

**Methods:** `GET` or `POST`

| Parameter | Required | Description |
|-----------|----------|-------------|
| `api_key` | yes | Authentication |
| `scope` | no | `month` (default), `year`, `range`, or `all` |
| `start_date` | if `scope=range` | `YYYY-MM-DD` |
| `end_date` | if `scope=range` | `YYYY-MM-DD` |

**Response:** `success`, `title`, `ledger`, `notes`.

The `ledger` object contains:

- `members`: array of `{ household_id, name, subscription_breakdown, subscriptions_total, income_total, net_difference }` (amounts in **main currency**)
- `main_currency_id`, `main_currency_code`, `main_currency_symbol`
- `grand_subscriptions_total`, `grand_income_total`, `grand_net_difference`
- `scope`, `range_start`, `range_end`

Logic matches the web ledger (`includes/ledger_calculations.php` → `buildLedgerData`).

---

## Other `api/` endpoints

Existing routes (subscriptions, categories, currencies, household, settings, notifications, admin, etc.) keep their behaviour. Each file under `api/` begins with a comment block describing parameters and an example JSON response—open the matching `.php` file for full detail.

---

## Web UI vs API

Session-authenticated JSON used by the browser lives under **`endpoints/`** (CSRF on mutating routes). The **`api/`** tree is for **API-key** access, suitable for scripts and integrations.
