<?php

if (!defined('ABSPATH')) exit;

class Ingredient_Shortcodes {

    public function __construct() {
        add_shortcode('ingredients', [ $this, 'shortcode_ingredients' ]);
    }

    public function shortcode_ingredients($atts) { 

        // Attributs du shortcode
        $atts = shortcode_atts([
            'recherche'      => 'false',
            'posts_per_page' => 3,
        ], $atts);

        $enable_search = ($atts['recherche'] === 'true');
        $posts_per_page = intval($atts['posts_per_page']);

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
            'posts_per_page' => $posts_per_page,
            'paged'          => $paged,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        // Si recherche active
        if ($enable_search && $search_query !== '') {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => '_ingredient_reference',
                    'value'   => $search_query,
                    'compare' => 'LIKE'
                ],
                [
                    'key'     => '_ingredient_intitule',
                    'value'   => $search_query,
                    'compare' => 'LIKE'
                ],
                [
                    'key'     => '_ingredient_ingredients_fr',
                    'value'   => $search_query,
                    'compare' => 'LIKE'
                ],
            ];
        }

        $query = new WP_Query($args);

        ob_start();

        ?>
        
        <!-- ***** CSS RESPONSIVE ***** -->
        <style>
        .ingredient-table {
            width: 100%;
            border-collapse: collapse;
        }
        span.ingredient-label {
            display: none;
        }
        .ingredient-table th,
        .ingredient-table td {
            padding: 15px;
            text-align: left;
        }

        /* Alternance des couleurs desktop */
        .ingredient-row:nth-child(even) { background: #F5F5F8; }
        .ingredient-row:nth-child(odd) { background: #FFFFFF; }

        /* ======== RESPONSIVE MOBILE ======== */
        @media (max-width: 768px) {

            .ingredient-table thead { display: none; }

            .ingredient-row {
                display: block;
                margin-bottom: 20px;
                padding: 15px;
                background: #F5F5F8 !important;
                border-radius: 10px;
            }

            .ingredient-cell {
                display: block;
                padding: 8px 0;
            }

            .ingredient-label {
                font-weight: bold;
                color: #333;
                margin-bottom: 3px;
                display: block !important;
            }
        }
        </style>
        <?php

        echo '<div class="ingredient-listing" style="max-width:1200px;margin:0 auto;">';

        // FORMULAIRE DE RECHERCHE
        if ($enable_search) { ?>
            <form method="GET" style="display:flex;gap:10px;margin-bottom:25px;align-items:center;">
                <input type="text" name="ingredient_search"
                    value="<?php echo esc_attr($search_query); ?>"
                    placeholder="Rechercher une référence ou un intitulé..."
                    style="flex:1;padding:10px 15px;border:1px solid #ccc;border-radius:8px;" />

                <button type="submit" style="padding:10px 20px;border-radius:8px;background:#4B4BFF;color:#fff;border:none;cursor:pointer;">
                    Rechercher
                </button>

                <?php if (!empty($search_query)) : ?>
                    <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?'); ?>" 
                       style="padding:10px 20px;border-radius:8px;background:#EDEDED;text-decoration:none;color:#333;">
                       Liste complète
                    </a>
                <?php endif; ?>
            </form>
        <?php }

        // TABLEAU / CARDS MOBILE
        if ($query->have_posts()) {

            echo '<table class="ingredient-table">';

            // HEADER DESKTOP
            echo '
            <thead>
            <tr style="background:#fafafa;font-weight:bold;">
                <th>Référence</th>
                <th>Intitulé</th>
                <th>Ingrédients français</th>
            </tr>
            </thead>
            <tbody>
            ';

            while ($query->have_posts()) {
                $query->the_post();

                $id   = get_the_ID();
                $reference = get_post_meta($id, '_ingredient_reference', true);
                $intitule  = get_post_meta($id, '_ingredient_intitule', true);
                $ingredients_fr = get_post_meta($id, '_ingredient_ingredients_fr', true);

                echo '
                <tr class="ingredient-row">

                    <!-- Référence -->
                    <td class="ingredient-cell">
                        <span class="ingredient-label">Référence</span>
                        '.esc_html($reference).'
                    </td>

                    <!-- Intitulé -->
                    <td class="ingredient-cell">
                        <span class="ingredient-label">Intitulé</span>
                        '.esc_html($intitule).'
                    </td>

                    <!-- Ingrédients FR -->
                    <td class="ingredient-cell">
                        <span class="ingredient-label">Ingrédients français</span>
                        '.nl2br(esc_html($ingredients_fr)).'
                    </td>

                </tr>';
            }

            echo '</tbody></table>';

            // PAGINATION
            echo '<div style="margin-top:20px;text-align:center;">';
            echo paginate_links([
                'total'   => $query->max_num_pages,
                'current' => $paged,
                'prev_text' => '«',
                'next_text' => '»'
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
