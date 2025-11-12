=== LotzApp for WooCommerce ===
Contributors: yourname
Tags: woocommerce, pricing, estimated, cart, buffer
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.1
WC requires at least: 7.0
WC tested up to: 9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


== Description ==
LotzApp for WooCommerce erweitert Standard-Shops um Ca.-Preislogik sowie Admin-Tools, die Datenpflege beschleunigen.

Hauptfunktionen:
* "Ca."-Artikel - Kennzeichnung (Preispraefixe, zeigt Hinweise auf Produkt- sowie Checkout-Seiten, Buffer-Artikel für Spielraum bei Capture)
* Zentrale tabellarische Produktbilderverwaltung
* Menüplaner mit automatischer Regelung der Verfügbarkeit von WooCommerce-Produkten 
* sperrt kritische WooCommerce-Felder zur Qualitaetssicherung
* Versand des finalen Bestellabschluss-Emails mit Trackinglink und Rechnung aus LotzApp anreichern (Shortcode `[lotzwoo_tracking_links]`, Metafelder `lotzwoo_tracking_url` und `lotzwoo_invoice_url`)

Trackinglinks erscheinen automatisch in der WooCommerce-E-Mail `customer_completed_order`, sobald der neue Tab *Emails* in den Einstellungen aktiviert und durch LotzApp bzw. das ERP-System befuellt wird.


== Installation ==
1. Ordner `lotzapp-for-woocommerce` in `/wp-content/plugins/` hochladen
2. In WP-Admin unter *Plugins* aktivieren
3. Einstellungen unter *WooCommerce* -> *LotzApp* vornehmen


== Changelog ==
= 0.1.0 =
* Initialer Skeleton-Release: Admin-Page, Optionen, Uninstall-Routine
