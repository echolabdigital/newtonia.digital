#!/usr/bin/env bash
# Newton CNPJ — Setup PostgreSQL 16 no Vultr Ubuntu 22.04
# Executar como root: bash setup-vultr.sh
#
# Pré-requisito: VPS Ubuntu 22.04, mínimo 4 vCPU / 16GB RAM / 400GB NVMe
# (recomendado: Vultr High Frequency, plano $80-120/mês)

set -euo pipefail

DB_NAME="newton_cnpj"
DB_USER="newton"
DB_PASS="${CNPJ_DB_PASS:-TROQUE_ESTA_SENHA}"
SCHEMA_PATH="$(dirname "$0")/schema.sql"

echo "=== Newton CNPJ — Setup ==="
echo "Banco  : $DB_NAME"
echo "Usuário: $DB_USER"
echo ""

# --- Instalar PostgreSQL 16 ---
apt-get update -q
apt-get install -y curl gnupg lsb-release
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    | gpg --dearmor -o /etc/apt/trusted.gpg.d/postgresql.gpg
echo "deb [signed-by=/etc/apt/trusted.gpg.d/postgresql.gpg] \
https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    > /etc/apt/sources.list.d/pgdg.list
apt-get update -q
apt-get install -y postgresql-16 python3-pip
pip3 install psycopg2-binary --quiet

# --- Configurar PostgreSQL para desempenho de importação ---
PG_CONF="/etc/postgresql/16/main/postgresql.conf"
sed -i "s/^#shared_buffers.*/shared_buffers = 4GB/"           "$PG_CONF"
sed -i "s/^#work_mem.*/work_mem = 256MB/"                    "$PG_CONF"
sed -i "s/^#maintenance_work_mem.*/maintenance_work_mem = 2GB/" "$PG_CONF"
sed -i "s/^#wal_buffers.*/wal_buffers = 64MB/"               "$PG_CONF"
sed -i "s/^#checkpoint_completion_target.*/checkpoint_completion_target = 0.9/" "$PG_CONF"

systemctl enable postgresql
systemctl restart postgresql

# --- Criar database e usuário ---
sudo -u postgres psql <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$DB_USER') THEN
        CREATE USER $DB_USER WITH PASSWORD '$DB_PASS';
    END IF;
END\$\$;
SELECT 'user ok';
CREATE DATABASE IF NOT EXISTS $DB_NAME OWNER $DB_USER;
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
SQL

# --- Aplicar schema ---
if [ -f "$SCHEMA_PATH" ]; then
    sudo -u postgres psql -d "$DB_NAME" -f "$SCHEMA_PATH"
    echo "Schema aplicado."
else
    echo "AVISO: schema.sql não encontrado em $SCHEMA_PATH — aplique manualmente."
fi

echo ""
echo "=== Concluído ==="
echo "DSN: postgresql://$DB_USER:$DB_PASS@localhost:5432/$DB_NAME"
echo ""
echo "Próximo passo:"
echo "  python3 import.py \\"
echo "    --dir /caminho/para/zips-rf \\"
echo "    --dsn 'postgresql://$DB_USER:$DB_PASS@localhost/$DB_NAME' \\"
echo "    --truncate"
echo ""
echo "Estimativa: 4-8 horas para importação completa (~60M estabelecimentos)"
