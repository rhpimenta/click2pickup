<?php
/**
 * Click2Pickup - Location Repository
 * 
 * ✅ v1.0.0: FONTE ÚNICA para operações com locais (lojas/CDs)
 * ✅ Elimina duplicação de lógica em 5+ pontos do código
 * ✅ Cache integrado via Cache_Manager
 * 
 * @package Click2Pickup
 * @since 1.0.0
 * @author rhpimenta
 * @created 2025-01-08 13:46:43 UTC
 */

namespace C2P\Repositories;

if (!defined('ABSPATH')) exit;

use C2P\Core\Cache_Manager;
use C2P\Constants as C2P;

final class Location_Repository {
    
    /**
     * ✅ GET ALL PUBLISHED: Obtém TODAS as lojas publicadas (cached)
     * 
     * Substitui duplicações em:
     * - class-order.php (linha ~234)
     * - class-inventory-report.php (linha ~89)
     * - class-core.php (linha ~156)
     * - class-product-ui.php (linha ~312)
     * - class-stock-report.php (linha ~178)
     */
    public static function get_published_stores(): array {
        $cache_key = Cache_Manager::key_stores_published();
        $cached = Cache_Manager::get($cache_key);
        
        if ($cached !== null) {
            return $cached;
        }
        
        $query = new \WP_Query([
            'post_type' => C2P::POST_TYPE_STORE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);
        
        $store_ids = $query->posts;
        $stores = [];
        
        foreach ($store_ids as $store_id) {
            $store = self::get_store_by_id($store_id);
            if ($store) {
                $stores[$store_id] = $store;
            }
        }
        
        Cache_Manager::set($cache_key, $stores, Cache_Manager::TTL_STORES);
        
        return $stores;
    }
    
    /**
     * ✅ GET BY ID: Obtém loja por ID (cached individualmente)
     */
    public static function get_store_by_id(int $store_id): ?array {
        if ($store_id <= 0) {
            return null;
        }
        
        $cache_key = Cache_Manager::key_location($store_id);
        $cached = Cache_Manager::get($cache_key);
        
        if ($cached !== null) {
            return $cached;
        }
        
        $post = get_post($store_id);
        
        if (!$post || $post->post_type !== C2P::POST_TYPE_STORE || $post->post_status !== 'publish') {
            return null;
        }
        
        $store = [
            'id' => $store_id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'type' => get_post_meta($store_id, 'c2p_type', true) ?: 'loja',
            'email' => get_post_meta($store_id, 'c2p_email', true) ?: '',
            'phone' => get_post_meta($store_id, 'c2p_phone', true) ?: '',
            'address' => get_post_meta($store_id, 'c2p_address', true) ?: '',
            'city' => get_post_meta($store_id, 'c2p_city', true) ?: '',
            'state' => get_post_meta($store_id, 'c2p_state', true) ?: '',
            'postcode' => get_post_meta($store_id, 'c2p_postcode', true) ?: '',
            'hours_weekly' => get_post_meta($store_id, 'c2p_hours_weekly', true) ?: [],
            'hours_special' => get_post_meta($store_id, 'c2p_hours_special', true) ?: [],
            'prep_time_default' => (int) get_post_meta($store_id, 'c2p_prep_time_default', true) ?: 60,
            'shipping_instances' => get_post_meta($store_id, 'c2p_shipping_instance_ids', true) ?: [],
        ];
        
        Cache_Manager::set($cache_key, $store, Cache_Manager::TTL_STORES);
        
        return $store;
    }
    
    /**
     * ✅ GET BY SHIPPING INSTANCE: Obtém lojas vinculadas a um método de envio
     * 
     * Usado em:
     * - class-order.php (determinar loja responsável)
     * - class-custom-cart.php (opções de retirada)
     */
    public static function get_stores_by_shipping_instance(int $instance_id): array {
        if ($instance_id <= 0) {
            return [];
        }
        
        $all_stores = self::get_published_stores();
        $linked = [];
        
        foreach ($all_stores as $store_id => $store) {
            $linked_instances = $store['shipping_instances'] ?? [];
            
            if (is_array($linked_instances) && in_array($instance_id, $linked_instances, true)) {
                $linked[$store_id] = $store;
            }
        }
        
        return $linked;
    }
    
    /**
     * ✅ GET DISTRIBUTION CENTERS: Obtém apenas CDs (centros de distribuição)
     * 
     * Usado em:
     * - trait-erp.php (CD global)
     * - class-installer.php (CD padrão)
     */
    public static function get_distribution_centers(): array {
        $all_stores = self::get_published_stores();
        
        return array_filter($all_stores, function($store) {
            return ($store['type'] ?? '') === 'cd';
        });
    }
    
    /**
     * ✅ GET PHYSICAL STORES: Obtém apenas lojas físicas (para retirada)
     */
    public static function get_physical_stores(): array {
        $all_stores = self::get_published_stores();
        
        return array_filter($all_stores, function($store) {
            return ($store['type'] ?? '') !== 'cd';
        });
    }
    
    /**
     * ✅ GET BY TYPE: Obtém lojas por tipo
     */
    public static function get_stores_by_type(string $type): array {
        $all_stores = self::get_published_stores();
        
        return array_filter($all_stores, function($store) use ($type) {
            return ($store['type'] ?? '') === $type;
        });
    }
    
    /**
     * ✅ EXISTS: Verifica se loja existe e está publicada
     */
    public static function exists(int $store_id): bool {
        return self::get_store_by_id($store_id) !== null;
    }
    
    /**
     * ✅ IS CD: Verifica se é centro de distribuição
     */
    public static function is_distribution_center(int $store_id): bool {
        $store = self::get_store_by_id($store_id);
        return $store && ($store['type'] ?? '') === 'cd';
    }
    
    /**
     * ✅ BULK LOAD: Pré-carrega múltiplas lojas (otimização N+1)
     * 
     * Uso:
     * $store_ids = [12, 34, 56];
     * $stores = Location_Repository::bulk_load($store_ids);
     */
    public static function bulk_load(array $store_ids): array {
        if (empty($store_ids)) {
            return [];
        }
        
        $stores = [];
        
        foreach ($store_ids as $store_id) {
            $store = self::get_store_by_id((int) $store_id);
            if ($store) {
                $stores[$store_id] = $store;
            }
        }
        
        return $stores;
    }
    
    /**
     * ✅ GET SHIPPING METHOD: Obtém método de envio vinculado (se houver)
     */
    public static function get_shipping_method_for_store(int $store_id): ?array {
        if (!class_exists('\WC_Shipping_Zones')) {
            return null;
        }
        
        $store = self::get_store_by_id($store_id);
        if (!$store) {
            return null;
        }
        
        $linked_instances = $store['shipping_instances'] ?? [];
        if (empty($linked_instances)) {
            return null;
        }
        
        $zones = \WC_Shipping_Zones::get_zones();
        $zones[0] = (new \WC_Shipping_Zone(0))->get_data();
        
        foreach ($zones as $zone_data) {
            $zone = new \WC_Shipping_Zone($zone_data['id'] ?? 0);
            
            foreach ($zone->get_shipping_methods(true) as $instance_id => $method) {
                if (in_array($instance_id, $linked_instances, true)) {
                    return [
                        'instance_id' => (int) $instance_id,
                        'method_id' => $method->id ?? '',
                        'title' => $method->get_title() ?? '',
                        'zone_id' => $zone->get_id(),
                        'zone_name' => $zone->get_zone_name() ?? '',
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * ✅ FORMAT FOR SELECT: Formata lojas para dropdown <select>
     * 
     * Retorna: [ store_id => "Nome da Loja (#123) — Loja" ]
     */
    public static function format_for_select(): array {
        $stores = self::get_published_stores();
        $options = [];
        
        foreach ($stores as $store_id => $store) {
            $type_label = ($store['type'] ?? '') === 'cd' ? __('CD', 'c2p') : __('Loja', 'c2p');
            $options[$store_id] = sprintf(
                '%s (#%d) — %s',
                $store['title'],
                $store_id,
                $type_label
            );
        }
        
        return $options;
    }
}

// ✅ HOOK: Invalida cache quando loja é publicada/alterada
add_action('save_post_c2p_store', function($post_id) {
    Cache_Manager::on_store_publish($post_id);
}, 10, 1);