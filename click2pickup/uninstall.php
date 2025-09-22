<?php
// Se acesso direto, sai
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

// Limpa options (constantes fixas — ok manter literal)
$wpdb->query("
    DELETE FROM {$wpdb->options}
     WHERE option_name IN ('c2p_stock_ledger_db_version','c2p_init_scan_pending')
");

// Limpa usermeta (usa prepare)
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta}
      WHERE meta_key IN (%s,%s)",
    'c2p_email_invalid_','c2p_phone_invalid_'
) );

// Drop tabelas do plugin
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}c2p_stock_ledger" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}c2p_multi_stock" );
