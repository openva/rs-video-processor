# Lets this repository's GitHub Actions run `terraform plan` on pull requests
# (.github/workflows/terraform.yml) without stored AWS keys. Read-only:
# applying stays with Waldo.

resource "aws_iam_openid_connect_provider" "github" {
  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]
}

data "aws_iam_policy_document" "github_assume" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]
    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github.arn]
    }
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }
    # Pull requests and main in this repository only. GitHub gives no token to
    # pull requests from forks.
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:sub"
      values = [
        "repo:${var.github_repository}:pull_request",
        "repo:${var.github_repository}:ref:refs/heads/main",
      ]
    }
  }
}

resource "aws_iam_role" "github_plan" {
  name               = "rs-video-processor-terraform-plan"
  description        = "GitHub Actions: terraform plan for rs-video-processor. Read-only."
  assume_role_policy = data.aws_iam_policy_document.github_assume.json
}

# Enough to refresh everything this configuration manages. Not ReadOnlyAccess,
# which would let a workflow read every S3 object, the deploy bundle and its
# production settings among them.
resource "aws_iam_role_policy_attachment" "github_plan" {
  for_each = toset([
    "arn:aws:iam::aws:policy/IAMReadOnlyAccess",
    "arn:aws:iam::aws:policy/AmazonEC2ReadOnlyAccess",
    "arn:aws:iam::aws:policy/CloudWatchLogsReadOnlyAccess",
  ])
  role       = aws_iam_role.github_plan.name
  policy_arn = each.value
}

data "aws_iam_policy_document" "github_plan_state" {
  statement {
    sid       = "ReadTheState"
    actions   = ["s3:GetObject"]
    resources = ["arn:aws:s3:::${var.deploy_bucket}/terraform/rs-video-processor.tfstate"]
  }
  statement {
    sid       = "ListTheStatePrefix"
    actions   = ["s3:ListBucket"]
    resources = ["arn:aws:s3:::${var.deploy_bucket}"]
    condition {
      test     = "StringLike"
      variable = "s3:prefix"
      values   = ["terraform/*"]
    }
  }

  # The image parameter, which Terraform refreshes. Only this one: others may
  # hold secrets.
  statement {
    sid       = "ReadTheImageParameter"
    actions   = ["ssm:GetParameter", "ssm:GetParameters", "ssm:ListTagsForResource"]
    resources = [aws_ssm_parameter.image_id.arn]
  }

  statement {
    sid       = "DescribeParameters"
    actions   = ["ssm:DescribeParameters"]
    resources = ["*"]
  }
}

resource "aws_iam_role_policy" "github_plan_state" {
  name   = "state"
  role   = aws_iam_role.github_plan.id
  policy = data.aws_iam_policy_document.github_plan_state.json
}
