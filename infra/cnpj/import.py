#!/usr/bin/env python3
"""
Newton CNPJ — Importador Receita Federal -> PostgreSQL

Uso:
    python import.py --dir /caminho/para/zips --dsn "postgresql://user:pass@host:5432/db"

Arquivos esperados em --dir:
    Empresas0.zip .. Empresas9.zip
    Estabelecimentos0.zip .. Estabelecimentos9.zip
    Socios0.zip .. Socios9.zip
    Simples.zip
    Cnaes.zip, Municipios.zip, Naturezas.zip, Qualificacoes.zip, Paises.zip, Motivos.zip

Características:
    - COPY FROM STDIN: ~10x mais rápido que INSERT
    - Encoding ISO-8859-1 da RF convertido para UTF-8
    - Datas YYYYMMDD convertidas para DATE
    - Flush a cada 100k linhas para controlar memória
"""

import argparse
import csv
import io
import os
import sys
import time
import zipfile

try:
    import psycopg2
except ImportError:
    print("Instale: pip install psycopg2-binary")
    sys.exit(1)


TABLE_MAP = {
    'empresas': (
        'rf_empresas',
        ['cnpj_basico', 'razao_social', 'natureza_juridica',
         'qualificacao_responsavel', 'capital_social', 'porte_empresa',
         'ente_federativo'],
    ),
    'estabelecimentos': (
        'rf_estabelecimentos',
        ['cnpj_basico', 'cnpj_ordem', 'cnpj_dv', 'identificador_mf',
         'nome_fantasia', 'situacao_cadastral', 'data_situacao_cadastral',
         'motivo_situacao_cadastral', 'nome_cidade_exterior', 'pais',
         'data_inicio_atividade', 'cnae_principal', 'cnae_secundaria',
         'tipo_logradouro', 'logradouro', 'numero', 'complemento', 'bairro',
         'cep', 'uf', 'municipio', 'ddd1', 'telefone1', 'ddd2', 'telefone2',
         'ddd_fax', 'fax', 'email', 'situacao_especial',
         'data_situacao_especial'],
    ),
    'socios': (
        'rf_socios',
        ['cnpj_basico', 'identificador_socio', 'nome_socio',
         'cnpj_cpf_socio', 'qualificacao_socio', 'data_entrada_sociedade',
         'pais', 'representante_legal', 'nome_representante',
         'qualificacao_representante', 'faixa_etaria'],
    ),
    'simples': (
        'rf_simples',
        ['cnpj_basico', 'opcao_simples', 'data_opcao_simples',
         'data_exclusao_simples', 'opcao_mei', 'data_opcao_mei',
         'data_exclusao_mei'],
    ),
    'cnaes':        ('rf_cnaes',        ['codigo', 'descricao']),
    'municipios':   ('rf_municipios',   ['codigo', 'descricao']),
    'naturezas':    ('rf_naturezas',    ['codigo', 'descricao']),
    'qualificacoes':('rf_qualificacoes',['codigo', 'descricao']),
    'paises':       ('rf_paises',       ['codigo', 'descricao']),
    'motivos':      ('rf_motivos',      ['codigo', 'descricao']),
}

DATE_COLS = {
    'data_situacao_cadastral', 'data_inicio_atividade',
    'data_entrada_sociedade',  'data_opcao_simples',
    'data_exclusao_simples',   'data_opcao_mei',
    'data_exclusao_mei',       'data_situacao_especial',
}

NUMERIC_COLS = {'capital_social'}


def fix_value(col: str, val: str) -> str:
    val = val.strip()
    if col in DATE_COLS:
        if len(val) == 8 and val.isdigit() and val != '00000000':
            return f"{val[0:4]}-{val[4:6]}-{val[6:8]}"
        return r'\N'
    if col in NUMERIC_COLS:
        return val.replace(',', '.') if val else '0'
    return (val
            .replace('\\', '\\\\')
            .replace('\n', '\\n')
            .replace('\r', '\\r')
            .replace('\t', '\\t'))


def import_file(conn, zip_path: str, table: str, columns: list, truncate: bool = False) -> int:
    with zipfile.ZipFile(zip_path, 'r') as zf:
        names = zf.namelist()
        if not names:
            print(f"  ZIP vazio: {zip_path}")
            return 0

        total_rows = 0
        with conn.cursor() as cur:
            if truncate:
                print(f"  Truncando {table}...")
                cur.execute(f'TRUNCATE TABLE {table}')

            for name in names:
                print(f"  {name} -> {table} ...", end='', flush=True)
                t0 = time.time()

                with zf.open(name) as raw:
                    text = io.TextIOWrapper(raw, encoding='iso-8859-1', newline='')
                    reader = csv.reader(text, delimiter=';', quotechar='"')

                    buf  = io.StringIO()
                    rows = 0
                    for row in reader:
                        if len(row) < len(columns):
                            row += [''] * (len(columns) - len(row))
                        fixed = [fix_value(c, row[i]) for i, c in enumerate(columns)]
                        buf.write('\t'.join(fixed) + '\n')
                        rows += 1

                        if rows % 100_000 == 0:
                            buf.seek(0)
                            cur.copy_from(buf, table, sep='\t', null=r'\N', columns=columns)
                            buf = io.StringIO()
                            print(f'  {rows:,}', end='', flush=True)

                    if buf.tell() > 0:
                        buf.seek(0)
                        cur.copy_from(buf, table, sep='\t', null=r'\N', columns=columns)

                total_rows += rows
                elapsed = time.time() - t0
                print(f" {rows:,} linhas ({elapsed:.1f}s, {rows/max(elapsed,1):,.0f}/s)")

        conn.commit()
        return total_rows


def find_zips(directory: str, prefix: str) -> list:
    files = []
    for f in sorted(os.listdir(directory)):
        if f.lower().startswith(prefix.lower()) and f.lower().endswith('.zip'):
            files.append(os.path.join(directory, f))
    return files


def main():
    parser = argparse.ArgumentParser(description='Newton CNPJ — Import RF')
    parser.add_argument('--dir',      required=True, help='Diretório com os ZIPs da RF')
    parser.add_argument('--dsn',      required=True, help='PostgreSQL DSN')
    parser.add_argument('--only',     help='Importar apenas esta chave (ex: estabelecimentos)')
    parser.add_argument('--truncate', action='store_true', help='Truncar antes de importar')
    args = parser.parse_args()

    if not os.path.isdir(args.dir):
        print(f"Erro: diretório não encontrado: {args.dir}")
        sys.exit(1)

    print(f"\n{'='*60}")
    print(f"Newton CNPJ — Importador RF")
    print(f"Dir : {args.dir}")
    print(f"DB  : {args.dsn.split('@')[-1]}")
    print(f"{'='*60}\n")

    conn = psycopg2.connect(args.dsn)
    conn.autocommit = False

    order = [
        ('cnaes',            ['Cnaes']),
        ('municipios',       ['Municipios']),
        ('naturezas',        ['Naturezas']),
        ('qualificacoes',    ['Qualificacoes']),
        ('paises',           ['Paises']),
        ('motivos',          ['Motivos']),
        ('simples',          ['Simples']),
        ('empresas',         [f'Empresas{i}' for i in range(10)]),
        ('estabelecimentos', [f'Estabelecimentos{i}' for i in range(10)]),
        ('socios',           [f'Socios{i}' for i in range(10)]),
    ]

    t_start     = time.time()
    grand_total = 0

    for key, prefixes in order:
        if args.only and key != args.only:
            continue
        table, columns = TABLE_MAP[key]
        print(f"\n[{key.upper()}] -> {table}")
        first = True
        for prefix in prefixes:
            for zp in find_zips(args.dir, prefix):
                rows = import_file(conn, zp, table, columns, truncate=args.truncate and first)
                grand_total += rows
                first = False

    elapsed = time.time() - t_start
    print(f"\n{'='*60}")
    print(f"Concluído! {grand_total:,} registros em {elapsed/60:.1f} min")
    print(f"{'='*60}\n")
    conn.close()


if __name__ == '__main__':
    main()
