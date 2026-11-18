<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Published line item names: an asset can have a name to show clients instead of what it really is, for when
 * a business wants to promise something that does the job without saying exactly which piece of kit it is.
 * A project uses them for all of its assets, or only for the lines that are marked to.
 */
final class PublishedLineItemNames extends AbstractMigration
{
    public function change(): void
    {
        $this->table('assets')
            ->addColumn('assets_publishedName', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->update();
        $this->table('projects')
            ->addColumn('projects_publishedNames', 'boolean', ['null' => false, 'default' => false])
            ->update();
        $this->table('assetsAssignments')
            ->addColumn('assetsAssignments_publishedName', 'boolean', ['null' => false, 'default' => false])
            ->update();
    }
}
