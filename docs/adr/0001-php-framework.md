# ADR 0001 — PHP Framework: Laravel API

- Status: accepted (2026-10-08)
- Decided: **Laravel 12/13 (API-only)** + Sanctum + Horizon/queues + Flysystem v3
- Context: need mature S3/SFTP adapters, hiring pool, OSS contributor familiarity vs API Platform strictness.
- Consequence: `apps/api` scaffolds via `composer create-project laravel/laravel`, stripped to API-only.
