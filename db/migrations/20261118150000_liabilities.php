<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What the business owes: loans, equipment bought on finance or credit and paid off over time, and money lent by a
 * vendor or a member of the business.
 *
 * A liability starts at what was borrowed (liabilities_amount) on its start date. Its transactions then move its
 * balance: a DRAWDOWN borrows more, a CHARGE adds interest or a fee to what's owed, and a REPAYMENT pays it off - the
 * interest part of a repayment (liabilityTransactions_interest) is a cost to the business rather than paying off the
 * debt. Assets bought with the money point at it (assets.liabilities_id).
 *
 * Gives the new permissions to the roles that can see the financial reports (view) and edit overheads (edit).
 */
final class Liabilities extends AbstractMigration
{
    public function up(): void
    {
        $this->table('liabilities', ['id' => false, 'primary_key' => ['liabilities_id']])
            ->addColumn('liabilities_id', 'integer', ['null' => false, 'identity' => true])
            ->addColumn('instances_id', 'integer', ['null' => false])
            ->addColumn('liabilities_name', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('liabilities_type', 'string', ['limit' => 20, 'null' => false, 'default' => 'LOAN'])
            ->addColumn('liabilities_lender', 'string', ['limit' => 200, 'null' => true, 'default' => null])
            ->addColumn('users_userid', 'integer', ['null' => true, 'default' => null, 'comment' => 'The member of the business who lent it, if one did'])
            ->addColumn('liabilities_amount', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('liabilities_startDate', 'date', ['null' => false])
            ->addColumn('liabilities_interestRate', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true, 'default' => null])
            ->addColumn('liabilities_repaymentAmount', 'biginteger', ['null' => true, 'default' => null])
            ->addColumn('liabilities_repaymentFrequency', 'string', ['limit' => 10, 'null' => false, 'default' => 'MONTHLY'])
            ->addColumn('liabilities_firstRepaymentDate', 'date', ['null' => true, 'default' => null])
            ->addColumn('liabilities_endDate', 'date', ['null' => true, 'default' => null])
            ->addColumn('liabilities_reference', 'string', ['limit' => 200, 'null' => true, 'default' => null])
            ->addColumn('liabilities_notes', 'text', ['null' => true, 'default' => null])
            ->addColumn('liabilities_added', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('liabilities_deleted', 'boolean', ['null' => false, 'default' => 0])
            ->addIndex(['instances_id'])
            ->addForeignKey('instances_id', 'instances', 'instances_id', ['constraint' => 'liabilities_instances_instances_id_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE'])
            ->addForeignKey('users_userid', 'users', 'users_userid', ['constraint' => 'liabilities_users_users_userid_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL'])
            ->create();
        $this->table('liabilityTransactions', ['id' => false, 'primary_key' => ['liabilityTransactions_id']])
            ->addColumn('liabilityTransactions_id', 'integer', ['null' => false, 'identity' => true])
            ->addColumn('liabilities_id', 'integer', ['null' => false])
            ->addColumn('liabilityTransactions_date', 'date', ['null' => false])
            ->addColumn('liabilityTransactions_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('liabilityTransactions_amount', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('liabilityTransactions_interest', 'biginteger', ['null' => false, 'default' => 0])
            ->addColumn('liabilityTransactions_reference', 'string', ['limit' => 200, 'null' => true, 'default' => null])
            ->addColumn('liabilityTransactions_notes', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('users_userid', 'integer', ['null' => true, 'default' => null])
            ->addColumn('liabilityTransactions_deleted', 'boolean', ['null' => false, 'default' => 0])
            ->addIndex(['liabilities_id', 'liabilityTransactions_date'])
            ->addForeignKey('liabilities_id', 'liabilities', 'liabilities_id', ['constraint' => 'liabilityTransactions_liabilities_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE'])
            ->addForeignKey('users_userid', 'users', 'users_userid', ['constraint' => 'liabilityTransactions_users_users_userid_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL'])
            ->create();
        $this->table('assets')
            ->addColumn('liabilities_id', 'integer', ['null' => true, 'default' => null])
            ->addForeignKey('liabilities_id', 'liabilities', 'liabilities_id', ['constraint' => 'assets_liabilities_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL'])
            ->update();

        foreach ($this->fetchAll("SELECT instancePositions_id, instancePositions_actions FROM instancePositions") as $position) {
            $actions = array_filter(explode(",", (string) $position['instancePositions_actions']));
            $new = $actions;
            if (in_array("FINANCE:REPORTS:VIEW", $actions, true) and !in_array("FINANCE:LIABILITIES:VIEW", $new, true)) $new[] = "FINANCE:LIABILITIES:VIEW";
            if (in_array("FINANCE:EXPENSES:EDIT", $actions, true)) {
                if (!in_array("FINANCE:LIABILITIES:VIEW", $new, true)) $new[] = "FINANCE:LIABILITIES:VIEW";
                if (!in_array("FINANCE:LIABILITIES:EDIT", $new, true)) $new[] = "FINANCE:LIABILITIES:EDIT";
            }
            if ($new === $actions) continue;
            $this->execute("UPDATE instancePositions SET instancePositions_actions = ? WHERE instancePositions_id = ?", [implode(",", $new), $position['instancePositions_id']]);
        }
    }

    public function down(): void
    {
        foreach ($this->fetchAll("SELECT instancePositions_id, instancePositions_actions FROM instancePositions") as $position) {
            $actions = array_filter(explode(",", (string) $position['instancePositions_actions']));
            $kept = array_values(array_diff($actions, ["FINANCE:LIABILITIES:VIEW", "FINANCE:LIABILITIES:EDIT"]));
            if (count($kept) == count($actions)) continue;
            $this->execute("UPDATE instancePositions SET instancePositions_actions = ? WHERE instancePositions_id = ?", [implode(",", $kept), $position['instancePositions_id']]);
        }
        $this->table('assets')->dropForeignKey('liabilities_id')->removeColumn('liabilities_id')->update();
        $this->table('liabilityTransactions')->drop()->save();
        $this->table('liabilities')->drop()->save();
    }
}
