<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Assignment rates: an asset on a project is priced at the day and week rates that applied when it was added,
 * rather than at whatever the asset or its type costs today, so repricing an asset doesn't change quotes that
 * have already gone out. Existing assignments are given the rates in effect now, so no project's totals move.
 * A NULL rate falls back to the asset's current rate.
 */
final class AssignmentRates extends AbstractMigration
{
    public function change(): void
    {
        $this->table('assetsAssignments')
            ->addColumn('assetsAssignments_dayRate', 'biginteger', ['null' => true, 'default' => null, 'after' => 'assetsAssignments_customPrice'])
            ->addColumn('assetsAssignments_weekRate', 'biginteger', ['null' => true, 'default' => null, 'after' => 'assetsAssignments_dayRate'])
            ->update();

        if ($this->isMigratingUp()) {
            $this->execute("
                UPDATE assetsAssignments
                    JOIN assets ON assetsAssignments.assets_id = assets.assets_id
                    JOIN assetTypes ON assets.assetTypes_id = assetTypes.assetTypes_id
                SET assetsAssignments.assetsAssignments_dayRate = COALESCE(assets.assets_dayRate, assetTypes.assetTypes_dayRate),
                    assetsAssignments.assetsAssignments_weekRate = COALESCE(assets.assets_weekRate, assetTypes.assetTypes_weekRate)
            ");
        }
    }
}
