# Análise Click2Pickup

## Objetos de análise

1. Estrutura do projeto
2. Segurança
3. Complexidade e qualidade do código

## Tecnologias

#### PHP
- **Versão Mínima Requerida**: 7.4

#### WordPress
- **Versão Mínima**: 5.8

#### WooCommerce
- **Versão Mínima**: 6.0

### Frontend

#### JavaScript
- **Biblioteca Principal**: jQuery (nativo do WordPress)

#### CSS
- **Framework**: CSS customizado

### Banco de Dados

#### MySQL
- **Tabelas Customizadas**:
  - `wp_c2p_stock` (gerenciamento de produtos em diferentes locais)
  - `wp_c2p_stock_ledger` (sistema de auditoria)
  - Integração com tabelas WooCommerce (produtos, pedidos)

## Estrutura

O plugin apresenta uma boa estrutura, seguindo em grande parte os padrões da comunidade WordPress/WooCommerce.

Padrões de código e arquitetura:

- Orientação a objetos: Paradigma de desenvolvimento de software amplamente adotado e recomendado

- Organização de diretórios: Utiliza estrutura de pastas includes, assets, templates e etc. Estrutura recomendada oficialmente para a construção de plugins robustos

- Compatibilidade com HPOS: O Armazenamento de Pedidos de Alto Desempenho é a nova estrutura de banco de dados que o WooCommerce utiliza para gerenciar os dados dos pedidos, ele foi implementado especificamente para atender os cenários de ecommerce e é otimizado para ser escalável e confiável.

**Pontos de atenção**:
- O plugin não possui o arquivo composer.json e realiza autoload manualmente. O autoload é um sistema que carrega as classes PHP somente quando são necessário (importação sob demanda). Hoje isso é realizado manualmente. O principal problema da abordagem atual é que todas as classes são carregadas mesmo quando não há necessidade, aumentando o consumo de memória e processamento.

- O plugin utiliza `error_log` e `wc_get_logger` de forma mista. Isso dificulta o debugging ao espalhar logs em diferentes locais (logs do servidor e logs do WooCommerce).

- Falta de documentação: O projeto depende principalmente de comentários no código para documentação, e a ausência de uma documentação centralizada (como um Wiki, ou arquivos .md detalhados) 
impacta na passagem de contexto para novas pessoas que irão trabalhar no projeto, aumentando ainda mais sua curva de aprendizado. Além disso, limita o potencial do uso da IA na resolução de problemas e desenvolvimento no projeto, uma vez que essa mesma documentação pode ser usada como contexto para guiar as implementações, impactando nos custos de utilização da IA.

## Segurança

- Enpoints api críticos verificam as capacidades que o usuário possui antes de permitir que alguma ação seja realizada
- As consultas realizadas ao banco de dados que recebem dados do usuário usam $wpdb->prepare() (função que faz a sanitização de queries sql antes de serem processadas, evitando ataques de sql injection). OBS: queries que não utilizam a função prepare() não recebem dados de fontes externas (usuário) mas é considerado uma boa prática o uso da função em todas as chamadas.
- Security checks: `if (!defined('ABSPATH')) exit;` em todos os arquivos PHP. Esta validação faz com que a execução de um script seja imediatamente interrompida caso um agente fora do WordPress tente acessar o arquivo diretamente.

**Pontos de Atenção**

- O plugin não implementa rate limiting (limitação de taxa de requisições) em endpoints críticos. Esta ausência expõe o sistema a diversos vetores de ataque:

POST /c2p/v1/stock: Atualização de estoque sem rate limiting. Permite que atacantes executem flood de requisições para sobrecarregar os serviços do plugin.

- POST /wp-admin/admin-ajax.php (action=c2p_check_customer): Endpoint público que, ao receber um e-mail de um cliente existente, retorna dados pessoais sensíveis como nome completo, telefone, CPF e endereço. Permite que um atacante enumere e-mails para coletar dados de todos os usuários da loja.

Dados expostos:

```php
$customer_data = [
    'first_name' => $customer->get_billing_first_name(),
    'last_name' => $customer->get_billing_last_name(),
    'phone' => $customer->get_billing_phone(),
    'cpf' => get_user_meta($user->ID, 'billing_cpf', true),
    'postcode' => $customer->get_billing_postcode(),
    'address_1' => $customer->get_billing_address_1(),
    'address_2' => $customer->get_billing_address_2(),
    'city' => $customer->get_billing_city(),
    'state' => $customer->get_billing_state()
]
```

## Complexidade e qualidade do código

- O código é bem estruturado em classes com responsabilidades claras (ex: Order, Custom_Cart, Rest_Api), facilitando a manutenção. 
- O projeto adota os padrões de codificação do WordPress, com nomes de arquivos, classes e métodos descritivos. 
- O uso de `traits` para dividir as abas de configuração é uma boa prática que evita "super classes" (Classes que fazem de tudo).

**Pontos de atenção**:

- Funções Longas e Complexas: O projeto possui métodos longos e com alta complexidade ciclomática, dificultando a leitura e manutenção. Além disso, para uma IA, isso aumenta a "carga cognitiva" para analisar o código, exigindo mais processamento e contexto (o que pode elevar os custos com a ferramenta), aumentando a probabilidade da IA gerar sugestões incorretas ou com efeitos colaterais indesejados, pois fica mais difícil rastrear todos os possíveis cenários.

Algumas funções com esse problema

- compute_prep_deadline() 
- calculate_store_prep_time()
- identify_api_source()
