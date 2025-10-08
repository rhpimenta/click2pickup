<?php
/**
 * Click2Pickup - Cache Manager Unificado
 * 
 * ✅ v1.0.0: Cache com invalidação coordenada
 * ✅ Runtime cache + Object cache (Redis/Memcached)
 * ✅ Invalidação por evento (publish store, update stock)
 * 
 * @package Click2Pickup
 * @since 1.0.0
 * @author rhpimenta
 * @created 2025-01-08 13:43:28 UTC
 */

namespace C2P\Core;

if (!defined('ABSPATH')) exit;

final class Cache_Manager {
    
    /**
     * Cache runtime (durante request)
     */
    private static $runtime_cache = [];
    
    /**
     * TTLs padrão (segundos)
     */
    const TTL_STORES = 3600;      // 1 hora
    const TTL_STOCK = 300;        // 5 minutos
    const TTL_PRODUCT = 1800;     // 30 minutos
    const TTL_SHORT = 60;         // 1 minuto
    
    /**
     * Prefixo de chaves
     */
    const PREFIX = 'c2p:';
    
    /**
     * ✅ GET: Obtém cache (runtime primeiro, depois object cache)
     */
    public static function get(string $key, $default = null) {
        // 1. Runtime cache (mais rápido)
        if (isset(self::$runtime_cache[$key])) {
            return self::$runtime_cache[$key];
        }
        
        // 2. Object cache (Redis/Memcached se disponível)
        if (function_exists('wp_cache_get')) {
            $value = wp_cache_get($key, 'c2p');
            if ($value !== false) {
                // Popula runtime cache
                self::$runtime_cache[$key] = $value;
                return $value;
            }
        }
        
        return $default;
    }
    
    /**
     * ✅ SET: Define cache (runtime + object)
     */
    public static function set(string $key, $value, int $ttl = self::TTL_SHORT): bool {
        // Runtime cache
        self::$runtime_cache[$key] = $value;
        
        // Object cache
        if (function_exists('wp_cache_set')) {
            return wp_cache_set($key, $value, 'c2p', $ttl);
        }
        
        return true;
    }
    
    /**
     * ✅ DELETE: Remove cache específico
     */
    public static function delete(string $key): bool {
        unset(self::$runtime_cache[$key]);
        
        if (function_exists('wp_cache_delete')) {
            return wp_cache_delete($key, 'c2p');
        }
        
        return true;
    }
    
    /**
     * ✅ DELETE PATTERN: Invalida padrão (ex: c2p:stock:product:*)
     */
    public static function delete_pattern(string $pattern): void {
        // Runtime cache
        foreach (self::$runtime_cache as $key => $value) {
            if (fnmatch($pattern, $key)) {
                unset(self::$runtime_cache[$key]);
            }
        }
        
        // Object cache (só funciona com Redis/Memcached avançado)
        // Fallback: flush do grupo inteiro
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('c2p');
        }
    }
    
    /**
     * ✅ FLUSH: Limpa TODO o cache C2P
     */
    public static function flush(): void {
        self::$runtime_cache = [];
        
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('c2p');
        }
    }
    
    /* ====================================================================
     * CHAVES PADRÃO (centralizadas)
     * ================================================================== */
    
    public static function key_stores_published(): string {
        return self::PREFIX . 'stores:published';
    }
    
    public static function key_stock_product(int $product_id): string {
        return self::PREFIX . "stock:product:{$product_id}";
    }
    
    public static function key_stock_product_store(int $product_id, int $store_id): string {
        return self::PREFIX . "stock:product:{$product_id}:store:{$store_id}";
    }
    
    public static function key_location(int $location_id): string {
        return self::PREFIX . "location:{$location_id}";
    }
    
    /* ====================================================================
     * INVALIDAÇÃO POR EVENTO (hooks)
     * ================================================================== */
    
    /**
     * Invalida cache quando loja é publicada/despublicada
     */
    public static function on_store_publish(int $store_id): void {
        self::delete(self::key_stores_published());
        self::delete(self::key_location($store_id));
    }
    
    /**
     * Invalida cache quando estoque é atualizado
     */
    public static function on_stock_update(int $product_id, ?int $store_id = null): void {
        // Cache geral do produto
        self::delete(self::key_stock_product($product_id));
        
        if ($store_id) {
            // Cache específico produto + loja
            self::delete(self::key_stock_product_store($product_id, $store_id));
        } else {
            // Invalida TODAS as combinações deste produto
            self::delete_pattern(self::PREFIX . "stock:product:{$product_id}:*");
        }
    }
    
    /**
     * ✅ ESTATÍSTICAS (para debug)
     */
    public static function get_stats(): array {
        return [
            'runtime_keys' => count(self::$runtime_cache),
            'runtime_size' => strlen(serialize(self::$runtime_cache)),
            'object_cache_enabled' => function_exists('wp_cache_get'),
        ];
    }
}

// ✅ REGISTRA HOOKS DE INVALIDAÇÃO
add_action('publish_c2p_store', ['\C2P\Core\Cache_Manager', 'on_store_publish']);
add_action('trash_c2p_store', ['\C2P\Core\Cache_Manager', 'on_store_publish']);
add_action('c2p_stock_updated', ['\C2P\Core\Cache_Manager', 'on_stock_update'], 10, 2);