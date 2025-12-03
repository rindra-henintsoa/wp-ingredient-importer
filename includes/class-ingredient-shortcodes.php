<?php

if (!defined('ABSPATH')) exit;

class Ingredient_Shortcodes {

    public function __construct() {
        add_shortcode('ingredients', [ $this, 'shortcode_ingredients' ]);
    }

    public function shortcode_ingredients($atts) {

        // Attributs du shortcode
        $atts = shortcode_atts([
            'recherche' => 'false',
        ], $atts);

        $enable_search = ($atts['recherche'] === 'true');

        // Pagination
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;

        // Recherche
        $search_query = '';

        if ($enable_search && isset($_GET['ingredient_search'])) {
            $search_query = sanitize_text_field($_GET['ingredient_search']);
        }

        // Prépare la requête WP_Query
        $args = [
            'post_type'      => 'ingredient_fiche',
            'posts_per_page' => 10,
            'paged'          => $paged,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        // Si recherche → filtrer via meta_query + title
        if ($enable_search && $search_query !== '') {
            $args['meta_query'] = [
                'relation' => 'OR',
        
                // Recherche par référence
                [
                    'key'     => '_ingredient_reference',
                    'value'   => $search_query,
                    'compare' => 'LIKE'
                ],
        
                // Recherche par intitulé (champ meta)
                [
                    'key'     => '_ingredient_intitule',
                    'value'   => $search_query,
                    'compare' => 'LIKE'
                ],
            ];
        }

        $query = new WP_Query($args);

        // Buffer pour retourner le HTML proprement
        ob_start();

        echo '<div class="ingredient-listing">';

        // Formulaire de recherche
        if ($enable_search) {
            ?>
            <form method="GET" class="ingredient-search-form" style="margin-bottom:20px;">
                <input type="text" name="ingredient_search"
                    value="<?php echo esc_attr($search_query); ?>"
                    placeholder="Rechercher un ingrédient…" />
                <button type="submit">Rechercher</button>
            </form>
            <?php
        }

        // Résultats
        if ($query->have_posts()) {

            echo '<ul class="ingredients-items">';

            while ($query->have_posts()) {
                $query->the_post();

                $id = get_the_ID();

                $reference = get_post_meta($id, '_ingredient_reference', true);
                $intitule  = get_post_meta($id, '_ingredient_intitule', true);

                echo '<li class="ingredient-item" style="margin-bottom:15px;">';

                echo '<strong>' . esc_html($intitule ?: get_the_title()) . '</strong><br>';
                echo '<small>Référence : ' . esc_html($reference) . '</small>';

                echo '</li>';
            }

            echo '</ul>';

            // Pagination
            echo '<div class="ingredient-pagination">';
            echo paginate_links([
                'total'   => $query->max_num_pages,
                'current' => $paged,
            ]);
            echo '</div>';

        } else {
            echo '<p>Aucun ingrédient trouvé.</p>';
        }

        echo '</div>';

        wp_reset_postdata();

        return ob_get_clean();
    }
}
