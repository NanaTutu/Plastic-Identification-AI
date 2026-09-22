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
            'api_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'owner' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'is_active' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'rate_limit' => [
                'type'    => 'INT',
                'constraint' => 11,
                'default' => 10,
            ],
            'window_seconds' => [
                'type'    => 'INT',
                'constraint' => 11,
                'default' => 60,
            ],
            'total_requests' => [
                'type'    => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('api_key');
        $this->forge->createTable('api_keys', true);

        $this->db->query('ALTER TABLE api_keys MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

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

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'job_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'api_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'model' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'detections' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'inference_ms' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('api_key');
        $this->forge->addKey('job_id');
        $this->forge->createTable('prediction_logs', true);

        $this->db->query('ALTER TABLE prediction_logs MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down()
    {
        $this->forge->dropTable('prediction_logs', true);
        $this->forge->dropTable('predictions', true);
        $this->forge->dropTable('images', true);
        $this->forge->dropTable('api_keys', true);
    }
}
