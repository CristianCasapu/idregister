<?php

declare(strict_types=1);

namespace OCA\IdRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The hand-off from a computer to a phone (QR code) used to live in the distributed cache,
 * which on a server without Redis or Memcached is a per-request array — every step of the
 * hand-off was lost at once. It lives in the database now, on every server.
 */
final class Version001003Date20260910000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('idregister_handoff')) {
            return null;
        }
        $table = $schema->createTable('idregister_handoff');
        $table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('data', Types::TEXT, ['notnull' => true]);
        $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
        $table->addColumn('expires', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
        $table->setPrimaryKey(['token'], 'idreg_handoff_pk');
        $table->addIndex(['expires'], 'idreg_handoff_exp');

        return $schema;
    }
}
