<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The tick boxes a user wants to start with when generating each type of document in a business,
 * which go on top of the business's own defaults (instances_config_documents).
 */
final class UserDocumentDefaults extends AbstractMigration
{
    public function change(): void
    {
        $this->table('userInstances')
            ->addColumn('userInstances_documentDefaults', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->update();
    }
}
