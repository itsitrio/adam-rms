<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Event brands: a client that runs several recurring events (a convention every year, or a company running a few
 * different conventions) can have a brand for each, and a project can be tagged with one of its client's brands, so
 * the client page can show how often each brand hires gear and what it spends.
 */
final class AddClientBrands extends AbstractMigration
{
    public function change(): void
    {
        $this->table('clientsBrands', ['id' => false, 'primary_key' => ['clientsBrands_id']])
            ->addColumn('clientsBrands_id', 'integer', ['null' => false, 'identity' => true])
            ->addColumn('clients_id', 'integer', ['null' => false])
            ->addColumn('instances_id', 'integer', ['null' => false])
            ->addColumn('clientsBrands_name', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('clientsBrands_notes', 'text', ['null' => true, 'default' => null])
            ->addColumn('clientsBrands_deleted', 'boolean', ['null' => false, 'default' => 0])
            ->addIndex(['instances_id', 'clients_id'])
            ->addForeignKey('clients_id', 'clients', 'clients_id', ['constraint' => 'clientsBrands_clients_clients_id_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE'])
            ->addForeignKey('instances_id', 'instances', 'instances_id', ['constraint' => 'clientsBrands_instances_instances_id_fk', 'update' => 'CASCADE', 'delete' => 'CASCADE'])
            ->create();
        $this->table('projects')
            ->addColumn('clientsBrands_id', 'integer', ['null' => true, 'default' => null, 'after' => 'clients_id'])
            ->addForeignKey('clientsBrands_id', 'clientsBrands', 'clientsBrands_id', ['constraint' => 'projects_clientsBrands_clientsBrands_id_fk', 'update' => 'CASCADE', 'delete' => 'SET_NULL'])
            ->update();
    }
}
