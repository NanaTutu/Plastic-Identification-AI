<?php

namespace Config;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\MigrationRunner;

/**
 * Services Configuration file.
 *
 * Service overrides for the application. The only override is `migrations`:
 * it runs `php spark migrate` with the dedicated `migrations` DB group
 * (plasticid_migrate, DDL privileges) instead of the runtime default group
 * (plasticid_ci, least-privilege). The migration bookkeeping table and every
 * schema change are therefore created as the migrate user, and the web
 * runtime never needs DDL rights.
 */
class Services extends BaseService
{
    public static function migrations(?Migrations $config = null, ?ConnectionInterface $db = null, bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('migrations', $config, $db);
        }

        $config ??= config(Migrations::class);
        $db ??= db_connect(ENVIRONMENT === 'testing' ? 'tests' : 'migrations');

        return new MigrationRunner($config, $db);
    }
}
