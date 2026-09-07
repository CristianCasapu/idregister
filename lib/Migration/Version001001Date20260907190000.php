<?php

declare(strict_types=1);

namespace OCA\IdRegister\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Which document was used, and whether the selfie needs a human look. */
final class Version001001Date20260907190000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('idregister_pending')) {
            return null;
        }
        $table = $schema->getTable('idregister_pending');

        if (!$table->hasColumn('document_type')) {
            $table->addColumn('document_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'id_card']);
        }
        if (!$table->hasColumn('needs_review')) {
            $table->addColumn('needs_review', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }
        if (!$table->hasColumn('selfie_distance')) {
            $table->addColumn('selfie_distance', Types::FLOAT, ['notnull' => true, 'default' => 0]);
        }

        return $schema;
    }
}
