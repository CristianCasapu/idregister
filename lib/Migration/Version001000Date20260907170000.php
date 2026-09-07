<?php

declare(strict_types=1);

namespace OCA\IdRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version001000Date20260907170000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('idregister_pending')) {
            $table = $schema->createTable('idregister_pending');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('surname', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $table->addColumn('given_names', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('phone', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
            // salted hash only: the personal number itself is never stored
            $table->addColumn('cnp_hash', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => '']);
            $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'pending']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uid'], 'idreg_uid');
            $table->addUniqueIndex(['token'], 'idreg_token');
            $table->addIndex(['email'], 'idreg_email');
            $table->addIndex(['cnp_hash'], 'idreg_cnp');
            $table->addIndex(['status', 'expires_at'], 'idreg_status');
        }

        // the values that may not change afterwards, per user
        if (!$schema->hasTable('idregister_locked')) {
            $table = $schema->createTable('idregister_locked');
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('display_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('phone', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['uid']);
        }

        return $schema;
    }
}
