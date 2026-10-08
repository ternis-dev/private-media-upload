# Terraform — private.wf beta infra (M4)

Provisions **L1 R2 bucket + DNS + strict TLS**. Expiry stays in-app
(`pwf:purge`), so no bucket lifecycle rules. L2 (Hetzner Storage Box) and
L3 (own server) are contracted/owned hardware — see `docs/runbook.md`.

## Apply (needs `CLOUDFLARE_API_TOKEN` with Zone+ R2 edit)
```bash
cd infra/terraform
terraform init
terraform plan -var cloudflare_account_id=… -var api_origin_ip=…
terraform apply …
terraform output -json  # → wire S3_L1_* + APP_URL into prod env
```

CI runs `terraform init -backend=false` + `validate` + `fmt -check` only
(no credentials, no state). Remote state (R2/consul) is operator-configured
before first apply and intentionally not committed.
