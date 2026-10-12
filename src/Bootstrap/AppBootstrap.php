<?php

namespace RichmondSunlight\VideoProcessor\Bootstrap;

use Log;
use RuntimeException;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class AppBootstrap
{
    public static function boot(): AppContext
    {
        $log = new Log();

        // Create non-persistent connection for video processor
        // This prevents "MySQL server has gone away" errors during long-running
        // video operations (downloads, ffmpeg, OCR) that can exceed RDS wait_timeout.
        // The shared Database class uses persistent connections which is fine for
        // rs-machine and rs-api, but problematic for long video processing tasks.
        $pdo = self::createNonPersistentConnection();
        if (!$pdo) {
            throw new RuntimeException('Unable to connect to database.');
        }

        $dispatcher = JobDispatcher::fromEnvironment($log);

        return new AppContext($log, $pdo, $dispatcher);
    }

    /**
     * Create a fresh non-persistent PDO connection and set it as the global.
     *
     * Long-running scripts (e.g. generate_screenshots.php) should call this
     * between jobs to replace a connection that may have timed out.
     *
     * @return \PDO|false
     */
    public static function createFreshConnection()
    {
        try {
            $options = [
                \PDO::ATTR_PERSISTENT => false,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $pdo = new \PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD, $options);
            $GLOBALS['db_pdo'] = $pdo;

            return $pdo;
        } catch (\PDOException $e) {
            error_log('Video processor database connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * A closure that opens a new connection each time it is called, for work
     * that outlasts the database's idle timeout.
     *
     * @return \Closure(): \PDO
     */
    public static function connectionFactory(): \Closure
    {
        return static function (): \PDO {
            $pdo = self::createFreshConnection();
            if (!$pdo instanceof \PDO) {
                throw new RuntimeException('Unable to connect to database.');
            }
            return $pdo;
        };
    }

    /**
     * @return \PDO|false
     */
    private static function createNonPersistentConnection()
    {
        if (isset($GLOBALS['db_pdo']) && $GLOBALS['db_pdo'] instanceof \PDO) {
            return $GLOBALS['db_pdo'];
        }

        return self::createFreshConnection();
    }
}
