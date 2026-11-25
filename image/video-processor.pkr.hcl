# The disk image each disposable video processor starts from (see
# docs/superpowers/specs/2026-11-24-disposable-processor-design.md).
#
# Build from the repository's top directory:
#   packer init image/
#   packer build image/
#
# It starts a temporary instance from Canonical's latest Ubuntu 24.04, runs
# deploy/provision.sh and deploy/install-services.sh on it, saves the result
# as an image, and deletes the instance. publish.sh then points the launch
# template at the new image (through an SSM parameter) and deletes old images.
#
# Rebuild when provision.sh or requirements.txt changes, and monthly for
# security updates: instances started from the image don't install them.
# The application itself isn't in the image: each instance downloads the
# latest deploy bundle when it boots.

packer {
  required_version = ">= 1.11"
  required_plugins {
    amazon = {
      source  = "github.com/hashicorp/amazon"
      version = "~> 1.8"
    }
  }
}

variable "region" {
  type    = string
  default = "us-east-1"
}

variable "subnet_id" {
  description = "The processor's subnet (terraform/variables.tf), which gives instances a public address."
  type        = string
  default     = "subnet-15e8113e"
}

variable "image_parameter" {
  description = "SSM parameter the launch template reads its image ID from (terraform/image.tf)."
  type        = string
  default     = "/rs-video-processor/image-id"
}

variable "keep_images" {
  description = "How many images to keep, newest first. Older ones are deleted with their snapshots."
  type        = number
  default     = 3
}

locals {
  app_dir = "/home/ubuntu/video-processor"
  stamp   = formatdate("YYYYMMDD-hhmm", timestamp())
  tags = {
    Name      = "rs-video-processor ${local.stamp}"
    Project   = "rs-video-processor"
    Role      = "video-processor-image"
    ManagedBy = "packer"
  }
}

source "amazon-ebs" "processor" {
  region        = var.region
  instance_type = "c7a.large"
  ssh_username  = "ubuntu"

  subnet_id                   = var.subnet_id
  associate_public_ip_address = true

  source_ami_filter {
    owners      = ["099720109477"] # Canonical
    most_recent = true
    filters = {
      name                = "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"
      virtualization-type = "hvm"
      root-device-type    = "ebs"
    }
  }

  ami_name        = "rs-video-processor-${local.stamp}"
  ami_description = "Richmond Sunlight video processor, built ${local.stamp} from Ubuntu 24.04"
  imds_support    = "v2.0"

  # The image's own disk. The launch template gives each instance a larger
  # one (terraform/variables.tf, root_volume_gb).
  launch_block_device_mappings {
    device_name           = "/dev/sda1"
    volume_size           = 16
    volume_type           = "gp3"
    delete_on_termination = true
  }

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  tags          = local.tags
  snapshot_tags = local.tags
  run_tags = {
    Name    = "Packer: building rs-video-processor image"
    Project = "rs-video-processor"
  }
}

build {
  sources = ["source.amazon-ebs.processor"]

  provisioner "shell" {
    inline = [
      # Until cloud-init finishes, apt may be locked.
      "cloud-init status --wait > /dev/null || true",
      "mkdir -p ${local.app_dir}",
    ]
  }

  # Only what provisioning and the first boot need. update-from-s3.sh replaces
  # all of it with the deploy bundle at boot.
  provisioner "file" {
    source      = "${path.root}/../deploy"
    destination = "${local.app_dir}/"
  }

  provisioner "file" {
    source      = "${path.root}/../requirements.txt"
    destination = "${local.app_dir}/requirements.txt"
  }

  provisioner "shell" {
    inline = [
      "chmod +x ${local.app_dir}/deploy/*.sh",
      "${local.app_dir}/deploy/provision.sh --strict",
      # Marks this as the video processor, for every unit and script.
      "touch /home/ubuntu/video-processor.txt",
      "${local.app_dir}/deploy/install-services.sh",
      # deploy.sh then skips provisioning, and the launch template's boot
      # script doesn't shut the instance down.
      "echo 'built=${local.stamp}' | sudo tee /etc/rs-video-processor-image",
    ]
  }

  post-processors {
    post-processor "manifest" {
      output     = "${path.root}/manifest.json"
      strip_path = true
    }

    post-processor "shell-local" {
      inline = [
        "'${path.root}/publish.sh' '${path.root}/manifest.json' '${var.region}' '${var.image_parameter}' '${var.keep_images}'",
      ]
    }
  }
}
