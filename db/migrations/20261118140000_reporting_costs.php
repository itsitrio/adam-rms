<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What things cost the business, so the reports can work out profit and not just revenue:
 * - payments_cost: what one unit of a sales item, additional hire or staff line costs the business (the supplier's
 *   price, the crew's pay), as opposed to payments_amount, which is what the client is charged for it
 * - assets_purchaseCost/assets_purchaseDate: what an asset was bought for and when, to depreciate it, and
 *   assets_depreciationMonths to depreciate one asset over a different life to the business's default
 * - maintenanceJobs_cost: what a repair cost
 * - expenses: the business's overheads, which aren't tied to a project (rent, vans, insurance)
 * - instances_config_depreciationMonths and instances_config_financialYearStart: how long assets are depreciated over
 *   by default, and the month the business's financial year starts in
 */
final class ReportingCosts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('payments')
            ->addColumn('payments_cost', 'biginteger', ['null' => true, 'default' => null, 'after' => 'payments_amount'])
            ->update();
        $this->table('assets')
            ->addColumn('assets_purchaseCost', 'biginteger', ['null' => true, 'default' => null])
            ->addColumn('assets_purchaseDate', 'date', ['null' => true, 'default' => null])
            ->addColumn('assets_depreciationMonths', 'integer', ['null' => true, 'default' => null, 'signed' => false])
            ->update();
        $this->table('maintenanceJobs')
            ->addColumn('maintenanceJobs_cost', 'biginteger', ['null' => true, 'default' => null])
            ->update();
        $this->table('instances')
            ->addColumn('instances_config_depreciationMonths', 'integer', ['null' => false, 'default' => 60, 'signed' => false])
            ->addColumn('instances_config_financialYearStart', 'integer', ['null' => false, 'default' => 1, 'signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY])
            ->update();
        $this->table('expenses', ['id' => false, 'primary_key' => ['expenses_id']])
            ->addColumn('expenses_id', 'integer', ['null' => false, 'identity' => true])
            ->addColumn('instances_id', 'integer', ['null' => false])
            ->addColumn('expenses_date', 'date', ['null' => false])
            ->addColumn('expenses_category', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('expenses_description', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('expenses_supplier', 'string', ['limit' => 200, 'null' => true, 'default' => null])
            ->addColumn('expenses_reference', 'string', ['limit' => 200, 'null' => true, 'default' => null])
            ->addColumn('expenses_amount', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('users_userid', 'integer', ['null' => true, 'default' => null])
            ->addColumn('expenses_added', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('expenses_deleted', 'boolean', ['null' => false, 'default' => 0])
            ->addIndex(['instances_id', 'expenses_date'])
            ->addForeignKey('instances_id', 'instances', 'instances_id', ['constraint' => 'expenses_instances_instances_id_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE'])
            ->addForeignKey('users_userid', 'users', 'users_userid', ['constraint' => 'expenses_users_users_userid_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL'])
            ->create();
    }
}
