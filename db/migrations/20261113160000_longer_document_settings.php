<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Room for longer text in a business's document settings - a cover page can now run to several pages of terms.
 */
final class LongerDocumentSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('instances')
            ->changeColumn('instances_config_documents', 'text', ['null' => true, 'default' => null, 'limit' => MysqlAdapter::TEXT_MEDIUM])
            ->update();
    }

    public function down(): void
    {
        $this->table('instances')
            ->changeColumn('instances_config_documents', 'text', ['null' => true, 'default' => null])
            ->update();
    }
}
