# Click2Pickup - Sistema de Estoque Multi-Local para WooCommerce

Plugin WordPress/WooCommerce para gestão de estoque em múltiplos locais com suporte a Centros de Distribuição e Lojas Físicas.

## 📋 Requisitos

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- MySQL 5.7+ ou MariaDB 10.3+

## 🚀 Instalação

### Método 1: Upload Manual

1. Faça o download do plugin
2. Extraia o arquivo ZIP na pasta `/wp-content/plugins/`
3. Ative o plugin através do menu 'Plugins' no WordPress

### Método 2: Instalação via Admin

1. Vá para Plugins > Adicionar Novo no admin do WordPress
2. Clique em "Enviar Plugin"
3. Selecione o arquivo ZIP do plugin
4. Clique em "Instalar Agora"
5. Ative o plugin após a instalação

## 🎯 Funcionalidades

### ✅ Implementadas
- [x] Estrutura básica do plugin
- [x] Sistema de ativação/desativação
- [x] Criação de tabelas do banco de dados
- [x] Sistema de capacidades (roles)

### 🚧 Em Desenvolvimento
- [ ] CRUD de Locais (Centros de Distribuição e Lojas)
- [ ] Gestão de estoque por local
- [ ] Aba customizada na página de produto
- [ ] Dashboard de visão geral

### 📝 Roadmap
- [ ] Checkout em 3 etapas
- [ ] Seletor de local com mapa interativo
- [ ] Validação de estoque em tempo real
- [ ] Sistema de reservas temporárias
- [ ] Transferências entre locais
- [ ] Relatórios e Analytics
- [ ] API REST
- [ ] Notificações (Email/WhatsApp)
- [x] Compatibilidade HPOS
- [ ] Multi-idioma

## 🏗️ Estrutura do Plugin

```

```

## 📊 Tabelas do Banco de Dados

### c2p_locations
Armazena informações dos locais (CDs e Lojas)

### c2p_stock
Controla o estoque de cada produto por local

### c2p_stock_log
Registra todas as movimentações de estoque

### c2p_reservations
Gerencia reservas temporárias durante o checkout

## 🔧 Desenvolvimento

### Configurar Ambiente Local

```bash
# Clonar o repositório
git clone https://github.com/rhpimenta/click2pickup.git

# Entrar na pasta do plugin
cd click2pickup

# Instalar dependências (quando adicionarmos composer)
composer install

# Instalar dependências NPM (quando adicionarmos build)
npm install
```

### Hooks Disponíveis

```php
// Após criar um local
do_action('c2p_after_location_created', $location_id, $location_data);

// Antes de atualizar estoque
apply_filters('c2p_before_stock_update', $quantity, $product_id, $location_id);

// Após movimentação de estoque
do_action('c2p_stock_movement', $product_id, $location_id, $quantity_change, $reason);
```

## 📝 Licença

GPL v2 ou posterior

## 👨‍💻 Autor

**RH Pimenta**
- GitHub: [@rhpimenta](https://github.com/rhpimenta)

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor, leia as diretrizes de contribuição antes de enviar um PR.

## 📞 Suporte

Para suporte, abra uma issue no [GitHub](https://github.com/rhpimenta/click2pickup/issues)
