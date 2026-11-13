<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Personas - other names (and optionally emails) a user goes by in a business, such as a fandom name
 * at some events and their legal name at others. A project can show one of its manager's personas in
 * place of the manager's own name and email (on quotes, invoices, crew emails, vacancies, calendars),
 * so one account can be used for both. Projects without a persona are unchanged.
 */
final class UserPersonas extends AbstractMigration
{
    public function change(): void
    {
        $this->table('usersPersonas', [
                'id' => false,
                'primary_key' => ['usersPersonas_id'],
                'engine' => 'InnoDB',
                'encoding' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'row_format' => 'DYNAMIC',
            ])
            ->addColumn('usersPersonas_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'identity' => 'enable',
            ])
            ->addColumn('users_userid', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('instances_id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
            ])
            ->addColumn('usersPersonas_name', 'string', [
                'null' => false,
                'limit' => 255,
            ])
            ->addColumn('usersPersonas_email', 'string', [
                'null' => true,
                'default' => null,
                'limit' => 255,
            ])
            ->addColumn('usersPersonas_deleted', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addIndex(['users_userid', 'instances_id'])
            ->addForeignKey('users_userid', 'users', 'users_userid', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('instances_id', 'instances', 'instances_id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('projects')
            ->addColumn('projects_manager_persona', 'integer', [
                'null' => true,
                'default' => null,
                'limit' => MysqlAdapter::INT_REGULAR,
                'after' => 'projects_manager',
            ])
            ->addForeignKey('projects_manager_persona', 'usersPersonas', 'usersPersonas_id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->update();
    }
}
