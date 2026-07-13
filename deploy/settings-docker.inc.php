<?php

###
# Docker / CI test-environment settings.
#
# This file previously hand-duplicated the entire ~120-line constant list from
# settings-default.inc.php, and the two had drifted (stale session values,
# missing newer constants). To keep them from diverging, define ONLY the values
# that must differ for the containerised test environment, then pull in the
# shared base for everything else.
#
# define() runs before the base's rs_define() (which is "define-if-not-set"),
# so these overrides win while every other constant is inherited from the single
# source of truth. settings-default.inc.php is placed in includes/ by
# docker-setup.sh (copied from the richmondsunlight.com repo) before this file
# is ever loaded as includes/settings.inc.php.
###

# This is the test/dev environment, not production.
define('IS_PRODUCTION', false);

# Database: the "db" service defined in docker-compose.yml.
define('PDO_DSN', 'mysql:host=db;dbname=richmondsunlight');
define('PDO_SERVER', 'db');
define('PDO_USERNAME', 'ricsun');
define('PDO_PASSWORD', 'password');
define('MYSQL_DATABASE', 'richmondsunlight');

# No Memcached container in the test env; keep the host local.
define('MEMCACHED_SERVER', 'localhost');

# No SQS in the test env.
define('VIDEO_SQS_URL', '');

# The shared base configures Memcached-backed PHP sessions for production; the
# test container has no memcached extension, so silence the resulting ini_set
# warning while still inheriting every constant defined during the require.
$rs_prev_error_reporting = error_reporting();
error_reporting($rs_prev_error_reporting & ~E_WARNING);
require __DIR__ . '/settings-default.inc.php';
error_reporting($rs_prev_error_reporting);
