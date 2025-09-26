<?php
/**
 * Classe de funções auxiliares
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Helper {
    
    /**
     * Formata CEP
     */
    public static function format_cep($cep) {
        $cep = preg_replace('/[^0-9]/', '', $cep);
        return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
    }
    
    /**
     * Formata telefone
     */
    public static function format_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 11) {
            return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7, 4);
        }
        return $phone;
    }
    
    /**
     * Calcula distância entre coordenadas
     */
    public static function calculate_distance($lat1, $lon1, $lat2, $lon2, $unit = 'km') {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        
        if ($unit == 'km') {
            return ($miles * 1.609344);
        } else {
            return $miles;
        }
    }
}