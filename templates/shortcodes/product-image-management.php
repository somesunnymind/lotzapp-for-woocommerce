<?php
/**
 * @var array<int, array<string, mixed>> $items
 * @var Lotzwoo\Services\Product_Media_Service $media_service
 */

if (!defined('ABSPATH')) {
    exit;
}

$group_order   = 0;
$last_group_id = null;
?>

<div class="lotzwoo_page lotzwoo_page--image-management">
            <details class="lotzwoo-image-management__intro lotzwoo-shortcode-intro">
        <summary><?php esc_html_e('Zentrale Verwaltung der Produkt- & Produktgaleriebilder', 'lotzapp-for-woocommerce'); ?></summary>
        <ul>
            <li><b><u><?php esc_html_e('Hinzufügen', 'lotzapp-for-woocommerce'); ?></u></b> <?php esc_html_e('Über Klick in die weißen Rahmen (öffnet die WordPress-Mediathek) oder per Drag & Drop direkt auf dem jeweiligen Feld ablegen.', 'lotzapp-for-woocommerce'); ?></li>
            <li><b><u><?php esc_html_e('Entfernen', 'lotzapp-for-woocommerce'); ?></u></b> <?php esc_html_e('Mit Klick auf das Mülleimersymbol (Bild verbleibt in der Mediathek).', 'lotzapp-for-woocommerce'); ?></li>
            <li><b><u><?php esc_html_e('Sortieren der Spalten', 'lotzapp-for-woocommerce'); ?></u></b> <?php esc_html_e('Aufsteigend / absteigend mit Klick auf die Spaltenüberschrift.', 'lotzapp-for-woocommerce'); ?></li>
            <li><b><u><?php esc_html_e('Sortieren der Galeriebilder', 'lotzapp-for-woocommerce'); ?></u></b> <?php esc_html_e('Per Drag & Drop – die Reihenfolge definiert die Anzeige im Produktbilder-Slider.', 'lotzapp-for-woocommerce'); ?></li>
            <li><b><u><?php esc_html_e('Komprimieren', 'lotzapp-for-woocommerce'); ?></u></b> <?php esc_html_e('Erfolgt automatisch – beim Hochladen werden große Bilder auf 2000px Seitenlänge verkleinert und in WEBP umgewandelt.', 'lotzapp-for-woocommerce'); ?></li>
        </ul>
    </details>



    <div class="lotzwoo_page__section lotzwoo-image-management__section lotzwoo-image-management__section--table">
        <div class="lotzwoo_div lotzwoo-image-management">
            <table class="lotzwoo-image-management__table">
                <thead>
                <tr>
                    <th scope="col" class="lotzwoo-image-management__col lotzwoo-image-management__col--name">
                        <button type="button" class="lotzwoo-image-management__sort" data-sort-key="name">
                            <span><?php esc_html_e('Produkt / Variante', 'lotzapp-for-woocommerce'); ?></span>
                            <span class="lotzwoo-image-management__sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th scope="col" class="lotzwoo-image-management__col lotzwoo-image-management__col--type">
                        <button type="button" class="lotzwoo-image-management__sort" data-sort-key="type">
                            <span><?php esc_html_e('Produkttyp', 'lotzapp-for-woocommerce'); ?></span>
                            <span class="lotzwoo-image-management__sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th scope="col" class="lotzwoo-image-management__col lotzwoo-image-management__col--featured">
                        <button type="button" class="lotzwoo-image-management__sort" data-sort-key="featured">
                            <span><?php esc_html_e('Produktbild', 'lotzapp-for-woocommerce'); ?></span>
                            <span class="lotzwoo-image-management__sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                    <th scope="col" class="lotzwoo-image-management__col lotzwoo-image-management__col--gallery">
                        <button type="button" class="lotzwoo-image-management__sort" data-sort-key="gallery">
                            <span><?php esc_html_e('Galeriebilder', 'lotzapp-for-woocommerce'); ?></span>
                            <span class="lotzwoo-image-management__sort-indicator" aria-hidden="true"></span>
                        </button>
                    </th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item) :
                    /** @var \WC_Product $product */
                    $product        = $item['product'];
                    $is_variation   = $product instanceof \WC_Product_Variation;
                    $gallery_ids    = $is_variation ? [] : array_map('intval', $product->get_gallery_image_ids());
                    $gallery_attr   = $gallery_ids ? implode(',', $gallery_ids) : '';
                    $featured_id    = (int) $product->get_image_id();
                    $interactive    = 'lotzwoo-image-management__cell lotzwoo-image-management__cell--interactive';
                    $gallery_cell   = 'lotzwoo-image-management__cell lotzwoo-image-management__cell--gallery';
                    $group_id       = isset($item['group_id']) ? (int) $item['group_id'] : (int) $item['id'];

                    if ($last_group_id !== $group_id) {
                        $group_order++;
                        $last_group_id = $group_id;
                    }

                    $row_classes = ['lotzwoo-image-management__row'];
                    if ($is_variation) {
                        $row_classes[] = 'lotzwoo-image-management__row--variation';
                    }

                    $featured_count = $featured_id > 0 ? 1 : 0;
                    $gallery_count  = $is_variation ? 0 : count($gallery_ids);
                    ?>
                    <tr
                        class="<?php echo esc_attr(implode(' ', $row_classes)); ?>"
                        data-group-id="<?php echo esc_attr($group_id); ?>"
                        data-group-order="<?php echo esc_attr($group_order); ?>"
                        data-is-parent="<?php echo esc_attr($item['is_parent'] ? '1' : '0'); ?>"
                        data-name="<?php echo esc_attr($item['label']); ?>"
                        data-type="<?php echo esc_attr($item['type_label']); ?>"
                        data-featured-count="<?php echo esc_attr($featured_count); ?>"
                        data-gallery-count="<?php echo esc_attr($gallery_count); ?>"
                    >
                        <td class="lotzwoo-image-management__cell lotzwoo-image-management__cell--name">
                            <?php echo esc_html($item['label']); ?>
                        </td>
                        <td class="lotzwoo-image-management__cell lotzwoo-image-management__cell--type">
                            <?php echo esc_html($item['type_label']); ?>
                        </td>
                        <td
                            class="<?php echo esc_attr($interactive . ' lotzwoo-image-management__cell--featured'); ?>"
                            data-field="featured"
                            data-object-id="<?php echo esc_attr($item['id']); ?>"
                            data-object-type="<?php echo esc_attr($item['object_type']); ?>"
                            data-image-id="<?php echo esc_attr($featured_id); ?>"
                            role="button"
                            tabindex="0"
                        >
                            <div class="lotzwoo-image-management__content">
                                <?php echo $media_service->render_featured_cell_content($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </td>
                        <td
                            class="<?php echo esc_attr($gallery_cell . ($is_variation ? ' is-disabled lotzwoo-image-management__cell--variation' : ' lotzwoo-image-management__cell--interactive')); ?>"
                            <?php if ($is_variation) : ?>
                                data-disabled="true"
                            <?php else : ?>
                                data-field="gallery"
                                data-object-id="<?php echo esc_attr($item['id']); ?>"
                                data-object-type="<?php echo esc_attr($item['object_type']); ?>"
                                data-gallery-ids="<?php echo esc_attr($gallery_attr); ?>"
                                role="button"
                                tabindex="0"
                            <?php endif; ?>
                        >
                            <div class="lotzwoo-image-management__content">
                                <?php echo $media_service->render_gallery_cell_content($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
