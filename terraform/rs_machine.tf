# Lets rs-machine (cron/start_video_processor.php) launch processors from the
# launch template, and nothing else: no other images, networks or roles.
#
# The user's inline StartVideoProcessor policy, which starts and resizes the
# hand-built instance, is left alone until that instance is retired.

data "aws_iam_user" "rs_machine" {
  user_name = var.rs_machine_user
}

locals {
  ec2_arn = "arn:aws:ec2:${var.region}:${local.account_id}"

  # What a launch creates. EC2 gives a Spot request no ec2:LaunchTemplate to check, so it is
  # allowed on its own (WhatSpotCreates); it can only be made together with an instance, which
  # must come from the template.
  launched_resources = [
    "${local.ec2_arn}:instance/*",
    "${local.ec2_arn}:volume/*",
    "${local.ec2_arn}:network-interface/*",
  ]
  spot_requests = "${local.ec2_arn}:spot-instances-request/*"
}

data "aws_iam_policy_document" "rs_machine_launch" {
  statement {
    sid       = "TheLaunchTemplate"
    actions   = ["ec2:RunInstances"]
    resources = [aws_launch_template.processor.arn]
  }

  statement {
    sid       = "WhatTheTemplateCreates"
    actions   = ["ec2:RunInstances"]
    resources = local.launched_resources
    condition {
      test     = "ArnEquals"
      variable = "ec2:LaunchTemplate"
      values   = [aws_launch_template.processor.arn]
    }
  }

  statement {
    sid       = "WhatSpotCreates"
    actions   = ["ec2:RunInstances"]
    resources = [local.spot_requests]
  }

  statement {
    sid     = "WhatTheTemplateUses"
    actions = ["ec2:RunInstances"]
    resources = [
      data.aws_subnet.processor.arn,
      data.aws_security_group.processor.arn,
      data.aws_key_pair.processor.arn,
    ]
  }

  statement {
    sid       = "OnlyOurOwnImages"
    actions   = ["ec2:RunInstances"]
    resources = ["arn:aws:ec2:${var.region}::image/*"]
    condition {
      test     = "StringEquals"
      variable = "ec2:Owner"
      values   = [local.account_id]
    }
  }

  statement {
    sid       = "ResolveTheImageParameter"
    actions   = ["ssm:GetParameters"]
    resources = [aws_ssm_parameter.image_id.arn]
  }

  statement {
    sid       = "GiveInstancesTheirRole"
    actions   = ["iam:PassRole"]
    resources = [aws_iam_role.processor.arn]
    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["ec2.amazonaws.com"]
    }
  }

  statement {
    sid       = "TagAtLaunch"
    actions   = ["ec2:CreateTags"]
    resources = concat(local.launched_resources, [local.spot_requests])
    condition {
      test     = "StringEquals"
      variable = "ec2:CreateAction"
      values   = ["RunInstances"]
    }
  }

  statement {
    sid       = "FindRunningProcessors"
    actions   = ["ec2:DescribeInstances"]
    resources = ["*"]
  }
}

resource "aws_iam_policy" "rs_machine_launch" {
  name        = "rs-video-processor-launch"
  description = "rs-machine launches video processors from the rs-video-processor launch template."
  policy      = data.aws_iam_policy_document.rs_machine_launch.json
}

resource "aws_iam_user_policy_attachment" "rs_machine_launch" {
  user       = data.aws_iam_user.rs_machine.user_name
  policy_arn = aws_iam_policy.rs_machine_launch.arn
}
