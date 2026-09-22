<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInitialTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'api',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('images', true);

        $this->db->query('ALTER TABLE images MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'image_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'label' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'confidence' => [
                'type' => 'FLOAT',
            ],
            'x1' => [
                'type' => 'FLOAT',
            ],
            'y1' => [
                'type' => 'FLOAT',
            ],
            'x2' => [
                'type' => 'FLOAT',
            ],
            'y2' => [
                'type' => 'FLOAT',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('image_id', 'images', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('predictions', true);

        $this->db->query('ALTER TABLE predictions MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down()
    {
        $this->forge->dropTable('predictions', true);
        $this->forge->dropTable('images', true);
    }
}
