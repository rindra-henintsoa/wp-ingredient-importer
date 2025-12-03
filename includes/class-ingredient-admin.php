<?php

if (!defined('ABSPATH')) exit;

class Ingredient_Admin {

    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('views_edit-ingredient_fiche', [$this, 'add_import_button_to_cpt_list']);
        add_action('current_screen', [ $this, 'maybe_load_metabox' ]);
        add_action( 'admin_notices', [$this, 'unsupported_file_import_error']);
    }

    public function admin_menu() {
        $parent_slug = 'edit.php?post_type=ingredient_fiche';

        add_submenu_page(
            $parent_slug, 'Importer', 'Importer',
            'manage_options', 'ingredient_fiches_import',
            [$this, 'admin_import_page']
        );

        add_submenu_page(
            $parent_slug, 'Historique', 'Historique Import',
            'manage_options', 'ingredient_fiches_history',
            [$this, 'admin_history_page']
        );
        
        remove_submenu_page(
            $parent_slug,
            'post-new.php?post_type=ingredient_fiche'
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'ingredient_fiches') === false) return;

        wp_enqueue_style('ingredient-importer-admin',
            site_url('/wp-content/plugins/ingredient-importer/assets/css/admin.css')
        );

        wp_enqueue_script('ingredient-importer-admin',
            site_url('/wp-content/plugins/ingredient-importer/assets/js/admin.js'),
            ['jquery'], $this->plugin->version, true
        );
    }

    /* IMPORT PAGE + HANDLE UPLOAD */
    /* — EXACTEMENT TON CODE ORIGINAL — */
    public function admin_import_page() { 
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé' );
        }

        $upload_dir = wp_upload_dir();
        $base = trailingslashit( $upload_dir['basedir'] ) . $this->plugin->upload_subdir;
        if ( ! file_exists( $base ) ) {
            wp_mkdir_p( $base );
        }

        ?>
        <div class="wrap">
            <h1>Importer des fiches ingrédients</h1>
            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'ingredient_import_action', 'ingredient_import_nonce' ); ?>
                <input type="hidden" name="action" value="ingredient_import">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ingredient_file">Fichier (.xlsx, .xls, .csv)</label></th>
                        <td><input type="file" name="ingredient_file" id="ingredient_file" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Mode</th>
                        <td>
                            <label><input type="radio" name="import_mode" value="simulate" checked> Simuler (preview)</label><br>
                            <label><input type="radio" name="import_mode" value="execute"> Exécuter</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Batch size</th>
                        <td><input type="number" name="batch_size" value="500" min="10" max="5000"></td>
                    </tr>
                </table>
                <?php submit_button( 'Lancer l\'import' ); ?>
            </form>
        </div>
        <?php
    }
    public function admin_history_page() { 
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT * FROM {$this->plugin->log_table} ORDER BY id DESC LIMIT 100" );
        ;
        echo '<div class="wrap"><h1>Historique des imports</h1><table class="wp-list-table history-import-content widefat fixed striped"><thead><tr><th>ID</th><th>Fichier</th><th>Lignes</th><th>Inséré</th><th>Mis à jour</th><th>Date</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>';
            echo '<td>' . esc_html( $r->id ) . '</td>';
            echo '<td>' . esc_html( $r->file_name ) . '</td>';
            echo '<td>' . esc_html( $r->rows_processed ) . '</td>';
            echo '<td>' . esc_html( $r->inserted ) . '</td>';
            echo '<td>' . esc_html( $r->updated ) . '</td>';
            echo '<td>' . esc_html( $r->finished_at ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    public function handle_upload_post() { 
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'ingredient_import_action', 'ingredient_import_nonce' );

        if ( empty( $_FILES['ingredient_file'] ) || $_FILES['ingredient_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_redirect( add_query_arg( 'msg', 'no_file', admin_url( 'admin.php?page=ingredient_fiches_import' ) ) );
            exit;
        }

        $file = $_FILES['ingredient_file'];
        $allowed = [ 'xlsx', 'xls', 'csv' ];
        $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $file_ext, $allowed, true ) ) {
            $redirect_url = add_query_arg(
                [ 'import_error' => 'format' ],
                admin_url( 'edit.php?post_type=ingredient_fiche&page=ingredient_fiches_import' )
            );
        
            wp_redirect( $redirect_url );
            exit;
        }

        $upload_dir = wp_upload_dir();
        $base = trailingslashit( $upload_dir['basedir'] ) . $this->plugin->upload_subdir;
        if ( ! file_exists( $base ) ) wp_mkdir_p( $base );

        $unique = time() . '-' . wp_unique_filename( $base, sanitize_file_name( $file['name'] ) );
        $destination = $base . '/' . $unique;
        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
            wp_die( 'Impossible de déplacer le fichier' );
        }

        $isvalid = $this->plugin->import->validate_required_columns( $destination );
        
        if ( ! $isvalid  ) {

            // Supprimer le fichier importé car invalide
            if ( file_exists( $destination ) ) {
                unlink( $destination );
            }
        
            // Rediriger avec message d’erreur
            $redirect = add_query_arg(
                [ 'import_error' => 'missing_columns' ],
                admin_url( 'edit.php?post_type=ingredient_fiche&page=ingredient_fiches_import' )
            );
        
            wp_redirect( $redirect );
            exit;
        }

        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $this->plugin->log_table, [
            'file_name' => $file['name'],
            'file_path' => $destination,
            'rows_total' => 0,
            'rows_processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'ignored' => 0,
            'errors' => '',
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
            'user_id' => get_current_user_id(),
        ], [ '%s','%s','%d','%d','%d','%d','%d','%s','%s','%s','%d' ] );

        $log_id = $wpdb->insert_id;

        // If simulate, do a quick preview synchronously
        $mode = sanitize_text_field( $_POST['import_mode'] ?? 'simulate' );
        $batch_size = intval( $_POST['batch_size'] ?? 500 );

        if ( $mode === 'simulate' ) {
            $preview = $this->plugin->import->preview_file( $destination );
            // Show preview (simple)
            echo '<div class="wrap"><h1>Preview import</h1>';
            echo '<p>Fichier: ' . esc_html( $file['name'] ) . '</p>';
            echo '<pre style="white-space:pre-wrap;border:1px solid #ddd;padding:12px;">';
            echo esc_html( print_r( $preview, true ) );
            echo '</pre>';
            echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ingredient_fiches_import' ) ) . '">Retour</a></p>';
            echo '</div>';
            exit;
        }

        // For real execution: mark running and process via cron immediate schedule to avoid timeout.
        $wpdb->update( $this->plugin->log_table, [ 'status' => 'running', 'started_at' => current_time( 'mysql' ) ], [ 'id' => $log_id ] );

        // Process in batches synchronously but safe. If file is huge, recommend WP-CLI or cron.
        $result = $this->plugin->import->process_file( $destination, $log_id, $batch_size );

        // update final status
        $status = $result['errors'] ? 'failed' : 'done';
        $wpdb->update( $this->plugin->log_table, [
            'status' => $status,
            'rows_total' => $result['rows_total'],
            'rows_processed' => $result['rows_processed'],
            'inserted' => $result['inserted'],
            'updated' => $result['updated'],
            'ignored' => $result['ignored'],
            'errors' => maybe_serialize( $result['errors'] ),
            'finished_at' => current_time( 'mysql' )
        ], [ 'id' => $log_id ] );

        // Redirect to history
        wp_redirect( admin_url( 'admin.php?page=ingredient_fiches_history' ) );
        exit;
    }

    public function unsupported_file_import_error() {

        if ( isset( $_GET['import_error'] ) && $_GET['import_error'] === 'format' ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>Erreur :</strong> Format non supporté. Veuillez importer un fichier XLSX, XLS ou CSV.</p>
            </div>
            <?php
        }

        if ( isset($_GET['import_error']) && $_GET['import_error'] === 'missing_columns' ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong>Erreur :</strong> Le fichier importé ne contient pas les colonnes obligatoires :
                    <code>reference</code>, <code>intitule</code>, <code>ingredients_francais</code>.
                </p>
            </div>
            <?php
        }
    }
    public function add_import_button_to_cpt_list($views) {
        $import_url = admin_url('admin.php?page=ingredient_fiches_import');
        $views['importer'] = '<a href="' . $import_url . '" class="page-title-action">Importer</a>';
    
        return $views;
    }

    public function add_ref_meta_box() {
        add_meta_box(
            'ingredient_reference_box',
            'Référence ingrédient',
            'render_ingredient_reference_box',
            'ingredient_fiche',
            'side',
            'high'
        );
    }
    
    public function maybe_load_metabox( $screen = null ) {

        if ( ! $screen ) {
            $screen = get_current_screen();
        }

        // Vérifie qu'on est sur post.php
        if (
            $screen->base === 'post' &&                      // page d'édition
            $screen->id === 'ingredient_fiche' &&            // ton CPT
            isset($_GET['action']) && $_GET['action'] === 'edit'
        ) {
            // On est sur : /wp-admin/post.php?post=ID&action=edit
            add_action('add_meta_boxes', [ $this, 'add_reference_metabox' ]);
        }
    }

    public function add_reference_metabox() {
        add_meta_box(
            'ingredient_reference_box',
            'Référence ingrédient',
            [ $this, 'render_reference_box' ],
            'ingredient_fiche',
            'side',
            'high'
        );
    }

    public function render_reference_box( $post ) {
        $reference = get_post_meta( $post->ID, '_ingredient_reference', true );

        echo '<p><strong>Référence :</strong></p>';
        echo '<input type="text" readonly class="widefat"
               value="' . esc_attr($reference) . '" 
               style="background:#f5f5f5;color:#444;">';
    }
    
}
