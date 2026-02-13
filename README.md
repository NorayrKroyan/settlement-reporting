# Settlement Reporting & Inbound Load Processing System

## Overview

This repository contains two integrated modules:

1.  Statement Viewer / Generator
2.  Inbound Load Matching (Queue)

Built with Laravel and Vue 3, this system generates, stores, and exports
settlement reports while managing unmatched inbound loads.

------------------------------------------------------------------------

## Technology Stack

Backend: - PHP 8.2+ - Laravel - DomPDF - MySQL / MariaDB

Frontend: - Vue 3 - Vite - Axios

------------------------------------------------------------------------

## Project Structure

repo/ - backend/ - frontend/ui-vue/ - README.md

------------------------------------------------------------------------

## Statement Viewer / Generator

### Purpose

Creates financial settlement statements for clients and carriers.

### Workflow

1.  Select client, carrier, date range
2.  System calculates totals
3.  Settlement saved as new revision
4.  PDF export available

### Key Rule

Each build creates a new database record (revision model).

------------------------------------------------------------------------

## Inbound Load Matching

### Purpose

Processes and matches unmatched loads before settlement.

### Workflow

1.  Retrieve queue
2.  Match IDs
3.  Process records
4.  Finalize for settlements

------------------------------------------------------------------------

## Database Requirements

Required tables: - fatloads - bill_settlements - bill_settlementloads -
clients - carrier

Optional: - bill_chargebacks - bill_settlementchargebacks

------------------------------------------------------------------------

## API Endpoints

Statement: - GET /api/health - POST /api/settlements/build - GET
/api/settlements/{id} - GET /api/settlements/{id}/pdf - GET
/api/settlements/history

Inbound: - GET /api/inbound-loads/queue - POST
/api/inbound-loads/process

------------------------------------------------------------------------

## Local Setup

Backend:

cd backend composer install php artisan serve

Frontend:

cd frontend/ui-vue npm install npm run dev

------------------------------------------------------------------------

## Production

Backend:

composer install --no-dev php artisan migrate --force

Frontend:

npm run build

------------------------------------------------------------------------

## Security

No built-in authentication. Must be handled by parent system.

------------------------------------------------------------------------

## Performance

Recommended DB indexes on fatloads and bill_chargebacks.

------------------------------------------------------------------------

## Status

Production ready. Requires external authentication.
