# HERMES.b2b — Deploy Guide (Vultr)

> Passo a passo completo para subir o app em produção no Vultr.  
> **Tempo estimado:** 45–90 min (primeira vez).

---

## Pré-requisitos

| Item | Valor |
|------|-------|
| Domínio | `hermesb2b.co` (registrado) |
| App URL | `https://app.hermesb2b.co` |
| Banco CNPJ (PostgreSQL) | `216.238.124.50` — já disponível |
| Asaas conta prod | https://www.asaas.com → gerar chave de produção |

---

## 1. Provisionamento do servidor Vultr

1. Login em [my.vultr.com](https://my.vultr.com)
2. **Deploy New Server → Cloud Compute**
   - OS: **Ubuntu 22.04 LTS**
   - Plano: **2 vCPUs / 4 GB RAM** (mínimo; 8 GB recomendado)
   - Region: São Paulo (BRA) ou qualquer Americas
   - Enable: Backups automáticos (recomendado)
3. Anote o IP público do servidor (ex.: `45.77.x.x`)

---

## 2. Acesso inicial e hardening básico

```bash
ssh root@45.77.x.x

# Atualiza pacotes
apt update && apt upgrade -y

# Cria usuário deploy
adduser deploy
usermod -aG sudo deploy

# Copia chave SSH pro novo usuário
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy

# Desabilita login por senha (opcional mas recomendado)
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh
```

---

## 3. Instalar LAMP (Apache + MySQL + PHP 8.0)

```bash
# Apache
apt install -y apache2

# MySQL
apt install -y mysql-server
mysql_secure_installation   # senha root: use algo forte

# PHP 8.0 + extensões necessárias
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.0 php8.0-mysql php8.0-pgsql php8.0-curl \
               php8.0-mbstring php8.0-xml php8.0-zip php8.0-pdo \
               libapache2-mod-php8.0

# Habilita módulos Apache
a2enmod rewrite headers deflate expires
systemctl restart apache2
```

---

## 4. Banco MySQL — criar banco e usuário

```sql
-- mysql -u root -p
CREATE DATABASE newtonia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'newton'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
GRANT ALL PRIVILEGES ON newtonia.* TO 'newton'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Importar schema

```bash
mysql -u newton -p newtonia < /var/www/html/infra/install-prod.sql
```

---

## 5. Clone do repositório

```bash
cd /var/www/html

# Limpa o diretório padrão do Apache
rm -f index.html

# Clone o repo (ajuste a URL se necessário)
git clone https://github.com/echolabdigital/app-newton.git .

# Permissões
chown -R www-data:www-data /var/www/html
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;
```

---

## 6. Configurar config.php para produção

Edite `/var/www/html/config.php`:

```php
<?php
error_reporting(0);   // nunca exibir erros em prod

// ── MySQL (prod) ──────────────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'newtonia');
define('DB_USER', 'newton');
define('DB_PASS', 'SENHA_FORTE_AQUI');

// ── PostgreSQL (CNPJ — servidor remoto) ──────────────────────────────────────
define('CNPJ_DB_HOST', '216.238.124.50');
define('CNPJ_DB_PORT', '5432');
define('CNPJ_DB_NAME', 'newton_cnpj');
define('CNPJ_DB_USER', 'newton');
define('CNPJ_DB_PASS', 'Newton2026xpto');

// ── App ───────────────────────────────────────────────────────────────────────
define('APP_URL',  'https://app.hermesb2b.co');
define('APP_ENV',  'production');
define('APP_NAME', 'HERMES.b2b');
define('SESSION_NAME',     'hermes_sess');
define('SESSION_LIFETIME', 86400 * 30);

// ── Core ──────────────────────────────────────────────────────────────────────
$_core = __DIR__ . '/core/';
require_once $_core . 'db.php';
// ... (resto igual ao local)
```

> **NUNCA** commite `config.php` com credenciais. Adicione ao `.gitignore`.

---

## 7. Virtual Host Apache

```bash
nano /etc/apache2/sites-available/hermesb2b.conf
```

```apache
<VirtualHost *:80>
    ServerName app.hermesb2b.co
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/hermes-error.log
    CustomLog ${APACHE_LOG_DIR}/hermes-access.log combined
</VirtualHost>
```

```bash
a2ensite hermesb2b.conf
a2dissite 000-default.conf
systemctl reload apache2
```

---

## 8. DNS — apontar subdomínio

No painel do registrador do `hermesb2b.co`:

| Tipo | Host | Valor |
|------|------|-------|
| A | `app` | `45.77.x.x` (IP do servidor) |
| A | `@` | `45.77.x.x` (raiz — LP futura) |

> Propagação DNS: até 24h (normalmente < 1h).

---

## 9. SSL com Certbot (HTTPS)

```bash
apt install -y certbot python3-certbot-apache

# Gera e instala certificado (Let's Encrypt gratuito)
certbot --apache -d app.hermesb2b.co -d hermesb2b.co

# Renovação automática (já configurada pelo Certbot)
# Verifique com:
certbot renew --dry-run
```

---

## 10. Criar super-admin

```
# No navegador (só funciona de localhost ou IP autorizado)
https://app.hermesb2b.co/setup-admin.php
```

- Preencha email: `contato@echolab.digital`
- Defina senha forte
- **DELETAR IMEDIATAMENTE após uso:**

```bash
rm /var/www/html/setup-admin.php
```

---

## 11. Configurar Asaas (produção)

1. Acesse `https://app.hermesb2b.co/admin/integrations.php`
2. Troque a chave sandbox pela **chave de produção** do Asaas
3. Mude o ambiente para **"Produção"**
4. Clique em **"Gerar novo token"** no campo Webhook Token
5. No painel Asaas (https://www.asaas.com):
   - Vá em **Configurações → Notificações → Webhook**
   - URL: `https://app.hermesb2b.co/webhooks/asaas.php`
   - Token: cole o token gerado no passo 4
   - Eventos: marcar todos (`PAYMENT_*`, `SUBSCRIPTION_*`)

---

## 12. Configurar cron de expiração de trial

```bash
# Edita o crontab do www-data (ou crie um arquivo em /etc/cron.d)
crontab -e -u www-data
```

Adicione:

```
# HERMES.b2b — Expira trials sem pagamento (todo dia às 03:00 BRT = 06:00 UTC)
0 6 * * * php /var/www/html/cron/expire-trials.php >> /var/log/hermes-cron.log 2>&1
```

Verifique se o cron está rodando:

```bash
# Teste manual
php /var/www/html/cron/expire-trials.php

# Monitore o log
tail -f /var/log/hermes-cron.log
```

---

## 13. Smoke test — checklist final

Após o deploy, valide cada item:

- [ ] `https://app.hermesb2b.co/` redireciona para `/login.php` (sem erro SSL)
- [ ] Login com super-admin funciona → abre `/admin/`
- [ ] `/admin/integrations.php` → chave Asaas prod ativa
- [ ] Criar conta via `/signup.php` (fluxo completo)
- [ ] Radar Leads → busca retorna CNPJs do PostgreSQL Vultr
- [ ] Pipeline → criar card funciona
- [ ] Billing → assinar plano → invoice Asaas gerada
- [ ] Webhook Asaas → enviar evento teste → log em `asaas_events`
- [ ] `https://app.hermesb2b.co/pagina-que-nao-existe` → mostra 404 branded
- [ ] `/forgot-password.php` → envia e-mail de reset
- [ ] Cron manual: `php /var/www/html/cron/expire-trials.php` → sem erro

---

## 14. Segurança pós-deploy

```bash
# Confirme que setup-admin.php foi deletado
ls /var/www/html/setup-admin.php   # deve retornar "não encontrado"

# Confirme que cron/ e infra/ estão bloqueados pelo .htaccess
curl -I https://app.hermesb2b.co/cron/expire-trials.php   # deve retornar 403

# Logs de erro Apache
tail -f /var/log/apache2/hermes-error.log
```

---

## Atualizações futuras (deploys seguintes)

```bash
cd /var/www/html
git pull origin main
# Não é necessário reiniciar Apache para PHP — é interpretado a cada request
```

Se houver alterações de schema (novas colunas), rode o script de install novamente — ele é idempotente (IF NOT EXISTS + INSERT IGNORE).

---

## Referências rápidas

| O quê | Onde |
|-------|------|
| Logs Apache | `/var/log/apache2/hermes-error.log` |
| Logs cron | `/var/log/hermes-cron.log` |
| Config app | `/var/www/html/config.php` |
| Schema prod | `/var/www/html/infra/install-prod.sql` |
| Admin painel | `https://app.hermesb2b.co/admin/` |
| Asaas sandbox | https://sandbox.asaas.com |
| Asaas produção | https://www.asaas.com |
| PostgreSQL CNPJ | `216.238.124.50:5432 / newton_cnpj` |
