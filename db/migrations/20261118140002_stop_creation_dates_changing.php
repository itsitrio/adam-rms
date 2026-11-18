<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * These columns hold when something was created (or paid, or started), but were made with ON UPDATE
 * CURRENT_TIMESTAMP, so any change to the row - renaming a maintenance job, setting the cost of a line, editing an
 * asset - moved the date to the moment of the change. The reports date payments, repairs, assets and projects by
 * them, so they now keep the date they were given.
 */
final class StopCreationDatesChanging extends AbstractMigration
{
    private const COLUMNS = [
        "assets" => "assets_inserted",
        "maintenanceJobs" => "maintenanceJobs_timestamp_added",
        "maintenanceJobsMessages" => "maintenanceJobsMessages_timestamp",
        "payments" => "payments_date",
        "projects" => "projects_created",
        "userPositions" => "userPositions_start",
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            $this->execute("ALTER TABLE `$table` MODIFY COLUMN `$column` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            $this->execute("ALTER TABLE `$table` MODIFY COLUMN `$column` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }
}
