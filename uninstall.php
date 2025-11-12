<?php
// Sicherheitsnetz beim Entfernen: l??scht nur die eigene Optionsgruppe
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

delete_option( 'lotzwoo_options' );

