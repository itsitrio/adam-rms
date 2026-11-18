<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tracking whether won work has been paid for. A business sets how many days after a project ends its payment is
 * due; a project can have its own due date instead, and can be marked as in collections or written off.
 */
final class ProjectPaymentCollection extends AbstractMigration
{
    public function change(): void
    {
        $this->table('instances')
            ->addColumn('instances_config_paymentDueDays', 'integer', ['null' => false, 'default' => 30, 'signed' => false])
            ->update();
        $this->table('projects')
            ->addColumn('projects_paymentDueDate', 'date', ['null' => true, 'default' => null])
            ->addColumn('projects_collectionStatus', 'string', ['limit' => 16, 'null' => true, 'default' => null])
            ->update();
    }
}
