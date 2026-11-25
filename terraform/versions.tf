terraform {
  # 1.10 for S3 state locking without a DynamoDB table (use_lockfile).
  required_version = ">= 1.10"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.74"
    }
  }

  # The deploy bucket is private and already holds the deploy bundle, which
  # contains production settings; the state is no more sensitive than that.
  backend "s3" {
    bucket       = "deploy.richmondsunlight.com"
    key          = "terraform/rs-video-processor.tfstate"
    region       = "us-east-1"
    encrypt      = true
    use_lockfile = true
  }
}

provider "aws" {
  region = var.region

  default_tags {
    tags = {
      Project   = "rs-video-processor"
      ManagedBy = "terraform"
    }
  }
}

data "aws_caller_identity" "current" {}

locals {
  account_id = data.aws_caller_identity.current.account_id
}
