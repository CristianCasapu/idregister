<?php

declare(strict_types=1);

namespace OCA\IdRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Signing in with the phone: the paired phones (their public keys), the short-lived pairing
 * tokens, and the sign-in requests a phone approves.
 */
final class Version001005Date20260910150000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('idregister_device')) {
            $table = $schema->createTable('idregister_device');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('device_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            // the public half of a key pair that never leaves the phone (X.509, base64)
            $table->addColumn('public_key', Types::TEXT, ['notnull' => true]);
            $table->addColumn('algorithm', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ES256']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('last_used', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['id'], 'idreg_device_pk');
            $table->addUniqueIndex(['device_id'], 'idreg_device_did');
            $table->addIndex(['uid'], 'idreg_device_uid');
        }

        if (!$schema->hasTable('idregister_pairing')) {
            $table = $schema->createTable('idregister_pairing');
            $table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('device_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('expires', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['token'], 'idreg_pairing_pk');
            $table->addIndex(['expires'], 'idreg_pairing_exp');
        }

        if (!$schema->hasTable('idregister_authreq')) {
            $table = $schema->createTable('idregister_authreq');
            $table->addColumn('request_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // the browser keeps the secret; only the hash is here, so a stolen table signs nobody in
            $table->addColumn('secret_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('nonce', Types::STRING, ['notnull' => true, 'length' => 64]);
            // the two digits shown on the screen, and the three the phone offers
            $table->addColumn('number', Types::STRING, ['notnull' => true, 'length' => 2, 'default' => '']);
            $table->addColumn('choices', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('device_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'waiting']);
            $table->addColumn('browser', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('ip', Types::STRING, ['notnull' => true, 'length' => 45, 'default' => '']);
            $table->addColumn('redirect', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('expires', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['request_id'], 'idreg_authreq_pk');
            $table->addIndex(['expires'], 'idreg_authreq_exp');
        }

        return $schema;
    }
}
