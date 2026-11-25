# The ID of the image the launch template starts instances from. Packer
# (image/) replaces the value with each image it builds, so Terraform sets it
# only once and then leaves it alone.
#
# EC2 checks that the parameter exists when the launch template is created, so
# it starts out holding Canonical's current Ubuntu 24.04 image. That image has
# none of the processor's software; the launch template's boot script shuts
# down (so deletes) any instance started from it.

data "aws_ami" "ubuntu" {
  owners      = ["099720109477"] # Canonical
  most_recent = true

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }
}

resource "aws_ssm_parameter" "image_id" {
  name        = var.image_parameter
  description = "Image the video processor launches from. Written by Packer (image/)."
  type        = "String"
  data_type   = "aws:ec2:image"
  value       = data.aws_ami.ubuntu.id

  lifecycle {
    ignore_changes = [value]
  }
}
