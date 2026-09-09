<?php

declare(strict_types=1);

namespace OCA\IdRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Which Google account signs in as which account here. Keyed on Google's `sub`, the identifier
 * that never changes; the address is only kept to show it in the personal settings.
 */
final class Version001004Date20260910120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('idregister_google')) {
            return null;
        }
        $table = $schema->createTable('idregister_google');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('google_sub', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('google_email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        $table->addColumn('linked_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
        $table->setPrimaryKey(['id'], 'idreg_google_pk');
        // one Google account signs in as one account here, and the other way round
        $table->addUniqueIndex(['google_sub'], 'idreg_google_sub');
        $table->addUniqueIndex(['uid'], 'idreg_google_uid');

        return $schema;
    }
}
