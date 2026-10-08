variable "cloudflare_account_id" {
  description = "Cloudflare account ID owning the R2 bucket and zone"
  type        = string
}

variable "domain" {
  description = "Public domain for the platform"
  type        = string
  default     = "private.wf"
}

variable "environment" {
  description = "beta | prod (bucket names are suffixed)"
  type        = string
  default     = "beta"
}

variable "r2_location" {
  description = "R2 bucket location hint (EU for GDPR proximity)"
  type        = string
  default     = "EU"
}

variable "api_origin_ip" {
  description = "Public IPv4 of the nginx host (DNS A record target)"
  type        = string
}

variable "proxied" {
  description = "Orange-cloud the app records (hides origin IP)"
  type        = bool
  default     = true
}
