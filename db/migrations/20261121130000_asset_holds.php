<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Holds on assets, with a rule for each stage of an asset's life on a project - adding it to a project (assign) and
 * sending it out (dispatch) - of ALLOW, WARN or BLOCK. A hold can come from a maintenance job or be put on an asset
 * directly. A status can have an effect, which is how the system knows which of a project's statuses means gear has
 * left the warehouse.
 *
 * Maintenance jobs keep their existing flag and block settings for the assign stage (a flag warns, a block blocks), so
 * nothing changes for jobs that already exist: the new dispatch rule defaults to ALLOW, and holds can be signed off.
 */
final class AssetHolds extends AbstractMigration
{
    public function change(): void
    {
        $this->table('assetsAssignmentsStatus')
            ->addColumn('assetsAssignmentsStatus_effect', 'string', ['limit' => 32, 'null' => true, 'default' => null])
            ->update();

        $this->table('maintenanceJobs')
            ->addColumn('maintenanceJobs_dispatchMode', 'string', ['limit' => 8, 'null' => false, 'default' => 'ALLOW'])
            ->addColumn('maintenanceJobs_dispatchOverridable', 'boolean', ['null' => false, 'default' => true])
            ->update();

        $this->table('assetHolds', ['id' => 'assetHolds_id'])
            ->addColumn('instances_id', 'integer', ['null' => false])
            ->addColumn('assets_id', 'integer', ['null' => false])
            ->addColumn('assetHolds_reason', 'text', ['null' => true])
            ->addColumn('assetHolds_assignMode', 'string', ['limit' => 8, 'null' => false, 'default' => 'ALLOW'])
            ->addColumn('assetHolds_dispatchMode', 'string', ['limit' => 8, 'null' => false, 'default' => 'BLOCK'])
            ->addColumn('assetHolds_overridable', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('users_userid', 'integer', ['null' => true, 'default' => null])
            ->addColumn('assetHolds_created', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('assetHolds_deleted', 'boolean', ['null' => false, 'default' => false])
            ->addIndex(['assets_id', 'assetHolds_deleted'])
            ->addIndex(['instances_id'])
            ->create();
    }
}
