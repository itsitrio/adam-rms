<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A project's invoice can be locked in with the date it was issued, the payment terms it was issued on and the date
 * payment is due, so that later changes to the project's dates or the business's terms don't move a due date a
 * client has already been given. All NULL means the due date is still worked out from the project's dates.
 */
final class InvoiceDueDateLock extends AbstractMigration
{
    public function change(): void
    {
        $this->table('projects')
            ->addColumn('projects_invoiceIssueDate', 'date', ['null' => true, 'default' => null])
            ->addColumn('projects_invoiceDueDate', 'date', ['null' => true, 'default' => null, 'after' => 'projects_invoiceIssueDate'])
            ->addColumn('projects_invoiceTermsDays', 'smallinteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'projects_invoiceDueDate'])
            ->update();
    }
}
