output "launch_template_id" {
  description = "For rs-machine's settings (VIDEO_PROCESSOR_LAUNCH_TEMPLATE)."
  value       = aws_launch_template.processor.id
}

output "github_plan_role_arn" {
  description = "Set as the repository variable TERRAFORM_PLAN_ROLE_ARN so pull requests get a plan."
  value       = aws_iam_role.github_plan.arn
}

output "processor_role_arn" {
  value = aws_iam_role.processor.arn
}
