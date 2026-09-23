<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Unserialized assets - assets which are held as a quantity of interchangeable units
 * (gel frames, cable ties, XLR leads bought by the box) rather than as one row per
 * physical item.
 *
 * Every existing asset is a serialized asset holding a single unit, and every existing
 * assignment takes one unit of it, so the defaults here leave current behaviour untouched.
 */
final class UnserializedAssets extends AbstractMigration
{
    public function change(): void
    {
        $this->table('assets')
            ->addColumn('assets_unserialized', 'boolean', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_TINY,
                'after' => 'assets_showPublic',
            ])
            ->addColumn('assets_quantity', 'integer', [
                'null' => false,
                'default' => '1',
                'limit' => MysqlAdapter::INT_REGULAR,
                'after' => 'assets_unserialized',
            ])
            ->update();

        $this->table('assetsAssignments')
            ->addColumn('assetsAssignments_quantity', 'integer', [
                'null' => false,
                'default' => '1',
                'limit' => MysqlAdapter::INT_REGULAR,
                'after' => 'assetsAssignments_linkedTo',
            ])
            ->update();
    }
}
