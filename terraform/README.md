# Terraform: the video processor's AWS setup

What rs-machine needs to launch a disposable video processor for each run, and what that instance needs once it's running. The design is in `docs/superpowers/specs/2026-11-24-disposable-processor-design.md`.

| File | What it defines |
|---|---|
| `launch_template.tf` | The launch template: image (from an SSM parameter), disk, network, role, tags, and "shut down means terminate" |
| `image.tf` | The SSM parameter holding the image ID, which Packer updates |
| `processor_role.tf` | The instance's role: the CloudWatch agent, and reading the deploy bundle and YouTube cookies |
| `rs_machine.tf` | rs-machine's permission to launch from the template, and nothing else |
| `spot.tf` | The role EC2 needs before the account's first Spot instance |
| `logs.tf` | The log group the pipeline's output goes to, kept 30 days |
| `github.tf` | A read-only role GitHub Actions uses to show `terraform plan` on pull requests |
| `imports.tf` | Brings the role made by hand in phase 1 under Terraform at the first apply |

Not managed here, only read: the subnet, the `ssh-only` security group, the `waldo` key pair, the IAM user rs-machine uses, and the deploy bucket. They're shared with RS Web and RS Machine.

## Running it

From this directory, on the Mac:

```
terraform init
terraform plan
terraform apply
```

The state is kept in the deploy bucket (`terraform/rs-video-processor.tfstate`) and locked with a lock file next to it while an apply runs.

After the first apply:

1. Delete `imports.tf`. Its work is done, and keeping it does nothing.
2. Save the `github_plan_role_arn` output as the repository variable `TERRAFORM_PLAN_ROLE_ARN` (Settings → Secrets and variables → Actions → Variables). Pull requests that touch this directory then show a plan.

## Before the first launch

The launch template reads its image ID from the SSM parameter `/rs-video-processor/image-id` at each launch (`image.tf`). EC2 won't create a launch template whose parameter doesn't exist, so Terraform creates it holding Canonical's plain Ubuntu 24.04 image, and never changes it again: Packer (`image/`) writes each new image's ID there.

Until Packer has built an image, an instance launched from the template has none of the processor's software. The template's boot script shuts down (and so deletes) any instance whose image lacks `/etc/rs-video-processor-image`, after ten minutes. Nothing launches from the template until rs-machine is changed to.
