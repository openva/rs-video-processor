# What rs-machine launches for each run. Instances delete themselves, disk
# included, when deploy/auto-shutdown.sh shuts them down.
#
# No market options here: rs-machine asks for Spot and, if Spot is refused,
# launches the same template on demand.

data "aws_subnet" "processor" {
  id = var.subnet_id
}

data "aws_security_group" "processor" {
  id = var.security_group_id
}

data "aws_key_pair" "processor" {
  key_name = var.key_name
}

resource "aws_launch_template" "processor" {
  name                   = "rs-video-processor"
  description            = "Disposable video processor: one pipeline run, then terminates."
  update_default_version = true

  # Resolved at each launch, so a new image from Packer (image/) takes effect
  # without an apply.
  image_id      = "resolve:ssm:${aws_ssm_parameter.image_id.name}"
  instance_type = var.default_instance_type
  key_name      = data.aws_key_pair.processor.key_name

  instance_initiated_shutdown_behavior = "terminate"

  # Packer's images mark themselves with /etc/rs-video-processor-image. An
  # instance started from anything else (such as image.tf's placeholder) has
  # no pipeline to run and would never shut down, so it is shut down, and so
  # deleted, after ten minutes, time enough to look at it over SSH.
  user_data = base64encode(<<-EOT
    #!/bin/bash
    if [ ! -f /etc/rs-video-processor-image ]; then
      shutdown -h +10 "Not a video processor image: shutting down."
    fi
  EOT
  )

  iam_instance_profile {
    arn = aws_iam_instance_profile.processor.arn
  }

  network_interfaces {
    device_index                = 0
    subnet_id                   = data.aws_subnet.processor.id
    security_groups             = [data.aws_security_group.processor.id]
    associate_public_ip_address = true
    delete_on_termination       = true
  }

  block_device_mappings {
    # The root device of Canonical's Ubuntu images.
    device_name = "/dev/sda1"
    ebs {
      volume_size           = var.root_volume_gb
      volume_type           = "gp3"
      encrypted             = true
      delete_on_termination = true
    }
  }

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 2
    instance_metadata_tags      = "enabled"
  }

  # Found by these tags, not by instance ID, which changes every run.
  tag_specifications {
    resource_type = "instance"
    tags = {
      Name = "RS Video Processor"
      Role = "video-processor"
    }
  }

  tag_specifications {
    resource_type = "volume"
    tags = {
      Name = "RS Video Processor"
      Role = "video-processor"
    }
  }
}
