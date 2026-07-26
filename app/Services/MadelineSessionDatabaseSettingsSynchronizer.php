<?php

namespace App\Services;

use danog\AsyncOrm\DbArrayBuilder;
use danog\MadelineProto\SessionPaths;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\DriverDatabaseAbstract;

class MadelineSessionDatabaseSettingsSynchronizer
{
    public function synchronize(string $sessionDirectory, Settings $settings): bool
    {
        $sessionPaths = new SessionPaths($sessionDirectory);
        $sessionFile = $sessionPaths->getSessionPath();
        $storedSession = $sessionPaths->unserialize();

        if (! $storedSession instanceof DbArrayBuilder) {
            return false;
        }

        $database = $settings->getDb();

        if (! $database instanceof DriverDatabaseAbstract) {
            return false;
        }

        $databaseSettings = $database->getOrmSettings();

        if ($storedSession->settings == $databaseSettings) {
            return false;
        }

        $sessionPaths->serialize(
            new DbArrayBuilder(
                $storedSession->table,
                $databaseSettings,
                $storedSession->keyType,
                $storedSession->valueType,
            ),
            $sessionFile,
        );

        return true;
    }
}
