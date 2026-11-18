#!/usr/bin/env python3
"""Sincroniza os dados estáticos de reports/data com o Pinguim BO (SQL Server)."""

import json
import pathlib
from datetime import date, datetime

import pyodbc

ROOT = pathlib.Path(__file__).resolve().parents[1]
ENV_FILE = ROOT / '.env'
OUTPUT = ROOT / 'reports' / 'data'


def read_env():
    values = {}
    for line in ENV_FILE.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            key, value = line.split('=', 1)
            values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def json_value(value):
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if isinstance(value, float):
        return round(value, 6)
    return value


def write_json(name, value):
    (OUTPUT / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def main():
    env = read_env()
    required = ('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS')
    missing = [key for key in required if not env.get(key)]
    if missing:
        raise RuntimeError('Configuração em falta no .env: ' + ', '.join(missing))

    conn = pyodbc.connect(
        'DRIVER={ODBC Driver 17 for SQL Server};'
        f"SERVER={env['DB_HOST']},{env['DB_PORT']};DATABASE={env['DB_NAME']};"
        f"UID={env['DB_USER']};PWD={env['DB_PASS']};"
        'Encrypt=yes;TrustServerCertificate=yes;APP=PortalRTM-Sync',
        timeout=15,
    )
    cur = conn.cursor()

    cur.execute("""
        SELECT S.CODE, S.DESCRIPTION, E.DESCRIPTION
        FROM STORE S
        LEFT JOIN ENTITY E ON E.CODE = S.CODE AND E.DELETED = 0
        WHERE S.DELETED = 0
        ORDER BY S.CODE
    """)
    rotas = []
    for code, description, entity_description in cur.fetchall():
        rota = str(code).strip()
        if not rota.isdigit() or not (1001 <= int(rota) <= 1011):
            continue
        text = str(entity_description or description or '').strip()
        promoter = text
        if ' - ' in text:
            parts = [part.strip() for part in text.split(' - ')]
            promoter = parts[-1] if len(parts) >= 3 else parts[-1]
        if promoter.upper().startswith('FHR PIV'):
            promoter = rota
        if not promoter:
            promoter = rota
        rotas.append({'chave': rota, 'rota': rota, 'promotor': promoter})
    write_json('rotas.json', rotas)

    cur.execute("""
        SELECT DH.ID, DC.CODE, DC.DESCRIPTION, DH.DOC_PREFIX, DH.DOC_NUMBER,
               DH.DOC_DATE, DH.CLOCK_DATE, S.CODE, DH.ENTITY_NAME,
               DH.ENTITY_TAXNUM, DH.TOTAL, DH.PAID, DH.TOTAL_PAID,
               DH.DELETED, E.INTERNAL_NAME
        FROM DOCHEADER DH
        JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        LEFT JOIN EMPLOYEE E ON E.ENTITY_ID = DH.EMPLOYEE_ID
        WHERE DH.DELETED = 0
          AND DC.CODE IN ('FR', 'FT', 'NC')
          AND NOT EXISTS (
              SELECT 1 FROM DOCHEADEREXTRA ANUL
              WHERE ANUL.DOCHEADER_ID = DH.ID
                AND ANUL.DOCEXTRA_ID = 26003
                AND ANUL.DELETED = 0
                AND LTRIM(RTRIM(COALESCE(ANUL.STRING_VALUE, ''))) <> ''
          )
        ORDER BY DH.CLOCK_DATE, DH.ID
    """)
    vendas = []
    for row in cur.fetchall():
        doc_id, code, description, prefix, number, doc_date, clock_date, store_code, entity_name, tax_number, total, paid, total_paid, deleted, employee_name = row
        if code == 'NC':
            kind = 'Nota de crédito'
        elif code == 'FR':
            kind = 'Factura-recibo'
        else:
            kind = 'Factura'

        rota = str(store_code or '').strip()
        if employee_name:
            route_name = str(employee_name).strip()
            if route_name.isdigit():
                rota = route_name
        prefix_text = str(prefix or '').strip()
        document = f'{prefix_text}/{number}' if prefix_text and number is not None else str(number or '').strip()
        if not document:
            document = str(prefix or code)

        payment_cur = conn.cursor()
        payment_cur.execute("""
            SELECT PM.CODE, PM.DESCRIPTION, DF.PAYVALUE
            FROM DOCFOOTER DF
            JOIN PAYMETHOD PM ON PM.ID = DF.PAYMETHOD_ID
            WHERE DF.DOCHEADER_ID = ? AND DF.DELETED = 0
            ORDER BY DF.ID
        """, doc_id)
        payments = []
        for pm_code, pm_description, pay_value in payment_cur.fetchall():
            payments.append({
                'codigo': str(pm_code or '').strip(),
                'meio': str(pm_description or pm_code or '').strip(),
                'valor': abs(float(pay_value or 0)),
            })
        payment_cur.close()

        total_value = float(total or 0)
        if code == 'NC':
            total_value = -abs(total_value)
        paid_value = abs(float(total_paid or 0)) if code != 'NC' else 0.0
        liquidado = code != 'NC' and (bool(paid) or paid_value >= abs(total_value) - 0.005)
        medio = ', '.join(p['meio'] for p in payments if p['meio']) or None

        vendas.append({
            'chave': document,
            'data': json_value(doc_date),
            'hora': clock_date.strftime('%H:%M') if isinstance(clock_date, datetime) else '',
            'rota': rota,
            'promotor': next((r['promotor'] for r in rotas if r['rota'] == rota), rota),
            'tipo': kind,
            'tipo_codigo': code,
            'nc': code == 'NC',
            'documento': document,
            'cliente': str(entity_name or '').strip(),
            'nif': str(tax_number or '').strip(),
            'liquidado': liquidado,
            'total': total_value,
            'valor_pago': paid_value,
            'pagamentos': payments,
            'meio_pagamento': medio,
        })
    write_json('vendas.json', vendas)

    cur.execute("""
        SELECT EN.ID, EN.CODE, EN.DESCRIPTION, EN.TAX_NUMBER, C.DELETED,
               R.ROTA
        FROM CLIENT C
        JOIN ENTITY EN ON EN.ID = C.ENTITY_ID
        OUTER APPLY (
            SELECT TOP 1 S.CODE AS ROTA
            FROM DOCHEADER DH
            JOIN STORE S ON S.ID = DH.STORE_ID
            WHERE DH.ENTITY_ID = EN.ID AND DH.DELETED = 0
            GROUP BY S.CODE
            ORDER BY COUNT(*) DESC
        ) R
        WHERE C.DELETED = 0 AND EN.DELETED = 0
        ORDER BY EN.CODE, EN.DESCRIPTION
    """)
    clientes = []
    seen = set()
    for entity_id, code, name, tax_number, deleted, route_value in cur.fetchall():
        route = str(route_value or '').strip()
        if not route.isdigit():
            route = ''
        if route not in {r['rota'] for r in rotas}:
            route = ''
        promoter = next((r['promotor'] for r in rotas if r['rota'] == route), '')
        client_code = str(code or '').strip()
        nif = str(tax_number or '').strip()
        client_name = str(name or '').strip()
        key = f'{route}|{client_code}'
        if key in seen:
            continue
        seen.add(key)
        clientes.append({
            'chave': key,
            'rota': route,
            'promotor': promoter,
            'codigo': client_code,
            'nome': client_name,
            'nif': nif,
            'tipo': 'Consumidor Final' if (not nif or nif == '999999999') else 'Cliente',
            'dia': '',
            'estado': 'Ativo',
            'tem_gps': False,
        })
    write_json('clientes.json', clientes)

    conn.close()
    print(json.dumps({'rotas': len(rotas), 'vendas': len(vendas), 'clientes': len(clientes)}, ensure_ascii=False))


if __name__ == '__main__':
    main()
