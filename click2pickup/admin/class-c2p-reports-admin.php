<?php
/**
 * Relatórios do Click2Pickup (Admin)
 *
 * @package Click2Pickup
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_Reports_Admin {

    public function __construct() {
        // Se o menu principal "Click2Pickup" já for criado por C2P_Admin, adicionamos um submenu.
        add_action('admin_menu', array($this, 'register_menu'), 30);
    }

    public function register_menu() {
        // O slug do menu principal deve ser o mesmo usado pelo C2P_Admin.
        // Caso o slug do menu principal seja diferente, ajuste 'click2pickup' abaixo.
        $parent_slug = 'click2pickup';

        // Verifica se o menu existe. Se não existir, evita erro.
        global $submenu;
        $menu_exists = false;
        if (is_array($submenu)) {
            foreach ($submenu as $slug => $items) {
                if ($slug === $parent_slug) {
                    $menu_exists = true;
                    break;
                }
            }
        }

        // Mesmo que o menu não exista, registrar não causa fatal; apenas não aparecerá.
        add_submenu_page(
            $parent_slug,
            __('Relatórios', 'click2pickup'),
            __('Relatórios', 'click2pickup'),
            'manage_c2p_stock',
            'c2p-reports',
            array($this, 'display_page'),
            30
        );
    }

    public function display_page() {
        if (!current_user_can('manage_c2p_stock')) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'click2pickup'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Relatórios Click2Pickup', 'click2pickup'); ?></h1>

            <h2 class="nav-tab-wrapper" style="margin-top:16px;">
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'c2p-reports', 'tab' => 'overview'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Visão Geral', 'click2pickup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'c2p-reports', 'tab' => 'locations'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'locations' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Por Local', 'click2pickup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'c2p-reports', 'tab' => 'products'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Por Produto', 'click2pickup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'c2p-reports', 'tab' => 'stockouts'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'stockouts' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Rupturas', 'click2pickup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('page' => 'c2p-reports', 'tab' => 'transfers'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'transfers' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Transferências', 'click2pickup'); ?>
                </a>
            </h2>

            <div style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;">
                <?php
                switch ($active_tab) {
                    case 'locations':
                        $this->render_locations_report();
                        break;
                    case 'products':
                        $this->render_products_report();
                        break;
                    case 'stockouts':
                        $this->render_stockouts_report();
                        break;
                    case 'transfers':
                        $this->render_transfers_report();
                        break;
                    default:
                        $this->render_overview_report();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_overview_report() {
        global $wpdb;

        $table_locations = $wpdb->prefix . 'c2p_locations';
        $table_stock = $wpdb->prefix . 'c2p_stock';
        $table_mov = $wpdb->prefix . 'c2p_stock_movements';

        $locations_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_locations WHERE is_active = 1"));
        $products_count = intval($wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM $table_stock"));
        $total_stock = intval($wpdb->get_var("SELECT SUM(stock_quantity) FROM $table_stock"));
        $low_stock = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_stock WHERE stock_quantity <= low_stock_threshold"));

        ?>
        <div class="c2p-report-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
            <div style="background:#f8f9ff;border:1px solid #e5e7ff;border-radius:10px;padding:16px;">
                <h3 style="margin:0 0 8px 0;"><?php esc_html_e('Locais Ativos', 'click2pickup'); ?></h3>
                <div style="font-size:28px;font-weight:700;color:#4c51bf;"><?php echo esc_html($locations_count); ?></div>
            </div>
            <div style="background:#f8fff9;border:1px solid #e6f6ea;border-radius:10px;padding:16px;">
                <h3 style="margin:0 0 8px 0;"><?php esc_html_e('Produtos Gerenciados', 'click2pickup'); ?></h3>
                <div style="font-size:28px;font-weight:700;color:#2f855a;"><?php echo esc_html($products_count); ?></div>
            </div>
            <div style="background:#fffaf8;border:1px solid #ffe9df;border-radius:10px;padding:16px;">
                <h3 style="margin:0 0 8px 0;"><?php esc_html_e('Estoque Total', 'click2pickup'); ?></h3>
                <div style="font-size:28px;font-weight:700;color:#dd6b20;"><?php echo esc_html($total_stock); ?></div>
            </div>
            <div style="background:#fff5f7;border:1px solid #fed7e2;border-radius:10px;padding:16px;">
                <h3 style="margin:0 0 8px 0;"><?php esc_html_e('Baixo Estoque', 'click2pickup'); ?></h3>
                <div style="font-size:28px;font-weight:700;color:#e53e3e;"><?php echo esc_html($low_stock); ?></div>
            </div>
        </div>
        <?php
    }

    private function render_locations_report() {
        global $wpdb;
        $table_locations = $wpdb->prefix . 'c2p_locations';
        $table_stock = $wpdb->prefix . 'c2p_stock';

        $locations = $wpdb->get_results("SELECT * FROM $table_locations WHERE is_active = 1 ORDER BY name ASC");
        if (!$locations) {
            echo '<p>' . esc_html__('Nenhum local ativo.', 'click2pickup') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Local', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Tipo', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Produtos', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Estoque Total', 'click2pickup') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($locations as $loc) {
            $products_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_stock WHERE location_id = %d",
                $loc->id
            )));
            $stock_sum = intval($wpdb->get_var($wpdb->prepare(
                "SELECT SUM(stock_quantity) FROM $table_stock WHERE location_id = %d",
                $loc->id
            )));
            echo '<tr>';
            echo '<td><strong>' . esc_html($loc->name) . '</strong></td>';
            echo '<td>' . ($loc->type === 'distribution_center' ? esc_html__('CD', 'click2pickup') : esc_html__('Loja', 'click2pickup')) . '</td>';
            echo '<td>' . esc_html($products_count) . '</td>';
            echo '<td>' . esc_html($stock_sum) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_products_report() {
        global $wpdb;
        $table_stock = $wpdb->prefix . 'c2p_stock';

        $items = $wpdb->get_results("
            SELECT product_id, SUM(stock_quantity) as total_stock, SUM(reserved_quantity) as total_reserved
            FROM $table_stock
            GROUP BY product_id
            ORDER BY total_stock ASC
            LIMIT 50
        ");

        if (!$items) {
            echo '<p>' . esc_html__('Sem dados para exibir.', 'click2pickup') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Produto', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('SKU', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Estoque', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Reservado', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Disponível', 'click2pickup') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $row) {
            $product = wc_get_product($row->product_id);
            if (!$product) { continue; }
            $available = intval($row->total_stock) - intval($row->total_reserved);

            echo '<tr>';
            echo '<td><strong>' . esc_html($product->get_name()) . '</strong></td>';
            echo '<td>' . esc_html($product->get_sku()) . '</td>';
            echo '<td>' . esc_html($row->total_stock) . '</td>';
            echo '<td>' . esc_html($row->total_reserved) . '</td>';
            echo '<td style="color:' . ($available <= 0 ? 'red' : 'green') . ';">' . esc_html($available) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_stockouts_report() {
        global $wpdb;
        $table_stock = $wpdb->prefix . 'c2p_stock';
        $table_locations = $wpdb->prefix . 'c2p_locations';

        $rows = $wpdb->get_results("
            SELECT s.location_id, s.product_id, s.stock_quantity, l.name as location_name
            FROM $table_stock s
            JOIN $table_locations l ON l.id = s.location_id
            WHERE s.stock_quantity <= 0
            ORDER BY s.stock_quantity ASC
            LIMIT 100
        ");

        if (!$rows) {
            echo '<p>' . esc_html__('Nenhuma ruptura encontrada.', 'click2pickup') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Local', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Produto', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Estoque', 'click2pickup') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $product = wc_get_product($r->product_id);
            echo '<tr>';
            echo '<td>' . esc_html($r->location_name) . '</td>';
            echo '<td>' . ($product ? esc_html($product->get_name()) : ('#' . intval($r->product_id))) . '</td>';
            echo '<td style="color:red;">' . esc_html(intval($r->stock_quantity)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_transfers_report() {
        global $wpdb;
        $table_transfers = $wpdb->prefix . 'c2p_transfers';
        $table_locations = $wpdb->prefix . 'c2p_locations';

        $rows = $wpdb->get_results("
            SELECT t.*, lf.name as from_name, lt.name as to_name
            FROM $table_transfers t
            LEFT JOIN $table_locations lf ON lf.id = t.from_location_id
            LEFT JOIN $table_locations lt ON lt.id = t.to_location_id
            ORDER BY t.created_at DESC
            LIMIT 50
        ");

        if (!$rows) {
            echo '<p>' . esc_html__('Nenhuma transferência registrada.', 'click2pickup') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Origem', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Destino', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Status', 'click2pickup') . '</th>';
        echo '<th>' . esc_html__('Criado em', 'click2pickup') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>#' . esc_html($r->id) . '</td>';
            echo '<td>' . esc_html($r->from_name ?: '-') . '</td>';
            echo '<td>' . esc_html($r->to_name ?: '-') . '</td>';
            echo '<td>' . esc_html($r->status) . '</td>';
            echo '<td>' . esc_html(date_i18n('d/m/Y H:i', strtotime($r->created_at))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}