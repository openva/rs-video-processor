#!/bin/bash
#==================================================================================
# Uses environment variables within Travis CI to populate includes/settings.inc.php
# prior to deployment. This allows secrets (e.g., API keys) to be stored in Travis,
# while the settings file is stored on GitHub.
#==================================================================================

# Define the list of environmental variables that we need to populate during deployment.
variables=(
	AWS_ACCESS_KEY
	AWS_SECRET_KEY
	PDO_DSN
	PDO_SERVER
	PDO_USERNAME
	PDO_PASSWORD
	MEMCACHED_SERVER
	MYSQL_DATABASE
	SLACK_WEBHOOK
	OPENAI_KEY
)

# Iterate over the variables and make sure that they're all populated.
for i in "${variables[@]}"
do
	if [ -z "${!i}" ]; then
		echo "GitHub Actions has no value set for $i -- aborting"
		exit 1
	fi
done

# Now iterate over again and perform the replacement.
cp includes/settings-default.inc.php includes/settings.inc.php
for i in "${variables[@]}"
do
	sed -i -e "s|define('$i', '')|define('$i', '${!i}')|g" includes/settings.inc.php
done
