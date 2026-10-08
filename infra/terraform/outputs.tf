output "r2_bucket" {
  description = "L1 bucket name → S3_L1_BUCKET"
  value       = cloudflare_r2_bucket.l1.name
}

output "app_url" {
  description = "Public base → APP_URL / NEXT_PUBLIC_API_BASE"
  value       = "https://${var.domain}"
}
