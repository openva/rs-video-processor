#!/bin/bash

# Skip if a credentials file is already present (don't clobber a
# manually-configured one). The previous guard used `-b` (block-device test)
# with a quoted "~" that never expanded, so it was always false and this always
# ran — harmless, but not what was intended.
if [ -f "$HOME/.aws/credentials" ]; then
	exit 0
fi

mkdir -p "$HOME/.aws"

cat > "$HOME/.aws/credentials" << EOL
[default]
aws_access_key_id = ${AWS_ACCESS_KEY}
aws_secret_access_key = ${AWS_SECRET_KEY}
EOL
