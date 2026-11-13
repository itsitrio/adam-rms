<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Lets a project's asset lines be left off its quotes and invoices - things like cases and accessories that come
 * with other equipment, which matter for picking and checking but not to the client. They still show on delivery
 * notes and everywhere else in the project.
 *
 * A line can also be left out of the project's totals, so it isn't charged for. Its mass and value still count.
 */
final class AssignmentHiddenLines extends AbstractMigration
{
    public function change(): void
    {
        $this->table('assetsAssignments')
            ->addColumn('assetsAssignments_hidden', 'boolean', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_TINY,
            ])
            ->addColumn('assetsAssignments_excludedFromTotals', 'boolean', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_TINY,
            ])
            ->update();
    }
}
