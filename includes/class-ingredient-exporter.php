<?php

use \PhpOffice\PhpSpreadsheet\Spreadsheet;
use \PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Ingredient_Exporter_XLSX {

    public function __construct() {
        add_filter('views_edit-ingredient_fiche', [$this, 'add_export_button_to_cpt_list']);
        add_action('admin_post_export_ingredients_xlsx', [$this, 'export_xlsx']);
    }

    /**
     * Ajoute un bouton "Exporter" dans la vue listing du CPT
     */
    public function add_export_button_to_cpt_list($views) {

        $export_url = admin_url('admin-post.php?action=export_ingredients_xlsx');
        $views['export'] = '<a href="'.esc_url($export_url).'" class="page-title-action">Exporter (.xlsx)</a>';

        return $views;
    }

    /**
     * Génération du fichier XLSX
     */
    public function export_xlsx() {

        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé');
        }

        $filename = 'ingredients-export-' . date('d-m-Y') . '.xlsx';

        // Récupération de tous les posts du CPT ingredient
        $posts = get_posts([
            'post_type'      => 'ingredient_fiche',
            'posts_per_page' => -1,
            'post_status'    => 'any'
        ]);

        // Préparation du fichier Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // --------------------------
        // En-têtes du fichier Excel
        // --------------------------
        $headers = [
            'A1' => 'Référence',
            'B1' => 'Intitulé',
            'C1' => 'Ingrédients français',
        ];

        foreach ($headers as $cell => $title) {
            $sheet->setCellValue($cell, $title);
        }

        // --------------------------
        // Contenu du fichier Excel
        // --------------------------
        $row = 2;

        foreach ($posts as $post) {

            $reference = get_post_meta($post->ID, '_ingredient_reference', true);

            $sheet->setCellValue('A' . $row, $reference);
            $sheet->setCellValue('B' . $row, $post->post_title);
            $sheet->setCellValue('C' . $row, $post->post_content);

            $row++;
        }

        // --------------------------
        // Téléchargement du fichier
        // --------------------------
        $writer = new Xlsx($spreadsheet);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
        exit;
    }
}

