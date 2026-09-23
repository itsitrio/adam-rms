<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Quote sections - custom headings a project's quote groups its equipment under (e.g. Video, Audio,
 * Lighting on a stage) in place of the business-wide asset categories, and note lines that sit under
 * them. A note line can carry a price, which counts towards the project's equipment total.
 *
 * Units of an assignment are placed into sections through projectsQuoteAllocations, so an assignment
 * of 10 mics can show 6 under Audio and 4 under Video while staying one assignment. Anything not
 * allocated is shown under its asset category as before, so existing quotes are unchanged.
 */
final class ProjectQuoteSections extends AbstractMigration
{
    public function change(): void
    {
        $this->table('projectsQuoteSections', [
                'id' => false,
                'primary_key' => ['projectsQuoteSections_id'],
                'engine' => 'InnoDB',
                'encoding' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'row_format' => 'DYNAMIC',
            ])
            ->addColumn('projectsQuoteSections_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'identity' => 'enable',
            ])
            ->addColumn('projects_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteSections_name', 'string', [
                'null' => false,
                'limit' => 255,
            ])
            ->addColumn('projectsQuoteSections_rank', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteSections_deleted', 'boolean', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_TINY,
            ])
            ->addForeignKey('projects_id', 'projects', 'projects_id', [
                'constraint' => 'projectsQuoteSections_projects_projects_id_fk',
                'update' => 'CASCADE',
                'delete' => 'CASCADE',
            ])
            ->create();

        $this->table('projectsQuoteAllocations', [
                'id' => false,
                'primary_key' => ['projectsQuoteAllocations_id'],
                'engine' => 'InnoDB',
                'encoding' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'row_format' => 'DYNAMIC',
            ])
            ->addColumn('projectsQuoteAllocations_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'identity' => 'enable',
            ])
            ->addColumn('assetsAssignments_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteSections_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteAllocations_quantity', 'integer', [
                'null' => false,
                'default' => '1',
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addIndex(['assetsAssignments_id', 'projectsQuoteSections_id'], [
                'name' => 'projectsQuoteAllocations_assignment_section_uindex',
                'unique' => true,
            ])
            ->addForeignKey('assetsAssignments_id', 'assetsAssignments', 'assetsAssignments_id', [
                'constraint' => 'projectsQuoteAllocations_assetsAssignments_id_fk',
                'update' => 'CASCADE',
                'delete' => 'CASCADE',
            ])
            ->addForeignKey('projectsQuoteSections_id', 'projectsQuoteSections', 'projectsQuoteSections_id', [
                'constraint' => 'projectsQuoteAllocations_projectsQuoteSections_id_fk',
                'update' => 'CASCADE',
                'delete' => 'CASCADE',
            ])
            ->create();

        $this->table('projectsQuoteLines', [
                'id' => false,
                'primary_key' => ['projectsQuoteLines_id'],
                'engine' => 'InnoDB',
                'encoding' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'row_format' => 'DYNAMIC',
            ])
            ->addColumn('projectsQuoteLines_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'identity' => 'enable',
            ])
            ->addColumn('projects_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteSections_id', 'integer', [
                'null' => true,
                'default' => null,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteLines_text', 'string', [
                'null' => false,
                'limit' => 1000,
            ])
            ->addColumn('projectsQuoteLines_quantity', 'integer', [
                'null' => false,
                'default' => '1',
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteLines_price', 'biginteger', [
                'null' => false,
                'default' => '0',
                'comment' => 'Price of one unit, in the minor unit of the instance currency',
            ])
            ->addColumn('projectsQuoteLines_rank', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('projectsQuoteLines_deleted', 'boolean', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_TINY,
            ])
            ->addForeignKey('projects_id', 'projects', 'projects_id', [
                'constraint' => 'projectsQuoteLines_projects_projects_id_fk',
                'update' => 'CASCADE',
                'delete' => 'CASCADE',
            ])
            ->addForeignKey('projectsQuoteSections_id', 'projectsQuoteSections', 'projectsQuoteSections_id', [
                'constraint' => 'projectsQuoteLines_projectsQuoteSections_id_fk',
                'update' => 'CASCADE',
                'delete' => 'SET_NULL',
            ])
            ->create();
    }
}
