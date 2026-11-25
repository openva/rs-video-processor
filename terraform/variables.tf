variable "region" {
  type    = string
  default = "us-east-1"
}

# The network the hand-built instance (i-05a12457e82c9aed5) uses. Read, not
# managed: they are shared with RS Web and RS Machine.
variable "subnet_id" {
  description = "us-east-1e, which gives instances a public address."
  type        = string
  default     = "subnet-15e8113e"
}

variable "security_group_id" {
  description = "ssh-only: SSH in from anywhere, everything out."
  type        = string
  default     = "sg-0760707be1438a73a"
}

variable "key_name" {
  type    = string
  default = "waldo"
}

variable "default_instance_type" {
  description = "rs-machine names the type at each launch (VideoProcessorSize); this is only the template's default."
  type        = string
  default     = "c7a.xlarge"
}

variable "root_volume_gb" {
  description = "Today's size. Lower it once the CloudWatch agent's disk_used_percent shows the real need."
  type        = number
  default     = 125
}

variable "deploy_bucket" {
  type    = string
  default = "deploy.richmondsunlight.com"
}

variable "deploy_bundle_key" {
  description = "Written by .github/workflows/deploy.yml, read at boot by deploy/update-from-s3.sh."
  type        = string
  default     = "rs-video-processor-master.zip"
}

variable "youtube_cookies_key" {
  description = "yt-dlp's cookies, fetched at boot (they used to be copied onto the instance by hand)."
  type        = string
  default     = "rs-video-processor/youtube-cookies.txt"
}

variable "image_parameter" {
  description = "SSM parameter holding the current image ID. Packer writes it; the launch template reads it at every launch."
  type        = string
  default     = "/rs-video-processor/image-id"
}

variable "rs_machine_user" {
  description = "The IAM user whose keys rs-machine uses to launch the processor."
  type        = string
  default     = "Travis"
}

variable "github_repository" {
  type    = string
  default = "openva/rs-video-processor"
}
