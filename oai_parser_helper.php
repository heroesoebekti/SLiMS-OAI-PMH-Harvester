<?php
/**
 * @Created by          : Heru Subekti (heroe.soebekti@gmail.com)
 * @Date                : 10/01/2026
 * @File name           : oai_parser_helper.php
 */

defined('INDEX_AUTH') OR die('Direct access not allowed!');

class OaiParserHelper
{

    private static function getElementValue($element): string
    {
        if (!isset($element)) {
            return '';
        }

        if (is_iterable($element)) {
            foreach ($element as $item) {
                $val = trim((string)$item);
                if (!empty($val)) {
                    return $val;
                }
            }
        }
        
        $val = trim((string)$element);
        return $val;
    }

    public static function parse(string $node_type, $dc, $dbs, array &$cache_publisher, array &$cache_place): array
    {
        $data = [
            'gmd_id' => 30,
            'publisher_id' => 'NULL',
            'place_id' => 'NULL',
            'language_id' => "'eng'",
            'title' => 'No Title',
            'publish_year' => date('Y'),
            'notes' => '',
            'collation' => '',
            'isbn_issn' => '',
            'detailinfo' => 'NULL',
            'classification' => '',
            'call_number' => '',
            'image' => 'NULL'
        ];

        $title_val = self::getElementValue($dc->title ?? null);
        if (!empty($title_val)) {
            $data['title'] = $dbs->escape_string($title_val);
        }

        $desc_val = self::getElementValue($dc->description ?? null);
        if (!empty($desc_val)) {
            $data['notes'] = $dbs->escape_string($desc_val);
        }

        if ($node_type === 'slims') {
            $date_val = self::getElementValue($dc->date ?? null);
            $data['publish_year'] = !empty($date_val) ? (int)date("Y", strtotime($date_val)) : date('Y');

            $pub_val = self::getElementValue($dc->publisher ?? null);
            if (!empty($pub_val)) {
                $data['publisher_id'] = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $pub_val, $cache_publisher);
            }

            $place_val = self::getElementValue($dc->coverage ?? null);
            if (!empty($place_val)) {
                $data['place_id'] = utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $place_val, $cache_place);
            }

            $format_val = self::getElementValue($dc->format ?? null);
            $data['collation'] = $dbs->escape_string($format_val);

            $isbn_val = self::getElementValue($dc->identifier_isbn ?? null);
            if (!empty($isbn_val)) {
                $data['isbn_issn'] = $dbs->escape_string($isbn_val);
            }

            if (isset($dc->identifier)) {
                foreach ($dc->identifier as $identifier) {
                    $id_str = trim((string)$identifier);
                    if (preg_match('/^[0-9]{3}(\.[0-9]+)?\s+[A-Z]+\s+[a-z]/', $id_str)) {
                        $data['call_number'] = $dbs->escape_string($id_str);
                        
                        $parts = preg_split('/\s+/', $id_str);
                        if (isset($parts[0])) {
                            $data['classification'] = $dbs->escape_string($parts[0]);
                        }
                        break;
                    }
                }
            }

        } elseif ($node_type === 'ojs') {
            $date_val = self::getElementValue($dc->date ?? null);
            $data['publish_year'] = !empty($date_val) ? (int)date("Y", strtotime($date_val)) : date('Y');

            $pub_val = self::getElementValue($dc->publisher ?? null);
            if (!empty($pub_val)) {
                $data['publisher_id'] = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $pub_val, $cache_publisher);
            }

            $format_val = self::getElementValue($dc->format ?? null);
            $data['collation'] = $dbs->escape_string($format_val);

            $lang_val = self::getElementValue($dc->language ?? null);
            if (!empty($lang_val)) {
                $data['language_id'] = "'" . $dbs->escape_string($lang_val) . "'";
            }

            if (isset($dc->source)) {
                foreach ($dc->source as $source) {
                    $src_val = trim((string)$source);
                    if (preg_match('/^[0-9]{4}-[0-9]{3}[0-9X]$/', $src_val)) {
                        $data['isbn_issn'] = $dbs->escape_string($src_val);
                        break;
                    }
                }
            }
        }

        return $data;
    }
}