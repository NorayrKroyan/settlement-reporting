# SettlementV2 (Laravel + React) — no auth

This repo is a **starter** for SettlementMakerV2-style weekly settlements.

- **Backend:** Laravel API (no auth)
- **Frontend:** React (Vite)
- **DB:** Uses your existing `escrow_tracker` schema (import your SQL dump)

> Note: This repo does **not** include `vendor/` or `node_modules/`.
> You will install dependencies locally.

---

## 1) Prereqs

- PHP 8.2+
- Composer
- Node 18+ (or 20+)
- MySQL/MariaDB
- (Recommended) Git

---

## 2) Database

Create DB and import your dump:

```sql
CREATE DATABASE escrow_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then import:

- `database/escrow_tracker.sql`

---

## 3) Backend setup (Laravel)

### Windows (PowerShell)

```powershell
cd scripts
./create-backend.ps1
cd ../backend
copy .env.example .env
# edit .env DB_* values
composer install
php artisan key:generate
php artisan serve
```

### macOS/Linux (bash)

```bash
cd scripts
bash create-backend.sh
cd ../backend
cp .env.example .env
# edit .env DB_* values
composer install
php artisan key:generate
php artisan serve
```

Backend runs on: http://127.0.0.1:8000

Health:
- http://127.0.0.1:8000/api/health

---

## 4) Frontend setup (React)

```bash
cd frontend
npm install
npm run dev
```

Frontend runs on: http://127.0.0.1:5173

The frontend expects backend at:
- `http://127.0.0.1:8000`

You can change it in:
- `frontend/.env`

---

## 5) API endpoints (current MVP)

### Lookups
- `GET /api/lookups/clients`
- `GET /api/lookups/carriers?client_name=...`

> These are derived from distinct `fatloads.client_name` and `fatloads.carrier_name`

### Settlement
- `POST /api/settlements/build`
- `GET /api/settlements/{id}`

Build payload example:

```json
{
  "client_name": "Henry Bros LLC",
  "carrier_name": "K & B Trucking LLC",
  "start_date": "2026-01-01",
  "end_date": "2026-03-31",
  "factor_percent": 2.5
}
```

---

## 6) Important schema notes (kept as-is)

Your schema has `bill_settlements.clientid`/`carrierid` integers, but there is no `clients` table in the dump.
This MVP stores **client/carrier as strings** in `bill_settlements.desc` and leaves `clientid`/`carrierid` NULL.

Later we can normalize by creating/using `clients` + fixing FK types.

---

## 7) Next steps
- Add chargebacks attach/detach
- Add PDF generation
- Add caching/indexes
- Normalize clients/carriers mapping
