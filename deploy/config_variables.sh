#!/bin/bash
#==================================================================================
# Uses environment variables within GitHub Actions to populate
# includes/settings.inc.php prior to deployment. This allows secrets (e.g., API keys)
# to be stored in GitHub Actions, while the settings file is stored on GitHub.
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

# Now perform the replacement. This is done in PHP rather than sed so that each value
# is written as a correctly escaped PHP string literal (var_export) -- a secret that
# contains |, &, ', or \ would otherwise corrupt the settings file.
cp includes/settings-default.inc.php includes/settings.inc.php
php -- "${variables[@]}" <<'PHP'
<?php
$file = 'includes/settings.inc.php';
$settings = file_get_contents($file);
foreach (array_slice($argv, 1) as $name) {
    $placeholder = "define('$name', '')";
    $settings = str_replace($placeholder, "define('$name', " . var_export(getenv($name), true) . ')', $settings, $count);
    if ($count === 0) {
        fwrite(STDERR, "No placeholder for $name in $file -- aborting\n");
        exit(1);
    }
}
file_put_contents($file, $settings);
PHP
