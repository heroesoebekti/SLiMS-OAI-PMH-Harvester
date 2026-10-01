<?php
/**
 * @Created by          : Heru Subekti (heroe.soebekti@gmail.com)
 * @Date                : 01/10/2026
 * @File name           : 1_AddOaiColl.php
 */

use SLiMS\DB;
use SLiMS\Migration\Migration;

class AddOaiColl extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    function up()
    {
        $db = DB::getInstance();$db->query("CREATE TABLE IF NOT EXISTS `harvest_biblio_relation` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `node_id` int(11) NOT NULL,
          `oai_identifier` varchar(255) NOT NULL,
          `biblio_id` int(11) NOT NULL,
          `synced_at` datetime NOT NULL,
          PRIMARY KEY (`id`),
          KEY `node_id` (`node_id`),
          KEY `biblio_id` (`biblio_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $db->query("CREATE TABLE IF NOT EXISTS `harvest_biblio` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `node_id` int(11) NOT NULL,
          `biblio_id` int(11) NOT NULL,
          PRIMARY KEY (`id`),
          KEY `node_id` (`node_id`),
          KEY `biblio_id` (`biblio_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

        $db->query("CREATE TABLE IF NOT EXISTS `harvest_nodes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `node_name` varchar(255) NOT NULL,
          `oai_url` varchar(255) NOT NULL,
          `node_type` varchar(50) NOT NULL DEFAULT 'slims',
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    function down()
    {
        $db = DB::getInstance();
        $db->query("DROP TABLE IF EXISTS `harvest_biblio`");
        $db->query("DROP TABLE IF EXISTS `harvest_biblio_relation`");
        $db->query("DROP TABLE IF EXISTS `harvest_nodes`");
    }
}