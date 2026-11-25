# The pipeline's output, sent by the CloudWatch agent. The journal goes with
# each instance, and it was where a failed run was looked into afterwards.
resource "aws_cloudwatch_log_group" "pipeline" {
  name              = "/rs-video-processor/pipeline"
  retention_in_days = 30
}
