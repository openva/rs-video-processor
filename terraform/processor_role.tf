# The role each processor instance runs as. It was made by hand in November
# 2026 for the CloudWatch agent, and is imported (imports.tf).
#
# It covers only what the instance needs before the deploy bundle (and so
# the application's own AWS keys in settings.inc.php) has arrived, plus
# metrics and logs.

data "aws_iam_policy_document" "ec2_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "processor" {
  name               = "rs-video-processor"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json
}

resource "aws_iam_instance_profile" "processor" {
  name = "rs-video-processor"
  role = aws_iam_role.processor.name
}

# Metrics and logs from the CloudWatch agent (deploy/cloudwatch-agent.json).
resource "aws_iam_role_policy_attachment" "processor_cloudwatch" {
  role       = aws_iam_role.processor.name
  policy_arn = "arn:aws:iam::aws:policy/CloudWatchAgentServerPolicy"
}

data "aws_iam_policy_document" "processor_boot" {
  statement {
    sid     = "ReadTheDeployBundleAndCookies"
    actions = ["s3:GetObject"]
    resources = [
      "arn:aws:s3:::${var.deploy_bucket}/${var.deploy_bundle_key}",
      "arn:aws:s3:::${var.deploy_bucket}/${var.youtube_cookies_key}",
    ]
  }
}

resource "aws_iam_role_policy" "processor_boot" {
  name   = "boot"
  role   = aws_iam_role.processor.id
  policy = data.aws_iam_policy_document.processor_boot.json
}
