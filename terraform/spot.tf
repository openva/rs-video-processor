# EC2 needs this role to run Spot instances. The account had never used Spot,
# so it didn't exist, and without it rs-machine's first Spot launch would need
# permission to create IAM roles.
resource "aws_iam_service_linked_role" "spot" {
  aws_service_name = "spot.amazonaws.com"
}
