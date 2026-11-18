<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Each project status belongs to a stage of the sales pipeline - Lead, Opportunity, Won, Lost or Cancelled - so
 * reports such as an asset's revenue can tell won work from work that's still being chased or has fallen through.
 * Statuses with the default names are given their stage; any other status starts as a Lead until its business sets it.
 */
final class ProjectStatusPipelineStage extends AbstractMigration
{
    private const DEFAULT_STAGES = [
        "Added to RMS" => "LEAD",
        "Targeted" => "LEAD",
        "Quote Sent" => "OPPORTUNITY",
        "Confirmed" => "WON",
        "Prep" => "WON",
        "Dispatched" => "WON",
        "Returned" => "WON",
        "Closed" => "WON",
        "Cancelled" => "CANCELLED",
        "Lead Lost" => "LOST",
    ];

    public function up(): void
    {
        $this->table('projectsStatuses')
            ->addColumn('projectsStatuses_pipelineStage', 'string', ['limit' => 16, 'null' => false, 'default' => 'LEAD', 'after' => 'projectsStatuses_assetsReleased'])
            ->update();
        foreach (self::DEFAULT_STAGES as $name => $stage) {
            $this->execute("UPDATE projectsStatuses SET projectsStatuses_pipelineStage = ? WHERE projectsStatuses_name = ?", [$stage, $name]);
        }
    }

    public function down(): void
    {
        $this->table('projectsStatuses')
            ->removeColumn('projectsStatuses_pipelineStage')
            ->update();
    }
}
