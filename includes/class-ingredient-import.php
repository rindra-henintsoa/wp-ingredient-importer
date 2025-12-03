<?php

if (!defined('ABSPATH')) exit;

class Ingredient_Import {

    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    /* === toutes tes fonctions d'import === */

    public function preview_file($path) {
        // return first 10 rows parsed
        $rows = [];
        $reader = $this->get_reader( $path );
        if ( $reader['type'] === 'php' ) {
            try {
                $spreadsheet = $reader['reader']->load( $path );
                $worksheet = $spreadsheet->getActiveSheet();
                $i = 0;
                foreach ( $worksheet->getRowIterator() as $row ) {
                    if ( $i++ >= 10 ) break;
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowData = [];
                    foreach ( $cellIterator as $cell ) {
                        $rowData[] = $cell->getValue();
                    }
                    $rows[] = $rowData;
                }
            } catch ( Exception $e ) {
                $rows[] = [ 'error' => $e->getMessage() ];
            }
        } else {
            // CSV fallback
            if ( ( $handle = fopen( $path, 'r' ) ) !== false ) {
                $i = 0;
                while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false && $i++ < 10 ) {
                    $rows[] = $data;
                }
                fclose( $handle );
            }
        }
        return $rows;
     }
    public function process_file($path, $log_id=null, $batch_size=500) { 
        global $wpdb;
        $inserted = 0;
        $updated = 0;
        $ignored = 0;
        $rows_total = 0;
        $rows_processed = 0;
        $errors = [];

        $reader_meta = $this->get_reader( $path );
        if ( $reader_meta['type'] === 'php' ) {
            $reader = $reader_meta['reader'];
            try {
                $spreadsheet = $reader->load( $path );
                $worksheet = $spreadsheet->getActiveSheet();

                $header = [];
                $first = true;
                $batch = [];
                foreach ( $worksheet->getRowIterator() as $row_index => $row ) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowData = [];
                    foreach ( $cellIterator as $cell ) {
                        $rowData[] = $cell->getValue();
                    }

                    // Detect header
                    if ( $first ) {
                        $header = $this->normalize_header( $rowData );
                        $first = false;
                        continue;
                    }

                    // Map row
                    $assoc = $this->map_row_by_header( $header, $rowData );
                    $rows_total++;
                    $batch[] = $assoc;

                    if ( count( $batch ) >= $batch_size ) {
                        $res = $this->process_batch( $batch );
                        $inserted += $res['inserted'];
                        $updated += $res['updated'];
                        $ignored += $res['ignored'];
                        $rows_processed += count( $batch );
                        $batch = [];
                        // update log
                        if ( $log_id ) {
                            $wpdb->update( $this->plugin->import->log_table, [ 'rows_processed' => $rows_processed, 'inserted' => $inserted, 'updated' => $updated, 'ignored' => $ignored ], [ 'id' => $log_id ] );
                        }
                    }
                }

                // remaining
                if ( count( $batch ) ) {
                    $res = $this->process_batch( $batch );
                    $inserted += $res['inserted'];
                    $updated += $res['updated'];
                    $ignored += $res['ignored'];
                    $rows_processed += count( $batch );
                }

            } catch ( Exception $e ) {
                $errors[] = $e->getMessage();
            }
        } else {
            // CSV fallback
            if ( ( $handle = fopen( $path, 'r' ) ) !== false ) {
                $header = [];
                $first = true;
                $batch = [];
                while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
                    if ( $first ) {
                        $header = $this->normalize_header( $data );
                        $first = false;
                        continue;
                    }
                    $assoc = $this->map_row_by_header( $header, $data );
                    $rows_total++;
                    $batch[] = $assoc;
                    if ( count( $batch ) >= $batch_size ) {
                        $res = $this->process_batch( $batch );
                        $inserted += $res['inserted'];
                        $updated += $res['updated'];
                        $ignored += $res['ignored'];
                        $rows_processed += count( $batch );
                        $batch = [];
                        if ( $log_id ) {
                            $wpdb->update( $this->plugin->import->log_table, [ 'rows_processed' => $rows_processed, 'inserted' => $inserted, 'updated' => $updated, 'ignored' => $ignored ], [ 'id' => $log_id ] );
                        }
                    }
                }
                if ( count( $batch ) ) {
                    $res = $this->process_batch( $batch );
                    $inserted += $res['inserted'];
                    $updated += $res['updated'];
                    $ignored += $res['ignored'];
                    $rows_processed += count( $batch );
                }
                fclose( $handle );
            } else {
                $errors[] = 'Impossible d\'ouvrir le fichier CSV';
            }
        }

        return [
            'rows_total' => $rows_total,
            'rows_processed' => $rows_processed,
            'inserted' => $inserted,
            'updated' => $updated,
            'ignored' => $ignored,
            'errors' => $errors,
        ];
    }
    protected function normalize_header($row) { 
        $normalized = [];
    
        foreach ( $row as $col ) {
            $h = trim($col);
            // clean invalid UTF-8
            $h = mb_convert_encoding($h, 'UTF-8', 'UTF-8');
            $h = strtolower($h);
    
            // remove accents safely
            $h = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h);
    
            // replace spaces by underscores
            $h = str_replace(' ', '_', $h);
    
            // remove anything not valid for keys
            $h = preg_replace('/[^a-z0-9_]/', '', $h);
    
            // map canonical keys
            if ( in_array( $h, ['reference', 'ref', 'ref_', 'n_reference'], true ) ) $h = 'reference';
            if ( in_array( $h, ['intitule', 'intitule_', 'titre', 'libelle'], true ) ) $h = 'intitule';
            if ( in_array( $h, ['ingredients_francais','ingredient_francais','ingredients','ingredient','ingredients_français','liste_ingredients','composition'], true ) ) $h = 'ingredients_francais';
    
            $normalized[] = $h;
        }
    
        return $normalized;    
    }
    protected function process_batch($batch) { 
        $inserted = 0;
        $updated = 0;
        $ignored = 0;

        foreach ( $batch as $row ) {
            // We expect keys normalized such as 'reference', 'intitule', 'ingredients_francais' based on sample
            $reference = isset( $row['reference'] ) ? trim( (string) $row['reference'] ) : '';
            $intitule = isset( $row['intitule'] ) ? trim( (string) $row['intitule'] ) : '';
            // Sometimes header may be 'ingredients_francais' or 'ingredients_francais' etc.
            $ingredients_key = null;
            foreach ( ['ingredients_francais', 'ingredients_francais', 'ingredients', 'ingredients_francais'] as $k ) {
                if ( isset( $row[ $k ] ) ) { $ingredients_key = $k; break; }
            }

            if ( ! $ingredients_key ) {
                // try to find a header that contains 'ingredient'
                foreach ( $row as $k => $v ) {
                    if ( strpos( $k, 'ingredient' ) !== false ) { $ingredients_key = $k; break; }
                }
            }
            $ingredients = $ingredients_key ? $row[ $ingredients_key ] : '';

            if ( empty( $reference ) ) {
                $ignored++;
                continue;
            }

            // sanitize
            $reference_s = sanitize_text_field( $reference );
            $title_s = sanitize_text_field( $intitule ?: $reference_s );
            // Ingredients can be long; allow some HTML but sanitize
            $ingredients_s = wp_kses_post( nl2br( trim( (string) $ingredients ) ) );

            // Try to find existing post by meta _ingredient_reference
            $existing = $this->get_post_by_reference( $reference_s );


            if ( $existing ) {
                // update
                $post_id = $existing;
                wp_update_post( [ 'ID' => $post_id, 'post_title' => $title_s, 'post_content' => $ingredients_s ] );
                update_post_meta( $post_id, '_ingredient_reference', $reference_s );
                update_post_meta( $post_id, '_ingredient_intitule', $title_s );
                update_post_meta( $post_id, '_ingredient_ingredients_fr', $ingredients_s );
                $updated++;
            } else {
                // insert
                $post_id = wp_insert_post( [
                    'post_type' => 'ingredient_fiche',
                    'post_title' => $title_s,
                    'post_content' => $ingredients_s,
                    'post_status' => 'publish'
                ] );
                if ( is_wp_error( $post_id ) || ! $post_id ) {
                    // Could not insert
                    $ignored++;
                    continue;
                }
                update_post_meta( $post_id, '_ingredient_reference', $reference_s );
                update_post_meta( $post_id, '_ingredient_intitule', $title_s );
                update_post_meta( $post_id, '_ingredient_ingredients_fr', $ingredients_s );
                $inserted++;
            }
        }
        return [ 'inserted' => $inserted, 'updated' => $updated, 'ignored' => $ignored ];
    }
    protected function get_reader($path) { 
        // Returns ['type' => 'php'|'csv', 'reader' => ReaderInstance]
        // If PhpSpreadsheet available, return reader. Otherwise csv fallback.
        if ( class_exists( '\PhpOffice\PhpSpreadsheet\Reader\Xlsx' ) ) {
            try {
                $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
                if ( $ext === 'csv' ) {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                    $reader->setDelimiter(',');
                } elseif ( $ext === 'xls' ) {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                } else {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                }
                $reader->setReadDataOnly( true );
                return [ 'type' => 'php', 'reader' => $reader ];
            } catch ( Exception $e ) {
                return [ 'type' => 'csv', 'reader' => null ];
            }
        }
        return [ 'type' => 'csv', 'reader' => null ];
    }
    protected function get_post_by_reference($ref) { 
        global $wpdb;
        $meta_key = '_ingredient_reference';
        $sql = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", $meta_key, $ref );
        return $wpdb->get_var( $sql );
    }
    public function cron_process_pending_imports() { 
        global $wpdb;
        $pending = $wpdb->get_row( "SELECT * FROM {$this->plugin->log_table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1" );
        if ( ! $pending ) return;
        $wpdb->update( $this->plugin->log_table, [ 'status' => 'running', 'started_at' => current_time( 'mysql' ) ], [ 'id' => $pending->id ] );
        $res = $this->process_file( $pending->file_path, $pending->id, 500 );
        $status = $res['errors'] ? 'failed' : 'done';
        $wpdb->update( $this->plugin->log_table, [
            'status' => $status,
            'rows_total' => $res['rows_total'],
            'rows_processed' => $res['rows_processed'],
            'inserted' => $res['inserted'],
            'updated' => $res['updated'],
            'ignored' => $res['ignored'],
            'errors' => maybe_serialize( $res['errors'] ),
            'finished_at' => current_time( 'mysql' )
        ], [ 'id' => $pending->id ] );
    }

    protected function map_row_by_header( $header, $row ) {
        $assoc = [];
        foreach ( $header as $i => $h ) {
            $assoc[ $h ] = isset( $row[ $i ] ) ? $row[ $i ] : null;
        }
        return $assoc;
    }

    public function validate_required_columns( $path ) {

        $reader_meta = $this->get_reader( $path );
    
        if ( $reader_meta['type'] === 'php' ) {
            try {
                $spreadsheet = $reader_meta['reader']->load( $path );
                $worksheet = $spreadsheet->getActiveSheet();
    
                // Lire la première ligne = header
                $rowIterator = $worksheet->getRowIterator();
                $firstRow = $rowIterator->current();
                $cellIterator = $firstRow->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
    
                $header = [];
                foreach ( $cellIterator as $cell ) {
                    $header[] = $cell->getValue();
                }
    
            } catch ( Exception $e ) {
                return false;
            }
    
        } else {
            // CSV
            if ( ( $handle = fopen( $path, 'r' ) ) === false ) {
                return false;
            }
            $header = fgetcsv( $handle, 0, ',' );
            fclose( $handle );
        }
    
        // Normaliser les colonnes (exactement comme process_file)
        $normalized = $this->normalize_header( $header );
    
        // Colonnes obligatoires
        $required = [ 'reference', 'intitule', 'ingredients_francais' ];
    
        // Tester si toutes les colonnes obligatoires existent
        foreach ( $required as $col ) {
            if ( ! in_array( $col, $normalized, true ) ) {
                return false;
            }
        }
    
        return true;
    }
}
