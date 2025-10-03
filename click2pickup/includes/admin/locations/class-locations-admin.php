<?php
namespace C2P;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin de Locais de Estoque (CPT c2p_store)
 * - Mantém layout/UX completo (metabox principal, horários, dias especiais, trava/destrava, duplicar, etc.)
 * - Tipo do local: salva em c2p_type ('loja'|'cd') e mantém legados (c2p_store_type/c2p_is_cd) em sincronia
 * - Exposição do vínculo de frete: lista instâncias por zona e permite travar/destravar ao já haver pedidos
 * - Fornece mapeamento instance_id → location_id para o sincronizador de estoque (\C2P\Order_Stock_Sync)
 * - Dispara (re)scan ao salvar/publicar local e limpa/reindexa ao excluir permanentemente
 */

class Locations_Admin {
    private static $instance;
    public static function instance(): self {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        if (class_exists(\C2P\CPT_Store::class)) { \C2P\CPT_Store::instance(); }
        if (class_exists(\C2P\Store_Shipping_Link::class)) { \C2P\Store_Shipping_Link::instance(); }
    }
}

// === Begin CPT_Store ===
if ( ! defined( 'ABSPATH' ) ) exit;

class CPT_Store {
    private static $instance;

    public static function instance(): CPT_Store {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ], 0 );
        add_action( 'add_meta_boxes', [ $this, 'metaboxes' ] );
        add_action( 'save_post_c2p_store', [ $this, 'save_meta' ] );

        add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
        add_action( 'admin_notices', [ $this, 'maybe_field_notices' ] );

        add_filter( 'parent_file', [ $this, 'menu_highlight_parent' ] );
        add_filter( 'submenu_file', [ $this, 'menu_highlight_sub' ] );

        add_filter( 'manage_c2p_store_posts_columns', [ $this, 'columns' ] );
        add_action( 'manage_c2p_store_posts_custom_column', [ $this, 'columns_content' ], 10, 2 );

        add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
        add_action( 'admin_post_c2p_duplicate_store', [ $this, 'duplicate_store' ] );

        // Lifecycle do CPT:
        add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ] );          // excluir permanentemente
        add_action( 'transition_post_status', [ $this, 'on_transition_status' ], 10, 3 );// publicar -> disparar scan
        // NEW: dispara scan ao salvar (inclusive updates de um local já publicado)
        add_action( 'save_post_c2p_store', [ $this, 'maybe_enqueue_scan_on_save' ], 9999, 3 );
    }

    public function register_cpt() {
        $labels = [
            'name'               => __( 'Locais de Estoque', 'c2p' ),
            'singular_name'      => __( 'Local de Estoque', 'c2p' ),
            'add_new_item'       => __( 'Adicionar novo Local de Estoque', 'c2p' ),
            'edit_item'          => __( 'Editar Local de Estoque', 'c2p' ),
            'menu_name'          => __( 'Locais de Estoque', 'c2p' ),
            'name_admin_bar'     => __( 'Local de Estoque', 'c2p' ),
        ];
        register_post_type( 'c2p_store', [
            'labels'        => $labels,
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => false,
            'supports'      => [ 'title' ],
        ] );
    }

    /** Lê todas as metas (type normalizado APENAS a partir de c2p_type) */
    public static function get_meta_all( int $post_id ): array {
        // c2p_type: 'cd' | 'loja'  -> normaliza p/ 'dc' | 'store' (apenas para exibição)
        $c2p_type = get_post_meta( $post_id, 'c2p_type', true );
        $type_norm = ($c2p_type === 'cd') ? 'dc' : 'store';

        return [
            'type'           => $type_norm,

            'address_1'      => get_post_meta($post_id,'c2p_address_1',true),
            'address_2'      => get_post_meta($post_id,'c2p_address_2',true),
            'city'           => get_post_meta($post_id,'c2p_city',true),
            'country'        => get_post_meta($post_id,'c2p_country',true) ?: ( function_exists('wc_get_base_location') ? (wc_get_base_location()['country'] ?? '') : '' ),
            'state'          => get_post_meta($post_id,'c2p_state',true)   ?: ( function_exists('wc_get_base_location') ? (wc_get_base_location()['state']   ?? '') : '' ),
            'postcode'       => get_post_meta($post_id,'c2p_postcode',true),

            'phone'          => get_post_meta($post_id,'c2p_phone',true),
            'email'          => get_post_meta($post_id,'c2p_email',true),

            'photo_id'       => (int) get_post_meta($post_id,'c2p_photo_id',true),

            'hours_weekly'   => get_post_meta($post_id,'c2p_hours_weekly',true),
            'hours_special'  => get_post_meta($post_id,'c2p_hours_special',true),
        ];
    }

    public function metaboxes() {
        add_meta_box( 'c2p_store_main', __( 'Dados do Local de Estoque', 'c2p' ), [ $this, 'box_main' ], 'c2p_store', 'normal', 'high' );
        add_meta_box( 'c2p_store_hours', __( 'Horários', 'c2p' ), [ $this, 'box_hours' ], 'c2p_store', 'normal', 'default' );
        add_meta_box( 'c2p_store_special', __( 'Dias Especiais', 'c2p' ), [ $this, 'box_special' ], 'c2p_store', 'normal', 'default' );
    }

    /** Enfileira assets APENAS nas telas do CPT */
    public function assets( $hook ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'c2p_store' ) return;

        wp_enqueue_media();
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', [], '1.13.2' );

        // Caminhos relativos a este arquivo (includes/admin/locations/assets/)
        wp_enqueue_style( 'c2p-locations-admin', plugins_url('assets/admin.css', __FILE__), [], '1.0.1' );

        wp_register_script(
            'c2p-locations-admin',
            plugins_url('assets/admin.js', __FILE__),
            [ 'jquery', 'jquery-ui-datepicker' ],
            '1.0.2',
            true
        );

        $countries = [];
        $states_by_country = [];
        if ( function_exists( 'wc' ) && isset( wc()->countries ) ) {
            $countries = wc()->countries->get_countries();
            $states_by_country = wc()->countries->get_states();
        }

        wp_localize_script( 'c2p-locations-admin', 'C2P_LOC', [
            'i18n'      => [
                'media_title'  => __( 'Selecionar imagem', 'c2p' ),
                'media_button' => __( 'Usar imagem', 'c2p' ),
                'confirmUnlock'=> __( 'Marque a confirmação antes de destravar.', 'c2p' ),
                'invalidPhone' => __( 'Telefone inválido. Use (xx)xxxxx-xxxx ou (xx)xxxx-xxxx.', 'c2p' ),
                'invalidEmail' => __( 'E-mail inválido. Corrija para salvar.', 'c2p' ),
            ],
            'countries' => (array) $countries,
            'states'    => (array) $states_by_country,
        ] );

        wp_enqueue_script( 'c2p-locations-admin' );
    }

    /** Avisos de validação (telefone/e-mail) via admin_notices */
    public function maybe_field_notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'c2p_store' ) return;

        $post_id = 0;
        if ( isset($_GET['post']) ) $post_id = (int) $_GET['post'];
        elseif ( isset($_POST['post_ID']) ) $post_id = (int) $_POST['post_ID'];
        if ( ! $post_id ) return;

        $email_invalid = get_transient( 'c2p_email_invalid_'.$post_id );
        if ( $email_invalid ) {
            delete_transient( 'c2p_email_invalid_'.$post_id );
            echo '<div class="notice notice-error"><p>'.esc_html($email_invalid).'</p></div>';
        }
        $phone_invalid = get_transient( 'c2p_phone_invalid_'.$post_id );
        if ( $phone_invalid ) {
            delete_transient( 'c2p_phone_invalid_'.$post_id );
            echo '<div class="notice notice-error"><p>'.esc_html($phone_invalid).'</p></div>';
        }
    }

    public function box_main( \WP_Post $post ) {
        $m = self::get_meta_all( $post->ID );
        wp_nonce_field( 'c2p_store_save', 'c2p_store_nonce' );

        // Valor preferencial cd/loja para pintar os radios (SEM fallback)
        $c2p_type = get_post_meta( $post->ID, 'c2p_type', true ); // 'cd' | 'loja' | ''
        if ( $c2p_type !== 'cd' && $c2p_type !== 'loja' ) {
            $c2p_type = 'loja'; // padrão visual (equivale a 'store')
        }

        echo '<table class="form-table c2p-main-table">';

        // === Tipo do Local (FUNCIONAL: c2p_type, sem fallback) ===
        echo '<tr><th>'.esc_html__('Tipo do Local', 'c2p').'</th><td>';
        // nonce específico para o tipo
        wp_nonce_field('c2p_store_type_nonce', 'c2p_store_type_nonce');
        echo '<p style="margin:0 0 6px;">';
        echo '<label><input type="radio" name="c2p_type" value="loja" '.checked($c2p_type,'loja',false).'/> '.esc_html__('Loja (retirada)', 'c2p').'</label>';
        echo ' &nbsp; ';
        echo '<label><input type="radio" name="c2p_type" value="cd" '.checked($c2p_type,'cd',false).'/> '.esc_html__('Centro de Distribuição (envio)', 'c2p').'</label>';
        echo '</p>';
        echo '<p class="description" style="margin-top:6px;">'.esc_html__('Define como o local será exibido no Passo 2: CDs em “Receber” e Lojas em “Retirar”.','c2p').'</p>';
        echo '</td></tr>';

        // Endereço (padrão WooCommerce)
        $countries = ( function_exists('wc') && isset( wc()->countries ) ) ? wc()->countries->get_countries() : [];
        $states    = ( function_exists('wc') && isset( wc()->countries ) ) ? wc()->countries->get_states()    : [];

        echo '<tr><th>'.esc_html__('Endereço', 'c2p').'</th><td>';
        echo '<p><input type="text" name="c2p_address_1" value="'.esc_attr($m['address_1']).'" class="regular-text" placeholder="'.esc_attr__('Endereço linha 1','c2p').'" /></p>';
        echo '<p><input type="text" name="c2p_address_2" value="'.esc_attr($m['address_2']).'" class="regular-text" placeholder="'.esc_attr__('Endereço linha 2','c2p').'" /></p>';
        echo '<p><input type="text" name="c2p_city" value="'.esc_attr($m['city']).'" class="regular-text" placeholder="'.esc_attr__('Cidade','c2p').'" /></p>';

        $sel_country = get_post_meta( $post->ID, 'c2p_country', true ) ?: '';
        $sel_state   = get_post_meta( $post->ID, 'c2p_state', true )   ?: '';
        echo '<p class="c2p-country-state">';
        echo '<label style="display:block;margin-bottom:4px;">'.esc_html__('País / estado','c2p').'</label>';
        echo '<select name="c2p_country" id="c2p_country" class="c2p-country">';
        echo '<option value="">'.esc_html__('Selecione o país','c2p').'</option>';
        foreach ( (array) $countries as $code => $label ) {
            echo '<option value="'.esc_attr($code).'" '.selected($sel_country, $code, false).'>'.esc_html($label).'</option>';
        }
        echo '</select> ';
        $has_states = ! empty( $sel_country ) && ! empty( $states[ $sel_country ] );
        echo '<span id="c2p_state_wrap">';
        if ( $has_states ) {
            echo '<select name="c2p_state" id="c2p_state" class="c2p-state">';
            echo '<option value="">'.esc_html__('Selecione o estado','c2p').'</option>';
            foreach ( (array) $states[ $sel_country ] as $scode => $slabel ) {
                echo '<option value="'.esc_attr($scode).'" '.selected($sel_state, $scode, false).'>'.esc_html($slabel).'</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="text" name="c2p_state" id="c2p_state" class="regular-text" value="'.esc_attr($sel_state).'" placeholder="'.esc_attr__('Estado','c2p').'" />';
        }
        echo '</span>';
        echo '</p>';

        // CEP
        echo '<p><input type="text" name="c2p_postcode" value="'.esc_attr($m['postcode']).'" class="regular-text" placeholder="'.esc_attr__('CEP','c2p').'" /></p>';

        echo '</td></tr>';

        // Telefone + E-mail
        echo '<tr><th>'.esc_html__('Contato', 'c2p').'</th><td>';
        echo '<p><input type="text" name="c2p_phone" value="'.esc_attr($m['phone']).'" class="regular-text c2p-phone" placeholder="(xx)xxxxx-xxxx" /></p>';
        echo '<p><input type="email" name="c2p_email" value="'.esc_attr($m['email']).'" class="regular-text c2p-email" placeholder="email@dominio.com" /></p>';
        echo '</td></tr>';

        // Foto
        echo '<tr><th>'.esc_html__('Foto', 'c2p').'</th><td>';
        $img = $m['photo_id'] ? wp_get_attachment_image( $m['photo_id'], [90,90] ) : '<img src="" style="display:none;width:90px;height:90px;" />';
        echo '<div class="c2p-media">'.$img;
        echo '<input type="hidden" name="c2p_photo_id" value="'.esc_attr($m['photo_id']).'" />';
        echo '<button type="button" class="button c2p-media-select">'.esc_html__('Selecionar imagem','c2p').'</button> ';
        echo '<button type="button" class="button c2p-media-remove">'.esc_html__('Remover','c2p').'</button>';
        echo '</div></td></tr>';

        echo '</table>';
    }

    public function box_hours( \WP_Post $post ) {
        $m = self::get_meta_all( $post->ID );
        $days = [
            'mon'=>__('Segunda','c2p'),'tue'=>__('Terça','c2p'),'wed'=>__('Quarta','c2p'),
            'thu'=>__('Quinta','c2p'),'fri'=>__('Sexta','c2p'),'sat'=>__('Sábado','c2p'),'sun'=>__('Domingo','c2p')
        ];

        echo '<div class="c2p-hours-block">';

        echo '<div class="c2p-days-grid">';
        foreach ($days as $k=>$label) {
            $h = $m['hours_weekly'][$k] ?? ['open'=>'','close'=>'','open_enabled'=>true];
            $is_closed = empty($h['open_enabled']);

            $open   = (string)($h['open']   ?? '');
            $close  = (string)($h['close']  ?? '');
            $cutoff = (string)($h['cutoff'] ?? '');
            $prep   = (int)   ($h['prep_min'] ?? 0);

            echo '<div class="c2p-day-card">';
            echo '<div class="c2p-day-title">'.esc_html($label).'</div>';

            // abertura/fechamento
            echo '<div class="c2p-day-fields">';
            echo '<input type="text" name="c2p_hours_weekly['.$k.'][open]" value="'.esc_attr($open).'" class="c2p-time" placeholder="00:00" />';
            echo '<span class="c2p-sep">–</span>';
            echo '<input type="text" name="c2p_hours_weekly['.$k.'][close]" value="'.esc_attr($close).'" class="c2p-time" placeholder="00:00" />';
            echo '</div>';

            // horário limite — agora por dia (sem "mesmo dia")
            echo '<div class="c2p-day-fields" style="margin-top:6px">';
            echo '<label style="display:inline-block;margin-right:6px;min-width:140px;">'.esc_html__('Horário limite','c2p').'</label>';
            echo '<input type="text" name="c2p_hours_weekly['.$k.'][cutoff]" value="'.esc_attr($cutoff).'" class="c2p-time" placeholder="00:00" />';
            echo '</div>';

            // tempo de preparo por dia (min)
            echo '<div class="c2p-day-fields" style="margin-top:6px">';
            echo '<label style="display:inline-block;margin-right:6px;min-width:140px;">'.esc_html__('Tempo de preparo (min)','c2p').'</label>';
            echo '<input type="number" min="0" step="1" name="c2p_hours_weekly['.$k.'][prep_min]" value="'.esc_attr($prep).'" class="small-text" />';
            echo '</div>';

            echo '<label class="c2p-switch" style="margin-top:6px;"><input type="checkbox" name="c2p_hours_weekly['.$k.'][closed]" value="1" '.( $is_closed ? 'checked' : '' ).' /> ';
            echo '<span>'.esc_html__('Fechado','c2p').'</span></label>';

            echo '</div>';
        }
        echo '</div>';

        echo '<div class="c2p-hours-row" style="margin-top:12px"><div class="c2p-field c2p-actions" style="grid-column:1 / -1;">';
        echo '<button type="button" class="button" id="c2p-copy-mon">'.esc_html__('Replicar horário todos os dias','c2p').'</button>';
        echo '</div></div>';

        echo '</div>';
    }

    public function box_special( \WP_Post $post ) {
        $m = self::get_meta_all( $post->ID );
        $specials = is_array($m['hours_special']) ? array_values($m['hours_special']) : [];

        // Cabeçalho (títulos como "tabela")
        echo '<div class="c2p-special-head" style="display:grid;grid-template-columns:140px 90px 90px 90px 120px 1fr 90px 140px;gap:8px;font-weight:600;margin:4px 0 8px;">';
        echo '<div>'.esc_html__('Data','c2p').'</div>';
        echo '<div>'.esc_html__('Abertura','c2p').'</div>';
        echo '<div>'.esc_html__('Fechamento','c2p').'</div>';
        echo '<div>'.esc_html__('Limite','c2p').'</div>';
        echo '<div>'.esc_html__('Preparo (min)','c2p').'</div>';
        echo '<div>'.esc_html__('Descrição','c2p').'</div>';
        echo '<div>'.esc_html__('Anual','c2p').'</div>';
        echo '<div>'.esc_html__('Ações','c2p').'</div>';
        echo '</div>';

        echo '<div id="c2p-special-days">';
        foreach ($specials as $i=>$sp) {
            $date   = $sp['date_br'] ?? '';
            $open   = $sp['open'] ?? '';
            $close  = $sp['close'] ?? '';
            $cutoff = $sp['cutoff'] ?? '';
            $prep   = isset($sp['prep_min']) ? (int)$sp['prep_min'] : 0;
            $desc   = $sp['desc'] ?? '';
            $annual = !empty($sp['annual']);

            echo '<div class="c2p-special-item" data-index="'.(int)$i.'" data-readonly="1" style="display:grid;grid-template-columns:140px 90px 90px 90px 120px 1fr 90px 140px;gap:8px;align-items:center;margin-bottom:6px;">';

            echo '<input type="text" name="c2p_hours_special['.$i.'][date_br]" value="'.esc_attr($date).'" class="c2p-date c2p-ro" placeholder="dd/mm/aaaa" readonly />';

            echo '<input type="text" name="c2p_hours_special['.$i.'][open]" value="'.esc_attr($open).'" class="c2p-time c2p-ro" placeholder="00:00" readonly />';

            echo '<input type="text" name="c2p_hours_special['.$i.'][close]" value="'.esc_attr($close).'" class="c2p-time c2p-ro" placeholder="00:00" readonly />';

            // Limite (sem "mesmo dia")
            echo '<input type="text" name="c2p_hours_special['.$i.'][cutoff]" value="'.esc_attr($cutoff).'" class="c2p-time c2p-ro" placeholder="'.esc_attr__('00:00','c2p').'" readonly />';

            echo '<input type="number" min="0" step="1" name="c2p_hours_special['.$i.'][prep_min]" value="'.esc_attr($prep).'" class="small-text c2p-ro" placeholder="'.esc_attr__('0','c2p').'" readonly />';

            echo '<input type="text" name="c2p_hours_special['.$i.'][desc]" value="'.esc_attr($desc).'" class="regular-text c2p-ro" placeholder="'.esc_attr__('Descrição (ex.: Feriado Municipal)','c2p').'" readonly />';

            echo '<label class="c2p-ro" style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="c2p_hours_special['.$i.'][annual]" value="1" '.( $annual?'checked':'' ).' disabled /> <span>'.esc_html__('Sim','c2p').'</span></label>';

            echo '<span>';
            echo '<button type="button" class="button c2p-edit-special">'.esc_html__('Editar','c2p').'</button> ';
            echo '<button type="button" class="button c2p-remove-special">'.esc_html__('Remover','c2p').'</button>';
            echo '</span>';

            echo '</div>';
        }
        echo '</div>';

        echo '<p><button type="button" class="button" id="c2p-add-special">'.esc_html__('Adicionar dia especial','c2p').'</button></p>';
    }

    public function save_meta( int $post_id ) {
        if ( ! isset($_POST['c2p_store_nonce']) || ! wp_verify_nonce( $_POST['c2p_store_nonce'], 'c2p_store_save' ) ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        // === Tipo (preferência: c2p_type vindo do form principal) — SEM fallback
        if ( isset($_POST['c2p_store_type_nonce']) && wp_verify_nonce($_POST['c2p_store_type_nonce'], 'c2p_store_type_nonce') ) {
            $val = isset($_POST['c2p_type']) ? sanitize_text_field($_POST['c2p_type']) : '';
            if ( $val === 'cd' || $val === 'loja' ) {
                update_post_meta( $post_id, 'c2p_type', $val );
                // Legados em sincronia (útil p/ compatibilidade)
                update_post_meta( $post_id, 'c2p_store_type', $val === 'cd' ? 'dc' : 'store' );
                update_post_meta( $post_id, 'c2p_is_cd',      $val === 'cd' ? '1'  : '0' );
            }
        }

        // Endereço padrão Woo
        update_post_meta( $post_id, 'c2p_address_1', sanitize_text_field($_POST['c2p_address_1']??'') );
        update_post_meta( $post_id, 'c2p_address_2', sanitize_text_field($_POST['c2p_address_2']??'') );
        update_post_meta( $post_id, 'c2p_city',      sanitize_text_field($_POST['c2p_city']??'') );
        update_post_meta( $post_id, 'c2p_country',   sanitize_text_field($_POST['c2p_country']??'') );
        update_post_meta( $post_id, 'c2p_state',     sanitize_text_field($_POST['c2p_state']??'') );
        update_post_meta( $post_id, 'c2p_postcode',  sanitize_text_field($_POST['c2p_postcode']??'') );

        // Telefone: (xx)xxxxx-xxxx ou (xx)xxxx-xxxx
        $phone = sanitize_text_field($_POST['c2p_phone']??'');
        $phone = preg_replace('/\s+/', '', $phone);
        $valid_phone = (bool) preg_match('/^\(\d{2}\)\d{4,5}\-\d{4}$/', $phone);
        if ( $phone !== '' && ! $valid_phone ) {
            set_transient( 'c2p_phone_invalid_'.$post_id, __( 'Telefone inválido. Use (xx)xxxxx-xxxx ou (xx)xxxx-xxxx.', 'c2p' ), 30 );
        } else {
            update_post_meta( $post_id, 'c2p_phone', $phone );
        }

        // E-mail
        $email = sanitize_email($_POST['c2p_email']??'');
        if ( $email && ! is_email( $email ) ) {
            set_transient( 'c2p_email_invalid_'.$post_id, __( 'E-mail inválido. Corrija e salve novamente.', 'c2p' ), 30 );
        } else {
            update_post_meta( $post_id, 'c2p_email', $email );
        }

        // Mídia
        update_post_meta( $post_id, 'c2p_photo_id', (int)($_POST['c2p_photo_id']??0) );

        // Semana (com open/close/cutoff/prep_min por dia)
        $weekly = $_POST['c2p_hours_weekly'] ?? [];
        foreach ($weekly as $k=>$d) {
            foreach (['open','close','cutoff'] as $f) {
                if (!empty($d[$f])) {
                    $val = preg_replace('/[^0-9]/','',$d[$f]);
                    if (strlen($val) >= 3) { $d[$f] = substr($val,0,2).':'.substr($val,2,2); }
                    $d[$f] = $this->clamp_time($d[$f]);
                } else {
                    $d[$f] = '';
                }
            }
            $is_closed = !empty($d['closed']);
            $prep_min  = isset($d['prep_min']) ? max(0, intval($d['prep_min'])) : 0;
            $weekly[$k] = [
                'open'         => $d['open'] ?? '',
                'close'        => $d['close'] ?? '',
                'cutoff'       => $d['cutoff'] ?? '',
                'prep_min'     => $prep_min,
                'open_enabled' => !$is_closed,
            ];
        }
        update_post_meta( $post_id, 'c2p_hours_weekly', $weekly );

        // Dias especiais (com open/close/cutoff/prep_min)
        $specials = $_POST['c2p_hours_special'] ?? [];
        foreach ($specials as &$sp) {
            if (!empty($sp['date_br'])) {
                $parts = explode('/',$sp['date_br']);
                if (count($parts)===3) {
                    $sp['date_sql'] = sprintf('%04d-%02d-%02d',(int)$parts[2],(int)$parts[1],(int)$parts[0]);
                }
            }
            foreach (['open','close','cutoff'] as $f) {
                if (!empty($sp[$f])) {
                    $val = preg_replace('/[^0-9]/','',$sp[$f]);
                    if (strlen($val) >= 3) { $sp[$f] = substr($val,0,2).':'.substr($val,2,2); }
                    $sp[$f] = $this->clamp_time($sp[$f]);
                } else {
                    $sp[$f] = '';
                }
            }
            $sp['prep_min'] = isset($sp['prep_min']) ? max(0, intval($sp['prep_min'])) : 0;
            $sp['desc']     = sanitize_text_field( $sp['desc'] ?? '' );
            $sp['annual']   = !empty($sp['annual']);
        }
        unset($sp);
        update_post_meta( $post_id, 'c2p_hours_special', $specials );
    }

    private function clamp_time( string $hhmm ): string {
        if ( ! preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m) ) return '';
        $H = min( max( (int)$m[1], 0 ), 23 );
        $M = min( max( (int)$m[2], 0 ), 59 );
        return sprintf('%02d:%02d', $H, $M );
    }

    public function columns( $columns ) {
        $new = [];
        if ( isset($columns['cb']) ) $new['cb'] = $columns['cb'];
        $new['title']      = __( 'Local de Estoque', 'c2p' );
        $new['c2p_type']   = __( 'Tipo', 'c2p' );
        $new['c2p_hours' ] = __( 'Horário', 'c2p' );
        $new['c2p_photo' ] = __( 'Foto', 'c2p' );
        return $new;
    }

    public function columns_content( $column, $post_id ) {
        if ( $column === 'c2p_photo' ) {
            $photo_id = (int) get_post_meta( $post_id, 'c2p_photo_id', true );
            if ( $photo_id ) {
                echo wp_get_attachment_image( $photo_id, [90,90], false, [ 'style' => 'width:90px;height:90px;object-fit:cover;border-radius:6px;background:#f3f4f6;' ] );
            } else {
                echo '<span style="display:inline-block;width:90px;height:90px;border-radius:6px;background:#f3f4f6;"></span>';
            }
        }
        if ( $column === 'c2p_type' ) {
            // SEM fallback: mostra diretamente a partir de c2p_type
            $t = get_post_meta($post_id,'c2p_type',true); // 'cd' | 'loja'
            echo ($t === 'cd') ? esc_html__('Centro de Distribuição','c2p') : esc_html__('Loja','c2p');
        }
        if ( $column === 'c2p_hours' ) {
            $m = self::get_meta_all( $post_id );
            $summary_lines = $this->hours_summary_lines( $m['hours_weekly'] ?: [] );
            echo '<span class="dashicons dashicons-clock" style="vertical-align:middle;margin-right:6px;"></span>';
            if ( ! empty( $summary_lines ) ) {
                echo '<div style="line-height:1.35;">';
                foreach ( $summary_lines as $line ) echo '<div>'. esc_html( $line ) .'</div>';
                echo '</div>';
            } else {
                echo '<em>'.esc_html__( 'Sem horários definidos', 'c2p' ).'</em>';
            }
        }
    }

    private function hours_summary_lines( array $weekly ): array {
        $labels = ['mon'=>__('Seg','c2p'),'tue'=>__('Ter','c2p'),'wed'=>__('Qua','c2p'),'thu'=>__('Qui','c2p'),'fri'=>__('Sex','c2p'),'sat'=>__('Sáb','c2p'),'sun'=>__('Dom','c2p')];
        $order = ['mon','tue','wed','thu','fri','sat','sun'];
        $groups = []; $current = null;
        foreach ( $order as $day ) {
            $row = $weekly[$day] ?? ['open'=>'','close'=>'','open_enabled'=>false];
            $is_open = !empty($row['open_enabled']);
            $open = $row['open'] ?: '';
            $close = $row['close'] ?: '';
            $signature = $is_open ? "open:{$open}-{$close}" : 'closed';
            if ( ! $current ) $current = [ 'start'=>$day,'end'=>$day,'sig'=>$signature,'open'=>$open,'close'=>$close,'is_open'=>$is_open ];
            else {
                if ( $current['sig'] === $signature ) { $current['end'] = $day; }
                else { $groups[] = $current; $current = [ 'start'=>$day,'end'=>$day,'sig'=>$signature,'open'=>$open,'close'=>$close,'is_open'=>$is_open ]; }
            }
        }
        if ( $current ) $groups[] = $current;
        $lines = [];
        foreach ( $groups as $g ) {
            $labels_map = $labels;
            $range = $labels_map[$g['start']];
            if ( $g['start'] !== $g['end'] ) $range .= ' ' . __( 'a', 'c2p' ) . ' ' . $labels_map[$g['end']];
            if ( $g['is_open'] && $g['open'] && $g['close'] )
                $lines[] = sprintf( '%s %s %s %s %s', $range, __( 'das','c2p' ), $g['open'], __( 'às','c2p' ), $g['close'] );
            else $lines[] = sprintf( '%s %s', $range, __( 'Fechada','c2p' ) );
        }
        return $lines;
    }

    /** Remove "Edição rápida" e mantém "Duplicar" */
    public function row_actions( $actions, $post ) {
        if ( $post->post_type !== 'c2p_store' ) return $actions;
        foreach ($actions as $key => $html) {
            if ( strpos($key, 'inline') !== false ) unset($actions[$key]);
        }
        if ( current_user_can( 'edit_post', $post->ID ) ) {
            $url = wp_nonce_url( admin_url( 'admin-post.php?action=c2p_duplicate_store&post=' . $post->ID ), 'c2p_duplicate_store_' . $post->ID );
            $actions['c2p_duplicate'] = '<a href="'.esc_url($url).'">'.esc_html__( 'Duplicar', 'c2p' ).'</a>';
        }
        return $actions;
    }

    public function duplicate_store() {
        if ( ! isset($_GET['post']) ) wp_die( esc_html__( 'Local inválido.', 'c2p' ) );
        $post_id = (int) $_GET['post'];
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'c2p_duplicate_store_' . $post_id ) ) wp_die( esc_html__( 'Ação não autorizada.', 'c2p' ) );
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( esc_html__( 'Permissão insuficiente.', 'c2p' ) );
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'c2p_store' ) wp_die( esc_html__( 'Local inválido.', 'c2p' ) );

        $new_post = ['post_type'=>'c2p_store','post_status'=>'draft','post_title'=>$post->post_title.' '.__( '(cópia)', 'c2p' )];
        $new_id = wp_insert_post( $new_post, true );
        if ( is_wp_error( $new_id ) ) wp_die( esc_html__( 'Erro ao duplicar o local.', 'c2p' ) );

        $meta_keys = [
            'c2p_type','c2p_store_type','c2p_is_cd',
            'c2p_address_1','c2p_address_2','c2p_city','c2p_country','c2p_state','c2p_postcode',
            'c2p_phone','c2p_email',
            'c2p_photo_id','c2p_hours_weekly','c2p_hours_special','c2p_shipping_instance_ids'
        ];
        foreach ( $meta_keys as $k ) {
            $val = get_post_meta( $post_id, $k, true );
            if ( $val !== '' && $val !== null ) {
                update_post_meta( $new_id, $k, $val );
            }
        }
        wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) ); exit;
    }

    public function menu_highlight_parent( $parent_file ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( $screen && $screen->post_type === 'c2p_store' ) $parent_file = 'c2p-dashboard';
        return $parent_file;
    }
    public function menu_highlight_sub( $submenu_file ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( $screen && $screen->post_type === 'c2p_store' ) $submenu_file = 'edit.php?post_type=c2p_store';
        return $submenu_file;
    }

    /** Ao publicar (draft->publish), solicita (re)scan centralizado */
    public function on_transition_status( $new_status, $old_status, $post ) : void {
        try {
            if ( ! $post || $post->post_type !== 'c2p_store' ) return;
            if ( $new_status === 'publish' && $old_status !== 'publish' ) {
                if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan','enqueue') ) {
                    \C2P\Init_Scan::enqueue( 'store_published', 5 );
                } elseif ( function_exists('as_enqueue_async_action') ) {
                    as_enqueue_async_action( 'c2p_init_full_scan', [ 'reason' => 'store_published' ], 'c2p' );
                } else {
                    if ( class_exists('\C2P\Init_Scan') ) \C2P\Init_Scan::run_full_scan( 300 );
                }
            }
        } catch ( \Throwable $e ) {
            error_log('[C2P][CPT_Store][transition] '.$e->getMessage());
        }
    }

    /** NEW: ao salvar o post do tipo c2p_store, enfileira (re)scan centralizado */
    public function maybe_enqueue_scan_on_save( int $post_id, \WP_Post $post, bool $update ): void {
        // evita autosave/revision
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        if ( ! $post || $post->post_type !== 'c2p_store' ) return;
        if ( in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) return;

        if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan','enqueue') ) {
            \C2P\Init_Scan::enqueue( 'store_saved', 5 );
        } elseif ( function_exists('as_enqueue_async_action') ) {
            as_enqueue_async_action( 'c2p_init_full_scan', [ 'reason' => 'store_saved' ], 'c2p' );
        } else {
            if ( class_exists('\C2P\Init_Scan') ) \C2P\Init_Scan::run_full_scan( 300 );
        }
    }

    // ===== Limpeza ao excluir permanentemente um Local de Estoque =====
    public function on_before_delete_post( $post_id ): void {
        try {
            $post_id = (int) $post_id;
            if ( $post_id <= 0 ) return;
            if ( get_post_type( $post_id ) !== 'c2p_store' ) return;

            global $wpdb;

            // (0) CONGELAR O NOME DO LOCAL NO LEDGER ANTES DE REMOVER O CPT
            if ( class_exists( '\C2P\Stock_Ledger' ) && method_exists( '\C2P\Stock_Ledger', 'table_name' ) ) {
                $ledger_tbl = \C2P\Stock_Ledger::table_name();
                $name_now   = get_the_title( $post_id );
                if ( ! $name_now ) { $name_now = 'Local #'.$post_id; }
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$ledger_tbl}
                        SET location_name_text = %s
                      WHERE location_id = %d
                        AND (location_name_text IS NULL OR location_name_text = '')",
                    $name_now, $post_id
                ) );
            }

            // (1) Remover linhas do multi-estoque referentes a este local (se a tabela canônica existir)
            if ( class_exists( '\C2P\Inventory_DB' ) ) {
                $table = \C2P\Inventory_DB::table_name();
                $col   = \C2P\Inventory_DB::store_column_name();

                // Produtos impactados por este local
                $product_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT product_id
                       FROM {$table}
                      WHERE {$col} = %d",
                    $post_id
                ) );

                // Apaga as linhas deste local
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$table} WHERE {$col} = %d",
                    $post_id
                ) );

                // Reindexa cada produto impactado (rebuild snapshots e total)
                if ( $product_ids ) {
                    foreach ( $product_ids as $pid ) {
                        $this->rebuild_product_snapshots( (int) $pid );
                    }
                }
            }

            // (2) Enfileira um scan global para garantir consistência (centralizado)
            if ( class_exists('\C2P\Init_Scan') && method_exists('\C2P\Init_Scan','enqueue') ) {
                \C2P\Init_Scan::enqueue( 'store_deleted', 5 );
            } elseif ( function_exists('as_enqueue_async_action') ) {
                as_enqueue_async_action( 'c2p_init_full_scan', [ 'reason' => 'store_deleted' ], 'c2p' );
            } else {
                if ( class_exists('\C2P\Init_Scan') ) \C2P\Init_Scan::run_full_scan( 300 );
            }

        } catch ( \Throwable $e ) {
            error_log( '[C2P][CPT_Store][delete] ' . $e->getMessage() );
        }
    }

    /**
     * Recalcula total, espelha no Woo e recria snapshots por local (apenas Locais publicados).
     */
    private function rebuild_product_snapshots( int $product_id ) : void {
        if ( $product_id <= 0 || ! class_exists( '\\C2P\\Inventory_DB' ) ) return;

        global $wpdb;
        $table = \C2P\Inventory_DB::table_name();
        $col   = \C2P\Inventory_DB::store_column_name();

        // Soma apenas de locais válidos (c2p_store publicados)
        $sum = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(ms.qty),0)
               FROM {$table} ms
               JOIN {$wpdb->posts} p
                 ON p.ID = ms.{$col}
                AND p.post_type = %s
                AND p.post_status = 'publish'
              WHERE ms.product_id = %d",
            'c2p_store',
            $product_id
        ) );

        // Espelha no produto
        $product = function_exists('wc_get_product') ? wc_get_product( $product_id ) : null;
        if ( $product ) {
            $product->set_stock_quantity( $sum );
            if ( ! $product->backorders_allowed() ) {
                $product->set_stock_status( $sum > 0 ? 'instock' : 'outofstock' );
            }
            $product->save();
        } else {
            update_post_meta( $product_id, '_stock', $sum );
            $backorders = get_post_meta( $product_id, '_backorders', true );
            if ( $backorders !== 'yes' ) {
                update_post_meta( $product_id, '_stock_status', ( $sum > 0 ? 'instock' : 'outofstock' ) );
            }
        }

        // Recria snapshots (apenas locais publicados)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ms.{$col} AS loc, ms.qty
               FROM {$table} ms
               JOIN {$wpdb->posts} p
                 ON p.ID = ms.{$col}
                AND p.post_type = %s
                AND p.post_status = 'publish'
              WHERE ms.product_id = %d
              ORDER BY ms.{$col} ASC",
            'c2p_store',
            $product_id
        ), ARRAY_A );

        $by_id   = [];
        $by_name = [];
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $loc_id = (int) $r['loc'];
                $qty    = (int) $r['qty'];
                $by_id[ $loc_id ] = $qty;

                $title = get_the_title( $loc_id );
                if ( ! $title ) $title = 'Local #'.$loc_id;
                $by_name[ $title ] = $qty;
            }
        }

        update_post_meta( $product_id, 'c2p_stock_by_location_ids', $by_id );
        update_post_meta( $product_id, 'c2p_stock_by_location',     $by_name );

        // Limpa caches
        if ( function_exists('wc_delete_product_transients') ) wc_delete_product_transients( $product_id );
        if ( function_exists('wc_update_product_lookup_tables') ) wc_update_product_lookup_tables( $product_id );
        if ( function_exists('clean_post_cache') ) clean_post_cache( $product_id );
    }
}

// === Begin Store_Shipping_Link ===
if ( ! defined( 'ABSPATH' ) ) exit;

class Store_Shipping_Link {
    private static $instance;
    private function notice_key(): string { return 'c2p_notice_' . get_current_user_id(); }

    public static function instance(): Store_Shipping_Link {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
        add_action( 'save_post_c2p_store', [ $this, 'save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'admin_footer-post.php', [ $this, 'reposition_js' ] );
        add_action( 'admin_footer-post-new.php', [ $this, 'reposition_js' ] );
        add_action( 'admin_notices', [ $this, 'maybe_admin_notice' ] );
        add_filter( 'redirect_post_location', [ $this, 'inject_notice_param' ], 10, 2 );
    }

    public function add_box() {
        add_meta_box(
            'c2p_store_shipping',
            __( 'Entrega / Método de frete vinculado', 'c2p' ),
            [ $this, 'render' ],
            'c2p_store',
            'normal',
            'high'
        );
    }

    public function render( \WP_Post $post ) {
        wp_nonce_field( 'c2p_store_shipping', 'c2p_store_shipping_nonce' );

        $has_orders = $this->store_has_orders( $post->ID );
        $methods    = self::get_all_shipping_instances();
        $current    = (array) get_post_meta( $post->ID, 'c2p_shipping_instance_ids', true );

        echo '<p style="margin-top:0">'.esc_html__(
            'Marque os métodos de frete que pertencem a este local. Na retirada, use métodos de retirada; na entrega, os métodos de envio.',
            'c2p'
        ).'</p>';

        if ( $has_orders ) {
            echo '<div class="notice notice-warning" style="margin:8px 0;padding:8px;">';
            echo '<p style="margin:0"><strong>'.esc_html__('Atenção:', 'c2p').'</strong> ';
            echo esc_html__('Este local já possui pedidos vinculados. Para alterar, clique em "Destravar edição" e confirme a mudança.', 'c2p').'</p>';
            echo '<p style="margin:.5em 0 0">';
            echo '<button type="button" class="button" id="c2p_unlock_shipping_link">'.esc_html__('Destravar edição', 'c2p').'</button> ';
            echo '<label style="margin-left:.5em"><input type="checkbox" id="c2p_confirm_change_shipping_link" name="c2p_confirm_change_shipping_link" value="1"> ';
            echo esc_html__('Confirmo que desejo alterar os métodos deste local.', 'c2p').'</label>';
            echo '</p></div>';
        }

        $valid_ids = [];
        echo '<div id="c2p_shipping_methods_wrap" class="'.( $has_orders ? 'is-locked' : '' ).'">';
        if ( empty( $methods ) ) {
            echo '<p>'.esc_html__('Nenhuma instância de método de frete foi encontrada.', 'c2p').'</p>';
        } else {
            echo '<ul style="margin:0;padding:0;list-style:none">';
            foreach ( $methods as $m ) {
                $iid = (int) $m['instance_id'];
                $valid_ids[ $iid ] = true;
                $checked  = in_array( $iid, $current, true ) ? ' checked="checked"' : '';
                $disabled = $has_orders ? ' disabled="disabled"' : '';
                $label = sprintf( '%s — %s (ID %d)', $m['title'], $m['zone_name'], $iid );
                echo '<li style="margin:.25em 0">';
                echo '<label><input type="checkbox" class="c2p-ship-iid" name="c2p_shipping_instance_ids[]" value="'.esc_attr($iid).'"'.$checked.$disabled.'> '.esc_html($label).'</label>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';

        echo '<input type="hidden" name="c2p_shipping_valid_ids" value="'.esc_attr( wp_json_encode( array_keys( $valid_ids ) ) ).'">';
        echo '<input type="hidden" name="c2p_shipping_locked_snapshot" value="'.esc_attr( wp_json_encode( array_values( $current ) ) ).'">';

        ?>
<script>
(function(){
  var wrap = document.getElementById('c2p_shipping_methods_wrap');
  var btn  = document.getElementById('c2p_unlock_shipping_link');
  var cb   = document.getElementById('c2p_confirm_change_shipping_link');
  if(!wrap || !btn || !cb) return;
  function enableInputs(enable){
    wrap.querySelectorAll('input.c2p-ship-iid').forEach(function(i){ i.disabled = !enable; });
    wrap.classList.toggle('is-locked', !enable);
  }
  btn.addEventListener('click', function(){
    if(!cb.checked){
      alert('<?php echo esc_js(__('Marque a confirmação antes de destravar.', 'c2p')); ?>');
      return;
    }
    enableInputs(true);
  });
})();
</script>
        <?php
    }

    public function save( int $post_id ) {
        if ( ! isset($_POST['c2p_store_shipping_nonce']) || ! wp_verify_nonce( $_POST['c2p_store_shipping_nonce'], 'c2p_store_shipping' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $has_orders = $this->store_has_orders( $post_id );

        $valid = [];
        if ( isset($_POST['c2p_shipping_valid_ids']) ) {
            $decoded = json_decode( wp_unslash( $_POST['c2p_shipping_valid_ids'] ), true );
            if ( is_array( $decoded ) ) foreach ( $decoded as $i ) if ( is_numeric($i) ) $valid[(int)$i] = true;
        }
        if ( empty( $valid ) ) foreach ( self::get_all_shipping_instances() as $m ) $valid[(int)$m['instance_id']] = true;

        $submitted = isset($_POST['c2p_shipping_instance_ids']) && is_array($_POST['c2p_shipping_instance_ids'])
            ? array_values( array_unique( array_map('intval', $_POST['c2p_shipping_instance_ids']) ) )
            : [];

        if ( $has_orders ) {
            $snapshot = [];
            if ( isset($_POST['c2p_shipping_locked_snapshot']) ) {
                $snap = json_decode( wp_unslash( $_POST['c2p_shipping_locked_snapshot'] ), true );
                if ( is_array( $snap ) ) $snapshot = array_values( array_unique( array_map('intval', $snap) ) );
            }
            $confirmed = ! empty( $_POST['c2p_confirm_change_shipping_link'] );
            if ( ! $confirmed ) {
                update_post_meta( $post_id, 'c2p_shipping_instance_ids', $snapshot );
                $this->set_notice( 'error', __( 'Alteração NÃO aplicada: é necessário confirmar e destravar para editar os métodos.', 'c2p' ) );
                return;
            }
            $clean = []; foreach ( $submitted as $iid ) if ( isset($valid[$iid]) ) $clean[] = $iid;
            update_post_meta( $post_id, 'c2p_shipping_instance_ids', $clean );
            $this->set_notice( 'updated', __( 'Métodos de frete vinculados atualizados.', 'c2p' ) );
            return;
        }

        $clean = []; foreach ( $submitted as $iid ) if ( isset($valid[$iid]) ) $clean[] = $iid;
        update_post_meta( $post_id, 'c2p_shipping_instance_ids', $clean );
        $this->set_notice( 'updated', __( 'Métodos de frete vinculados atualizados.', 'c2p' ) );
    }

    public function admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'c2p_store' ) return;
        wp_add_inline_style( 'common', '#c2p_store_shipping.postbox .inside{ padding-top:10px; } #c2p_shipping_methods_wrap.is-locked { opacity:.85; pointer-events:none; }' );
    }

    public function reposition_js() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'c2p_store' ) return; ?>
<script>
(function(){
  function byTitle(pattern){
    var h = Array.prototype.find.call(document.querySelectorAll('#poststuff .postbox .hndle, #poststuff .postbox h2'),
      function(el){ return pattern.test((el.textContent||'').trim()); });
    return h ? h.closest('.postbox') : null;
  }
  function moveBox(){
    var ship   = document.getElementById('c2p_store_shipping'); if(!ship) return;
    var details= document.getElementById('c2p_store_main') || byTitle(/Dados do Local/i);
    var hours  = document.getElementById('c2p_store_hours') || byTitle(/Horários|Hours/i);
    if(details && details.parentNode){
      details.parentNode.insertBefore(ship, hours ? hours : details.nextSibling);
    } else if (hours && hours.parentNode){
      hours.parentNode.insertBefore(ship, hours);
    }
    ship.style.display = '';
  }
  document.addEventListener('DOMContentLoaded', moveBox);
  window.addEventListener('load', moveBox);
})();
</script>
<?php }

    private function set_notice( string $type, string $message ): void {
        set_transient( $this->notice_key(), [ 'type' => $type, 'message' => $message ], 30 );
    }
    public function maybe_admin_notice() {
        $n = get_transient( $this->notice_key() );
        if ( ! $n || empty( $n['message'] ) ) return;
        delete_transient( $this->notice_key() );
        $class = $n['type']==='error' ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="'.$class.'"><p>'.esc_html($n['message']).'</p></div>';
    }
    public function inject_notice_param( $location ) {
        if ( get_transient( $this->notice_key() ) ) $location = add_query_arg( 'c2p_notice', '1', $location );
        return $location;
    }

    /**
     * Considera pedidos com meta:
     *  - _c2p_location_id / c2p_location_id (novo)
     *  - _c2p_store_id    / c2p_store_id    (compat)
     */
    private function store_has_orders( int $store_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1)
               FROM {$wpdb->postmeta}
              WHERE meta_value = %d
                AND meta_key IN ('_c2p_location_id','c2p_location_id','_c2p_store_id','c2p_store_id')",
            $store_id
        ) );
        return $count > 0;
    }

    /** Lista todos os métodos (instâncias) ativos por zona */
    public static function get_all_shipping_instances(): array {
        if ( ! class_exists( '\WC_Shipping_Zones' ) ) return [];
        $out = [];
        $zones = \WC_Shipping_Zones::get_zones();
        // Zona 0 (Resto do Mundo)
        $zones[0] = ( new \WC_Shipping_Zone(0) )->get_data();
        foreach ( $zones as $z ) {
            $zone = new \WC_Shipping_Zone( $z['id'] ?? 0 );
            foreach ( $zone->get_shipping_methods( true ) as $iid => $m ) {
                if ( method_exists($m,'is_enabled') && ! $m->is_enabled() ) continue;
                $out[] = [
                    'instance_id' => (int) $iid,
                    'method_id'   => $m->id,
                    'title'       => $m->get_title(),
                    'zone_id'     => (int) $zone->get_id(),
                    'zone_name'   => $zone->get_zone_name(),
                ];
            }
        }
        return $out;
    }

    public static function get_store_shipping_instance_ids( int $store_id ): array {
        $ids = (array) get_post_meta( $store_id, 'c2p_shipping_instance_ids', true );
        return array_values( array_unique( array_map('intval', $ids) ) );
    }

    /**
     * Retorna um mapa instance_id => store_id (location_id), inferido dos metadados
     * "c2p_shipping_instance_ids" de cada Local de Estoque (CPT c2p_store).
     * Preferência:
     *  - para "local_pickup": prioriza lojas (c2p_type='loja')
     *  - para demais métodos: prioriza CDs (c2p_type='cd')
     */
    public static function get_instance_to_location_map(): array {
        static $cache = null;
        if ( is_array($cache) ) return $cache;

        // instance_id => method_id (ex.: 12 => 'local_pickup')
        $methods = [];
        foreach ( self::get_all_shipping_instances() as $m ) {
            $methods[ (int) ($m['instance_id'] ?? 0) ] = (string) ($m['method_id'] ?? '' );
        }

        // percorre todos os Locais (publicados, rascunho, etc.)
        $q = new \WP_Query([
            'post_type'      => 'c2p_store',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'fields'         => 'ids',
            'nopaging'       => true,
        ]);

        $map  = [];
        $pref = []; // instance_id => 'loja' | 'cd' | ''

        foreach ( $q->posts as $store_id ) {
            $store_id = (int) $store_id;
            $type     = (string) get_post_meta( $store_id, 'c2p_type', true ); // 'loja' ou 'cd'
            $iids     = (array) self::get_store_shipping_instance_ids( $store_id );

            foreach ( $iids as $iid ) {
                $iid = (int) $iid;
                if ( $iid <= 0 ) continue;

                if ( ! isset($map[$iid]) ) {
                    $map[$iid]  = $store_id;
                    $pref[$iid] = $type ?: '';
                    continue;
                }

                // Desempate: para local_pickup, prefira 'loja'; demais, prefira 'cd'
                $method_id = $methods[$iid] ?? '';
                $cur_type  = $pref[$iid] ?? '';

                if ( $method_id === 'local_pickup' ) {
                    if ( $type === 'loja' && $cur_type !== 'loja' ) {
                        $map[$iid]  = $store_id;
                        $pref[$iid] = 'loja';
                    }
                } else {
                    if ( $type === 'cd' && $cur_type !== 'cd' ) {
                        $map[$iid]  = $store_id;
                        $pref[$iid] = 'cd';
                    }
                }
            }
        }

        $cache = $map;
        return $map;
    }

    /** Retorna o ID do Local de Estoque a partir do instance_id do método de frete */
    public static function get_location_id_by_instance( int $instance_id ): ?int {
        $map = self::get_instance_to_location_map();
        return isset($map[$instance_id]) ? (int) $map[$instance_id] : null;
    }

    /** Alias compatível */
    public static function get_location_id_by_shipping_instance( int $instance_id ): ?int {
        return self::get_location_id_by_instance( $instance_id );
    }
}

// Bootstrap
\C2P\Locations_Admin::instance();
