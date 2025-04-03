<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFieldsChangesHistoryTable extends Migration
{
    public function up()
    {
        $this->forge->addField(
            [
                'id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'entity' => [
                    'type' => 'VARCHAR',
                    'constraint' => '64',
                ],
                'entity_id' => [
                    'type' => 'INT',
                    'constraint' => 5,
                    'unsigned' => true
                ],
                'user_id' => [
                    'type' => 'INT',
                    'constraint' => 5,
                    'unsigned' => true
                ],
                'created' => [
                    'type' => 'TIMESTAMP(0)'
                ],
                'field' => [
                    'type' => 'VARCHAR',
                    'constraint' => '64',
                ],
                'value_before' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'value_after' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
            ]
        );
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('user_id', 'auth_users', 'user_id');
        $this->forge->createTable('fields_changes_history');
    }

    public function down()
    {
        $this->forge->dropTable('fields_changes_history', true);
    }
}
