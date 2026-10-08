# apps/api — PHP backend (planned: Laravel 12 API)

Scaffolding lands in M1. See [.plans/02-architecture.md](../../.plans/02-architecture.md).

Proposed `composer create-project laravel/laravel .` then strip to API-only + Sanctum + Horizon + Flysystem adapters.
Contract test first: `tests/Contract/StorageDriverContractTest.php` vs MinIO + SFTP containers.
