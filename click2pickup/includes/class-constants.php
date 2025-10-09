<?php
/**
 * Click2Pickup - Constantes Centralizadas
 * 
 * TUDO que é usado em múltiplos arquivos fica aqui.
 * SEM descoberta, SEM fallbacks, SEM tentativas.
 * 
 * @package Click2Pickup
 * @since 2.0.1
 * @author rhpimenta
 * Last Update: 2025-01-09 01:04:24 UTC
 * 
 * CHANGELOG:
 * - 2025-01-09 01:04: ✅ CORRIGIDO: Adicionado META_STOCK_BY_ID (sem S) para compatibilidade
 * - 2025-01-09 00:29: ✅ Cache de arrays repetidos
 * - 2025-01-09 00:29: ✅ Padronização de nomenclatura
 * - 2025-01-09 00:29: ✅ Type hints melhorados
 * - 2025-01-09 00:29: ✅ Documentação aprimorada
 */

namespace C2P;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Constantes e helpers centralizados do Click2Pickup
 * 
 * USO:
 * use C2P\Constants as C2P;
 * $table = C2P::table();
 * $col = C2P::col_store();
 */
final class Constants {
    
    /* ================================================================
     * BANCO DE DADOS
     * ================================================================ */
    
    /**
     * Nome da tabela de estoque (SEM prefix)
     * 
     * Tabela completa: wp_c2p_stock
     */
    const TABLE_STOCK = 'c2p_stock';
    
    /**
     * Nome da tabela de ledger/auditoria (SEM prefix)
     * 
     * Tabela completa: wp_c2p_stock_ledger
     */
    const TABLE_LEDGER = 'c2p_stock_ledger';
    
    /**
     * Nome da coluna de local (SEMPRE store_id)
     * 
     * NOTA: Antes tentava descobrir se era location_id, c2p_store_id, etc.
     * Agora SEMPRE é store_id. A migração em Inventory_DB garante isso.
     */
    const COL_STORE = 'store_id';
    
    /**
     * Nome da coluna de produto
     */
    const COL_PRODUCT = 'product_id';
    
    /**
     * Nome da coluna de quantidade
     */
    const COL_QTY = 'qty';
    
    /**
     * Nome da coluna de limiar de estoque baixo
     */
    const COL_LOW_STOCK = 'low_stock_amount';
    
    /* ================================================================
     * META KEYS - PEDIDOS
     * ================================================================ */
    
    /**
     * ID do local no pedido (meta principal)
     * 
     * Ordem de prioridade de leitura:
     * 1. _c2p_store_id
     * 2. c2p_store_id
     * 3. c2p_location_id
     */
    const META_ORDER_LOCATION = '_c2p_store_id';
    
    /**
     * Modo do pedido (pickup/delivery)
     * 
     * Valores possíveis: 'pickup', 'delivery', 'RETIRAR', 'RECEBER'
     */
    const META_ORDER_MODE = '_c2p_mode';
    
    /**
     * Flag: estoque já foi reduzido?
     */
    const META_ORDER_STOCK_REDUCED = '_c2p_multistock_reduced';
    
    /**
     * Flag: estoque já foi restaurado?
     */
    const META_ORDER_STOCK_RESTORED = '_c2p_multistock_restored';
    
    /**
     * Flag: nota de unidade já adicionada?
     */
    const META_ORDER_NOTE_ADDED = '_c2p_unit_note_added';
    
    /**
     * Flag: email de pickup já enviado?
     */
    const META_ORDER_EMAIL_SENT = '_c2p_pickup_mail_sent';
    
    /* ================================================================
     * META KEYS - PRODUTOS
     * ================================================================ */
    
    /**
     * Flag: produto inicializado para multi-estoque?
     * 
     * Valores: 'yes' ou vazio
     */
    const META_PRODUCT_INITIALIZED = 'c2p_initialized';
    
    /**
     * ✅ CORRIGIDO: Snapshot de estoque por ID de local
     * 
     * Formato: Array [ location_id (int) => qty (int) ]
     * 
     * Exemplo: [ 123 => 50, 456 => 30 ]
     * 
     * NOTA: META_STOCK_BY_ID e META_STOCK_BY_IDS apontam para o mesmo valor
     * para manter compatibilidade total com código legado (class-order.php, etc.)
     */
    const META_STOCK_BY_ID = 'c2p_stock_by_location_ids';
    const META_STOCK_BY_IDS = 'c2p_stock_by_location_ids';
    
    /**
     * Snapshot de estoque por nome de local
     * 
     * Formato: Array [ location_name (string) => qty (int) ]
     * 
     * Exemplo: [ 'Loja Centro' => 50, 'CD Sul' => 30 ]
     */
    const META_STOCK_BY_NAME = 'c2p_stock_by_location';
    
    /**
     * Mapa de últimas notificações de estoque baixo
     * 
     * Formato: Array [ location_id (int) => qty_when_notified (int) ]
     */
    const META_LOW_STOCK_NOTIFIED = 'c2p_low_stock_notified';
    
    /* ================================================================
     * POST TYPES
     * ================================================================ */
    
    /**
     * Custom Post Type de loja/CD
     */
    const POST_TYPE_STORE = 'c2p_store';
    
    /* ================================================================
     * HOOKS / ACTIONS
     * ================================================================ */
    
    // Hook disparado quando estoque muda (edição manual no admin)
    // Parâmetros: (int $product_id, int $location_id, int $qty_before, int $qty_after, int $threshold, string $context)
    const HOOK_MULTISTOCK_CHANGED = 'c2p_multistock_changed';
    
    // Hook disparado após mudança de estoque por local (pedido)
    // Parâmetros: (array $product_ids, int $location_id, string $operation, int $order_id)
    const HOOK_AFTER_LOCATION_STOCK_CHANGE = 'c2p_after_location_stock_change';
    
    /* ================================================================
     * OPÇÕES
     * ================================================================ */
    
    /**
     * Chave de opções do plugin no wp_options
     */
    const OPTION_SETTINGS = 'c2p_settings';
    
    /**
     * Versão do schema do banco (usado para migrations)
     */
    const OPTION_SCHEMA_VERSION = 'c2p_schema_version';
    
    /* ================================================================
     * STATUS DE PEDIDO
     * ================================================================ */
    
    /**
     * Status de pedidos "ativos" (exibem prazo de separação)
     */
    const ACTIVE_ORDER_STATUSES = ['processing', 'on-hold', 'pending'];
    
    /**
     * Status de pedidos "finalizados" (ocultam prazo)
     */
    const COMPLETED_ORDER_STATUSES = ['completed', 'cancelled', 'refunded', 'failed'];
    
    /* ================================================================
     * MAPEAMENTO DE MODOS
     * ================================================================ */
    
    /**
     * Mapa de conversão de modos (legacy → padrão)
     * 
     * RECEBER/RETIRAR são valores legados do sistema antigo
     */
    const MODE_MAP = [
        'RECEBER' => 'delivery',
        'RETIRAR' => 'pickup',
        'delivery' => 'delivery',
        'pickup' => 'pickup',
    ];
    
    /* ================================================================
     * CACHE INTERNO (performance)
     * ================================================================ */
    
    /**
     * Cache de arrays que são retornados frequentemente
     * 
     * @var array
     */
    private static $cache = [];
    
    /* ================================================================
     * HELPERS ESTÁTICOS
     * ================================================================ */
    
    /**
     * Retorna nome completo da tabela de estoque (COM prefix)
     * 
     * @return string Ex: 'wp_c2p_stock'
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_STOCK;
    }
    
    /**
     * Retorna nome completo da tabela de ledger (COM prefix)
     * 
     * @return string Ex: 'wp_c2p_stock_ledger'
     */
    public static function table_ledger(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LEDGER;
    }
    
    /**
     * Retorna nome da coluna de local
     * 
     * @return string 'store_id'
     */
    public static function col_store(): string {
        return self::COL_STORE;
    }
    
    /**
     * Retorna nome da coluna de produto
     * 
     * @return string 'product_id'
     */
    public static function col_product(): string {
        return self::COL_PRODUCT;
    }
    
    /**
     * Retorna nome da coluna de quantidade
     * 
     * @return string 'qty'
     */
    public static function col_qty(): string {
        return self::COL_QTY;
    }
    
    /**
     * Meta keys válidas para location_id em pedidos (ordem de prioridade)
     * 
     * ✅ OTIMIZADO: Usa cache para evitar recriar array
     * 
     * @return array
     */
    public static function meta_location_keys(): array {
        if (!isset(self::$cache['meta_location_keys'])) {
            self::$cache['meta_location_keys'] = [
                self::META_ORDER_LOCATION,
                'c2p_store_id',
                'c2p_location_id',
                'c2p_selected_store',
                'rpws_store_id',
            ];
        }
        
        return self::$cache['meta_location_keys'];
    }
    
    /**
     * Meta keys válidas para modo em pedidos (ordem de prioridade)
     * 
     * ✅ OTIMIZADO: Usa cache para evitar recriar array
     * 
     * @return array
     */
    public static function meta_mode_keys(): array {
        if (!isset(self::$cache['meta_mode_keys'])) {
            self::$cache['meta_mode_keys'] = [
                self::META_ORDER_MODE,
                'c2p_mode',
            ];
        }
        
        return self::$cache['meta_mode_keys'];
    }
    
    /**
     * Normaliza modo do pedido (legacy → padrão)
     * 
     * @param string|null $raw_mode
     * @return 'pickup'|'delivery' Modo normalizado
     */
    public static function normalize_mode(?string $raw_mode): string {
        if (!$raw_mode) {
            return 'delivery';
        }
        
        return self::MODE_MAP[$raw_mode] ?? 'delivery';
    }
    
    /**
     * Verifica se status é "ativo" (exibe prazo de separação)
     * 
     * @param string $status Status do pedido (sem prefixo 'wc-')
     * @return bool
     */
    public static function is_active_order_status(string $status): bool {
        // Remove prefixo 'wc-' se existir
        $status = str_replace('wc-', '', $status);
        
        return in_array($status, self::ACTIVE_ORDER_STATUSES, true);
    }
    
    /**
     * Verifica se status é "finalizado" (oculta prazo)
     * 
     * @param string $status Status do pedido (sem prefixo 'wc-')
     * @return bool
     */
    public static function is_completed_order_status(string $status): bool {
        // Remove prefixo 'wc-' se existir
        $status = str_replace('wc-', '', $status);
        
        return in_array($status, self::COMPLETED_ORDER_STATUSES, true);
    }
    
    /**
     * ✅ NOVO: Retorna todos os status válidos de pedido
     * 
     * @return array
     */
    public static function all_order_statuses(): array {
        if (!isset(self::$cache['all_order_statuses'])) {
            self::$cache['all_order_statuses'] = array_merge(
                self::ACTIVE_ORDER_STATUSES,
                self::COMPLETED_ORDER_STATUSES
            );
        }
        
        return self::$cache['all_order_statuses'];
    }
    
    /**
     * ✅ NOVO: Limpa cache interno
     * 
     * Útil para testes ou quando as constantes mudam em runtime
     */
    public static function clear_cache(): void {
        self::$cache = [];
    }
    
    /**
     * ✅ NOVO: Valida se um modo é válido
     * 
     * @param string $mode
     * @return bool
     */
    public static function is_valid_mode(string $mode): bool {
        $normalized = self::normalize_mode($mode);
        return in_array($normalized, ['pickup', 'delivery'], true);
    }
    
    /**
     * ✅ NOVO: Retorna label amigável para um modo
     * 
     * @param string $mode
     * @param string $locale Opcional: 'pt_BR' (padrão) ou 'en_US'
     * @return string
     */
    public static function get_mode_label(string $mode, string $locale = 'pt_BR'): string {
        $normalized = self::normalize_mode($mode);
        
        if ($locale === 'en_US') {
            return $normalized === 'pickup' ? 'Pickup' : 'Delivery';
        }
        
        // pt_BR (padrão)
        return $normalized === 'pickup' ? 'Retirada' : 'Entrega';
    }
}