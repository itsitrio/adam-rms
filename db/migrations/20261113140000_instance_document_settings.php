<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-business display settings: the unit masses are shown and entered in (they are always stored in kg),
 * and a JSON blob of options for generated documents (quotes, invoices, delivery notes) - what each
 * is called, page size, page header/footer text and the default tick boxes for each type.
 */
final class InstanceDocumentSettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('instances')
            ->addColumn('instances_config_massUnit', 'string', [
                'null' => false,
                'default' => 'kg',
                'limit' => 2,
            ])
            ->addColumn('instances_config_documents', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->update();
    }
}
