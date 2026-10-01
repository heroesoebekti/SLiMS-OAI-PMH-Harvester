<?php
/**
 * Plugin Name: OAI-PMH Repository Harvesting
 * Plugin URI: https://github.com/heroesoebekti/SLiMS-OAI-PMH-Harvester
 * Description: SLiMS plugin to harvest OAI-PMH metadata.
 * Version: 1.0.0
 * Author: Heru Subekti
 * Author URI: https://github.com/heroesoebekti
 */

if (!defined('INDEX_AUTH')) {
    die('Direct access not allowed!');
}

$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu('bibliography', 'OAI-PMH Harvesting', __DIR__ . '/harvesting.php');
