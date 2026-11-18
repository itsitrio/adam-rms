<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives the new reporting permissions to the roles that can already see the same information, so nobody loses
 * sight of anything and businesses don't have to edit every role before they can use the reports:
 * - roles that can see the payments ledger can see the financial reports and the business's overheads
 * - roles that can add payments to projects can add overheads
 * - roles that can see business stats can see the asset, maintenance and crew reports
 * - roles that can rename maintenance jobs can record what a repair cost
 */
final class ReportingPermissions extends AbstractMigration
{
    private const GRANTS = [
        "FINANCE:PAYMENTS_LEDGER:VIEW" => ["FINANCE:REPORTS:VIEW", "FINANCE:EXPENSES:VIEW"],
        "PROJECTS:PROJECT_PAYMENTS:CREATE" => ["FINANCE:EXPENSES:EDIT"],
        "BUSINESS:BUSINESS_STATS:VIEW" => ["REPORTS:VIEW"],
        "MAINTENANCE_JOBS:EDIT:NAME" => ["MAINTENANCE_JOBS:EDIT:COST"],
    ];

    public function up(): void
    {
        foreach ($this->fetchAll("SELECT instancePositions_id, instancePositions_actions FROM instancePositions") as $position) {
            $actions = array_filter(explode(",", (string) $position['instancePositions_actions']));
            $new = $actions;
            foreach (self::GRANTS as $has => $grants) {
                if (!in_array($has, $actions, true)) continue;
                foreach ($grants as $grant) {
                    if (!in_array($grant, $new, true)) $new[] = $grant;
                }
            }
            //An editor of expenses needs to be able to see them
            if (in_array("FINANCE:EXPENSES:EDIT", $new, true) and !in_array("FINANCE:EXPENSES:VIEW", $new, true)) $new[] = "FINANCE:EXPENSES:VIEW";
            if ($new === $actions) continue;
            $this->execute("UPDATE instancePositions SET instancePositions_actions = ? WHERE instancePositions_id = ?", [implode(",", $new), $position['instancePositions_id']]);
        }
    }

    public function down(): void
    {
        $remove = ["FINANCE:REPORTS:VIEW", "FINANCE:EXPENSES:VIEW", "FINANCE:EXPENSES:EDIT", "REPORTS:VIEW", "MAINTENANCE_JOBS:EDIT:COST"];
        foreach ($this->fetchAll("SELECT instancePositions_id, instancePositions_actions FROM instancePositions") as $position) {
            $actions = array_filter(explode(",", (string) $position['instancePositions_actions']));
            $kept = array_values(array_diff($actions, $remove));
            if (count($kept) == count($actions)) continue;
            $this->execute("UPDATE instancePositions SET instancePositions_actions = ? WHERE instancePositions_id = ?", [implode(",", $kept), $position['instancePositions_id']]);
        }
    }
}
