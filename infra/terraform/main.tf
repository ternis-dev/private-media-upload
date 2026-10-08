# private.wf beta infrastructure (M4).
# Applies R2 (L1 edge storage) + DNS. Expiry/lifecycle stays in-app
# (purge worker), so buckets need no lifecycle rules here.

data "cloudflare_zone" "app" {
  filter = {
    name    = var.domain
    account = { id = var.cloudflare_account_id }
  }
}

# L1 edge object storage (S3-compatible endpoint per bucket).
resource "cloudflare_r2_bucket" "l1" {
  account_id = var.cloudflare_account_id
  name       = "privatewf-l1-${var.environment}"
  location   = var.r2_location
}

# App entrypoints. api.* serves the Laravel API (nginx), apex serves web.
resource "cloudflare_dns_record" "apex" {
  zone_id = data.cloudflare_zone.app.zone_id
  name    = var.domain
  type    = "A"
  content = var.api_origin_ip
  ttl     = 300
  proxied = var.proxied
}

resource "cloudflare_dns_record" "api" {
  zone_id = data.cloudflare_zone.app.zone_id
  name    = "api.${var.domain}"
  type    = "A"
  content = var.api_origin_ip
  ttl     = 300
  proxied = var.proxied
}

# Strict TLS everywhere; HSTS handled in-app when APP_URL is https.
resource "cloudflare_zone_setting" "ssl" {
  zone_id    = data.cloudflare_zone.app.zone_id
  setting_id = "ssl"
  value      = "strict"
}

resource "cloudflare_zone_setting" "always_https" {
  zone_id    = data.cloudflare_zone.app.zone_id
  setting_id = "always_use_https"
  value      = "on"
}

resource "cloudflare_zone_setting" "min_tls" {
  zone_id    = data.cloudflare_zone.app.zone_id
  setting_id = "min_tls_version"
  value      = "1.2"
}
