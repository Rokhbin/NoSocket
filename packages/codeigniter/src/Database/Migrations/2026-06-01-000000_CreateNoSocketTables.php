<?php

declare(strict_types=1);

namespace NoSocket\CodeIgniter\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateNoSocketTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 128],
            'event' => ['type' => 'VARCHAR', 'constraint' => 128],
            'payload_json' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
            'expires_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['channel', 'id']);
        $this->forge->addKey('expires_at');
        $this->forge->createTable('nosocket_events');

        $this->forge->addField([
            'key_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'bucket' => ['type' => 'BIGINT'],
            'hits' => ['type' => 'INT', 'unsigned' => true],
            'expires_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey(['key_hash', 'bucket']);
        $this->forge->addKey('expires_at');
        $this->forge->createTable('nosocket_rate_limits');

        $this->forge->addField([
            'channel' => ['type' => 'VARCHAR', 'constraint' => 128],
            'event_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('channel');
        $this->forge->createTable('nosocket_channel_watermarks');
    }

    public function down(): void
    {
        $this->forge->dropTable('nosocket_channel_watermarks', true);
        $this->forge->dropTable('nosocket_rate_limits', true);
        $this->forge->dropTable('nosocket_events', true);
    }
}
