<?php
/**
 * @var array<string, mixed> $initial_state
 * @var bool $table_exists
 */

if (!defined('ABSPATH')) {
    exit;
}

$initial_json = wp_json_encode($initial_state);
?>

<div class="lotzwoo_page lotzwoo_page--menu-planning">
            <details class="lotzwoo-image-management__intro lotzwoo-shortcode-intro">
        <summary><?php esc_html_e('Menüplanung', 'lotzapp-for-woocommerce'); ?></summary>
        <div class="lotzwoo-menu-planning__intro">
            <p>
                <?php esc_html_e('Verwalte hier die zukünftigen Menüpläne. Jede Tabellenzeile entspricht einem geplanten Veröffentlichungszeitpunkt, an dem die ausgewählten Produkte automatisch mit den jeweiligen "currentmenu_"-Schlagwörtern versehen werden.', 'lotzapp-for-woocommerce'); ?>
            </p>
            <p>
                <?php esc_html_e('Hinweis: Die Dropdowns zeigen nur Produkte aus der Kategorie, deren Slug dem Schlagwort (ohne Prefix "currentmenu_") entspricht.', 'lotzapp-for-woocommerce'); ?>
            </p>
        </div>
    </details>



    <div class="lotzwoo_page__section">
        <div class="lotzwoo-menu-planning">
            <?php if (!$table_exists) : ?>
                <div class="lotzwoo-menu-planning__notice">
                    <?php esc_html_e('Die Datenbanktabelle für die Menüplanung wurde noch nicht erstellt. Bitte speichere die LotzApp-Einstellungen erneut oder aktiviere das Plugin neu, um die Migration auszuführen.', 'lotzapp-for-woocommerce'); ?>
                </div>
            <?php endif; ?>

            <div
                class="lotzwoo-menu-planning__app"
                data-lotzwoo-menu-planning="1"
                data-initial="<?php echo esc_attr($initial_json ?: '{}'); ?>"
            >
                <div class="lotzwoo-menu-planning__loading">
                    <?php esc_html_e('Menüplanung wird geladen …', 'lotzapp-for-woocommerce'); ?>
                </div>
            </div>
        </div>
    </div>
</div>
