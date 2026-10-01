<?php
/**
 * @Created by          : Heru Subekti (heroe.soebekti@gmail.com)
 * @Date                : 10/01/2026
 * @File name           : harvesting_process.php
 */
defined('INDEX_AUTH') OR die('Direct access not allowed!');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/oai_parser_helper.php';
require LIB . 'ip_based_access.inc.php';
require MDLBS . 'system/biblio_indexer.inc.php';

use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\HttpAdapter\GuzzleAdapter;
use GuzzleHttp\Client as GuzzleClient;

while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo ":" . str_repeat(" ", 2048) . "\n\n";
flush();

function send_sse_event($data, $event_type = 'message') {
    echo "event: {$event_type}\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (connection_aborted()) exit;
    flush();
}

global $dbs;

if (isset($_GET['action']) && $_GET['action'] == 'harvest' && (isset($_GET['node_id']) || isset($_GET['id']))) {
    $node_id_pk = (int)(isset($_GET['node_id']) ? $_GET['node_id'] : $_GET['id']);
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'append';

    $stmt = $dbs->prepare("SELECT * FROM harvest_nodes WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $node_id_pk);
    $stmt->execute();
    $result = $stmt->get_result();
    $node_data = $result->fetch_assoc();

    if ($node_data) {
        $node_url = $node_data['oai_url'];
        $node_name = $node_data['node_name'];
        $node_type = $node_data['node_type'];
        $start_time = microtime(true);

        send_sse_event(['message' => __('Initializing connection to Node:') . " {$node_name} (" . __('Type:') . " {$node_type})", 'type' => 'info'], 'log');

        if ($mode === 'fresh') {
            send_sse_event(['message' => __('Clearing old bibliographic data and relations for this node...'), 'type' => 'info'], 'log');
            
            $stmt_get_hb = $dbs->prepare("SELECT biblio_id FROM harvest_biblio WHERE node_id = ?");
            $stmt_get_hb->bind_param("i", $node_id_pk);
            $stmt_get_hb->execute();
            $res_hb = $stmt_get_hb->get_result();
            while ($row_hb = $res_hb->fetch_assoc()) {
                $bid = $row_hb['biblio_id'];
                $dbs->query("DELETE FROM biblio WHERE biblio_id = {$bid}");
                $dbs->query("DELETE FROM search_biblio WHERE biblio_id = {$bid}");
                $dbs->query("DELETE FROM biblio_author WHERE biblio_id = {$bid}");
                $dbs->query("DELETE FROM biblio_topic WHERE biblio_id = {$bid}");
                $dbs->query("DELETE FROM biblio_attachment WHERE biblio_id = {$bid}");
            }

            $dbs->query("DELETE FROM harvest_biblio WHERE node_id = {$node_id_pk}");
            
            $stmt_del_rel = $dbs->prepare("DELETE FROM harvest_biblio_relation WHERE node_id = ?");
            $stmt_del_rel->bind_param("i", $node_id_pk);
            $stmt_del_rel->execute();
            
            send_sse_event(['message' => __('Old data successfully cleared.'), 'type' => 'success'], 'log');
        }

        try {
            $guzzleClient = new GuzzleClient([
                'verify' => false,
                'timeout' => 30
            ]);
            $adapter = new GuzzleAdapter($guzzleClient);
            $client = new Client($node_url, $adapter);
            $endpoint = new Endpoint($client);

            send_sse_event(['message' => __('Connection successful. Starting harvesting process...'), 'type' => 'success'], 'log');
            
            $record_count = 0;
            $cache_publisher = array();
            $cache_place = array();
            $cache_author = array();
            $cache_topic = array();

            $import_date = date('Y-m-d H:i:s');
            $recs = $endpoint->listRecords('oai_dc');
            $indexer = new biblio_indexer($dbs);

            foreach ($recs as $rec) {
                if (!$dbs->ping()) {
                    $dbs->connect();
                }

                try {
                    $header = $rec->header;
                    if (isset($header->status) && (string)$header->status == 'deleted') {
                        continue;
                    }

                    $oai_identifier = isset($header->identifier) ? (string)$header->identifier : '';
                    $metadata = $rec->metadata;
                    if (!$metadata) continue;

                    $oai_dc = $metadata->children('http://www.openarchives.org/OAI/2.0/oai_dc/');
                    if (!isset($oai_dc->dc)) continue;

                    $dc = $oai_dc->dc->children('http://purl.org/dc/elements/1.1/');

                    $data = OaiParserHelper::parse($node_type, $dc, $dbs, $cache_publisher, $cache_place);

                    $external_link = '';
                    if (isset($dc->identifier)) {
                        foreach ($dc->identifier as $identifier) {
                            $id_val = trim((string)$identifier);
                            if (filter_var($id_val, FILTER_VALIDATE_URL)) {
                                $external_link = $id_val;
                                break;
                            }
                        }
                    }
                    if (empty($external_link) && isset($dc->relation)) {
                        foreach ($dc->relation as $relation) {
                            $rel_val = trim((string)$relation);
                            if (filter_var($rel_val, FILTER_VALIDATE_URL)) {
                                $external_link = $rel_val;
                                break;
                            }
                        }
                    }


                    $sql_bib = "REPLACE INTO biblio (gmd_id, title, isbn_issn, publisher_id, publish_year, language_id, publish_place_id, notes, spec_detail_info, input_date, last_update, collation, classification, call_number, image) VALUES ({$data['gmd_id']}, '".$dbs->escape_string($data['title'])."', '".$dbs->escape_string($data['isbn_issn'])."', {$data['publisher_id']}, {$data['publish_year']}, {$data['language_id']}, {$data['place_id']}, '".$dbs->escape_string($data['notes'])."', {$data['detailinfo']}, '$import_date', NOW(), '".$dbs->escape_string($data['collation'])."', '".$dbs->escape_string($data['classification'])."', '".$dbs->escape_string($data['call_number'])."', {$data['image']})";

                    if ($dbs->query($sql_bib)) {
                        $biblio_id = $dbs->insert_id;
                        if (!$biblio_id) {
                            $res_check = $dbs->query("SELECT biblio_id FROM biblio WHERE title = '".$dbs->escape_string($data['title'])."' ORDER BY biblio_id DESC LIMIT 1");
                            if ($res_check && $row_check = $res_check->fetch_row()) {
                                $biblio_id = $row_check[0];
                            }
                        }

                        if ($biblio_id) {
                            $stmt_hb = $dbs->prepare("REPLACE INTO harvest_biblio (node_id, biblio_id) VALUES (?, ?)");
                            $stmt_hb->bind_param("ii", $node_id_pk, $biblio_id);
                            $stmt_hb->execute();

                            $stmt_rel = $dbs->prepare("REPLACE INTO harvest_biblio_relation (node_id, oai_identifier, biblio_id, synced_at) VALUES (?, ?, ?, ?)");
                            $stmt_rel->bind_param("isis", $node_id_pk, $oai_identifier, $biblio_id, $import_date);
                            $stmt_rel->execute();

                            if (!empty($external_link)) {
                                $cur_date = date("Y-m-d H:i:s");
                                $stmt_att = $dbs->prepare("REPLACE INTO files (file_title, file_name, file_url, mime_type, file_dir, input_date, last_update) VALUES (?, ?, ?, 'text/uri-list', 'external', ?, ?)");
                                $file_title = 'Original Link';
                                $stmt_att->bind_param("sssss", $file_title, $external_link, $external_link, $cur_date, $cur_date);
                                if ($stmt_att->execute()) {
                                    $file_id = $dbs->insert_id;
                                    if ($file_id) {
                                        $dbs->query("REPLACE INTO biblio_attachment (biblio_id, file_id, access_type) VALUES ({$biblio_id}, {$file_id}, 'public')");
                                    }
                                }
                            }

                            if (isset($dc->creator)) {
                                $author_order = 1;
                                foreach ($dc->creator as $creator) {
                                    $author_val = trim((string)$creator);
                                    if (!empty($author_val)) {
                                        $author_id = utility::getID($dbs, 'mst_author', 'author_id', 'author_name', $author_val, $cache_author);
                                        if ($author_id) {
                                            $dbs->query("REPLACE INTO biblio_author (biblio_id, author_id, level) VALUES ({$biblio_id}, {$author_id}, {$author_order})");
                                            $author_order++;
                                        }
                                    }
                                }
                            }

                            if (isset($dc->subject)) {
                                foreach ($dc->subject as $subject) {
                                    $topic_val = trim((string)$subject);
                                    if (!empty($topic_val)) {
                                        $topic_id = utility::getID($dbs, 'mst_topic', 'topic_id', 'topic', $topic_val, $cache_topic);
                                        if ($topic_id) {
                                            $dbs->query("REPLACE INTO biblio_topic (biblio_id, topic_id) VALUES ({$biblio_id}, {$topic_id})");
                                        }
                                    }
                                }
                            }

                            try {
                                $indexer->makeIndex($biblio_id);
                            } catch (\Throwable $t) {
                            }
                        }
                    }

                    $record_count++;
                    if ($record_count % 10 === 0) {
                        send_sse_event(['message' => __('Processing record') . " #{$record_count} (" . __('saved & indexed') . ")...", 'type' => 'info'], 'log');
                    }

                } catch (\Throwable $inner_ex) {
                }
            }
            
            $total_time = round(microtime(true) - $start_time, 2);
            $final_msg = "=== " . __('HARVEST RECORD COMPLETED') . " ===\n" . __('Node:') . " {$node_name}\n" . __('Total Successfully Saved & Indexed:') . " {$record_count} " . __('records') . "\n" . __('Execution Time:') . " {$total_time} " . __('seconds') . ".";
            send_sse_event(['message' => $final_msg, 'type' => 'title', 'record_count' => $record_count, 'total_time' => $total_time], 'finish');

        } catch (\Throwable $e) {
            $error_message = "[ERROR] " . $e->getMessage();
            send_sse_event(['message' => $error_message, 'type' => 'error'], 'log');
            send_sse_event(['message' => __('Harvest process stopped.'), 'type' => 'error'], 'finish');
            exit;
        }

    } else {
        send_sse_event(['message' => __('Error: Node ID not found.'), 'type' => 'error'], 'finish');
    }
}
exit;