=== Click2Pickup (Store Pickup & Multi-Estoque) ===
Contributors: ricardopimenta
Tags: woocommerce, estoque, pickup, retirada, multiloja, multi-estoque
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Descrição ==
Click2Pickup adiciona retirada em loja e multi-estoque por localização ao WooCommerce.
- Estoque por loja (mapa por local)
- Soma/espelho automático para `_stock` e `_stock_status`
- Integração REST para ERPs (leitura/escrita do mapa)
- Fluxo de carrinho em 3 passos (Entrega x Retirada)
- Compatível com YOOtheme/UIkit (carrega assets de forma condicional)

== Instalação ==
1. Faça upload da pasta `click2pickup` para `/wp-content/plugins/`.
2. Ative o plugin em **Plugins** no WordPress.
3. Acesse **Click2Pickup → Configurações** (`admin.php?page=c2p-settings`) para ajustar as opções.
4. Use o shortcode `[c2p_cart]` onde desejar exibir o carrinho em 3 passos.

== Frequently Asked Questions ==
= Onde configuro as lojas? =
No menu **Click2Pickup → Lojas** (CPT).

= Posso integrar com meu ERP via REST? =
Sim. O plugin expõe e aceita o meta `c2p_stock_by_location` (produto/variação), além de controlar o espelhamento para `_stock`.

== Changelog ==
= 1.1.0 =
* Renomeação e padronização para Click2Pickup (textdomain `c2p`, shortcode `[c2p_cart]`).
* Consolidação do bridge/espelho REST e tabela `{prefix}c2p_multi_stock`.
* Enqueue condicional de assets e telas de admin.

== Upgrade Notice ==
1.1.0 – Certifique-se de revisar as **Configurações → ERP (estoque global)** para escolher a estratégia correta de atualização de estoque.
