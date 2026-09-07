<?php

declare(strict_types=1);

namespace OCA\IdRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The account is now created only at the very end, so a registration in progress has no user id
 * yet: several rows share the empty one and the index can no longer be unique.
 */
final class Version001002Date20260907200000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('idregister_pending')) {
            return null;
        }
        $table = $schema->getTable('idregister_pending');

        if ($table->hasIndex('idreg_uid')) {
            $table->dropIndex('idreg_uid');
        }
        if (!$table->hasIndex('idreg_uid_idx')) {
            $table->addIndex(['uid'], 'idreg_uid_idx');
        }

        return $schema;
    }
}
