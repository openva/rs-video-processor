#!/usr/bin/env bash
set -euo pipefail

# Run by Packer after a build (video-processor.pkr.hcl), on the Mac.
#
# 1. Points the launch template at the new image, by writing its ID to the SSM
#    parameter the template reads at each launch (terraform/image.tf).
# 2. Deletes all but the newest KEEP images, and their snapshots: a stored
#    image costs about $0.05 a GB a month.
#
# Usage: publish.sh <manifest.json> <region> <parameter> <keep>

MANIFEST="$1"
REGION="$2"
PARAMETER="$3"
KEEP="$4"

# The manifest's last build: "us-east-1:ami-0123456789abcdef0".
IMAGE=$(jq -r '.builds[-1].artifact_id' "$MANIFEST" | cut -d: -f2)
if [[ ! "$IMAGE" =~ ^ami-[0-9a-f]+$ ]]; then
  echo "No image ID in ${MANIFEST}; nothing published." >&2
  exit 1
fi

aws ssm put-parameter --region "$REGION" --name "$PARAMETER" \
  --type String --data-type aws:ec2:image --value "$IMAGE" --overwrite > /dev/null
echo "Launch template now starts instances from ${IMAGE} (${PARAMETER})."

# Newest first.
IMAGES=$(aws ec2 describe-images --region "$REGION" --owners self \
  --filters Name=tag:Role,Values=video-processor-image \
  --query 'reverse(sort_by(Images, &CreationDate))[].ImageId' --output text | tr '\t' '\n')

count=0
for old in $IMAGES; do
  count=$((count + 1))
  if [[ $count -le $KEEP || "$old" == "$IMAGE" ]]; then
    continue
  fi
  snapshots=$(aws ec2 describe-images --region "$REGION" --image-ids "$old" \
    --query 'Images[0].BlockDeviceMappings[].Ebs.SnapshotId' --output text)
  aws ec2 deregister-image --region "$REGION" --image-id "$old"
  for snapshot in $snapshots; do
    [[ "$snapshot" == "None" ]] && continue
    aws ec2 delete-snapshot --region "$REGION" --snapshot-id "$snapshot"
  done
  echo "Deleted the older image ${old} and its snapshot(s) ${snapshots}."
done
