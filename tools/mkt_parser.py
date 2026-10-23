"""Processa os 6 relatórios diários SAP (Marketing) e gera/actualiza os datasets JSON do dashboard.

Uso: python3 -I mkt_parser.py <pasta_ficheiros_do_dia> <pasta_historico_entrada_ou_-> <pasta_saida>
Os ficheiros são identificados pelo conteúdo, não pelo nome.
"""
import sys, os, json, glob, unicodedata, re, datetime as dt
import openpyxl


def norm(s):
    s = unicodedata.normalize("NFKD", str(s or "")).encode("ascii", "ignore").decode()
    return re.sub(r"\s+", " ", s).strip().upper()


def num(v):
    if v in (None, "", " "):
        return 0.0
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def rows_of(path):
    wb = openpyxl.load_workbook(path, data_only=True)
    return [list(r) for r in wb.worksheets[0].iter_rows(values_only=True)]


def identify(paths):
    kinds = {}
    for p in paths:
        r = rows_of(p)
        flat = " ".join(str(c) for row in r[:4] for c in row if c is not None)
        if "Avg. Unit Price" in flat:
            k = "itens"
        elif "Storage Location Name" in flat:
            k = "registo"
        elif "Card Amount" in flat:
            k = "consolidado"
        elif "Stock Quantity on Period End" in flat:
            k = "stock"
        elif "SAP SERVER" in flat:
            k = "relatorio"
        elif "DAILY CASH SALES" in flat:
            k = "caixa"
        elif "GENERAL SALES PLAN" in flat:
            k = "transporte"
        else:
            continue
        kinds[k] = r
    obrig = {"itens", "registo", "consolidado", "stock", "relatorio", "caixa"}
    falta = obrig - set(kinds)
    if falta and not (falta == obrig and "transporte" in kinds):
        raise SystemExit(f"Faltam relatórios: {sorted(falta)}")
    return kinds


def parse_day(K):
    # ---------- Registo de vendas (linhas por vendedor) ----------
    linhas, pend = [], []
    dia = None
    nomes = {}
    for r in K["registo"][1:]:
        if r[1] not in (None, "") and str(r[1]).strip().isdigit():
            d = r[2].date() if isinstance(r[2], dt.datetime) else None
            dia = dia or d
            pend.append(dict(org=str(r[0]), doc=str(r[1]), produto=str(r[5]), desc=str(r[6]).strip(),
                             qtd=num(r[7]), preco=num(r[8]), valor=num(r[9])))
        elif any(isinstance(c, str) and c.strip() for c in r[:6]):
            nome = next(c for c in r[:6] if isinstance(c, str) and c.strip())
            if norm(nome) in ("TOTAL SALES", "TOTAL"):
                continue
            nomes[norm(nome)] = re.sub(r"\s+", " ", nome).strip().title()
            for l in pend:
                l["vendedor"] = norm(nome)
            linhas += pend
            pend = []
    dia = dia.isoformat()
    doc_vend = {l["doc"]: l["vendedor"] for l in linhas}

    # ---------- Itens (preço padrão) ----------
    padrao = {}
    for r in K["itens"][1:]:
        if r[1] not in (None, ""):
            padrao[str(r[1])] = dict(std=num(r[9]), minimo=num(r[10]), desc=str(r[2]).strip(), unidade=str(r[3] or "CAR").strip().upper())

    # ---------- Consolidado (facturas) ----------
    facturas = []
    for r in K["consolidado"][1:]:
        if r[4] not in (None, ""):
            facturas.append(dict(fatura=str(r[4]), empresa=str(r[1]).strip(), cliente=str(r[6]).strip(),
                                 tpa=num(r[7]), numerario=num(r[8]), deposito=num(r[9]),
                                 liquido=num(r[10]), iva=num(r[11]), total=num(r[12]),
                                 anulada=bool(str(r[15] or "").strip())))

    # ---------- Controlo de caixa (numerário entregue) ----------
    caixa_v, caixa_tot = {}, 0.0
    for r in K["caixa"]:
        if isinstance(r[1], dt.datetime) and r[2]:
            v = num(r[3]) + num(r[4]) + num(r[5])
            n = norm(r[2])
            caixa_v[n] = caixa_v.get(n, 0) + v
            caixa_tot += v

    # ---------- Relatório de vendas (TPA, saldo de caixa) ----------
    R = K["relatorio"]
    tpa_v, tpa_tot = {}, 0.0
    lab = {}
    in_tpa = False
    for r in R:
        c0 = norm(r[0]) if r and r[0] else ""
        if c0 == "TPA BANK NAME":
            in_tpa = True
            continue
        if c0 == "TOTAL GERAL":
            in_tpa = False
        if in_tpa and r[1] and num(r[2]):
            nome = norm(re.split(r"-", str(r[1]))[-1])
            tpa_v[nome] = tpa_v.get(nome, 0) + num(r[2])
            tpa_tot += num(r[2])
        if len(r) > 3 and r[1] and isinstance(r[1], str):
            lab[norm(r[1])] = r[3] if num(r[3]) or r[3] == 0 else r[2]
        if c0:
            lab.setdefault(c0, r)
    saldo_ini = num(lab.get("BEGINNING CASH"))
    retirada = num(lab.get("WITHDRAWAL"))
    saldo_fim = num(lab.get("CASH CLOSING BALANCE"))

    # ---------- Resumo ----------
    tot = lambda k: round(sum(f[k] for f in facturas if not f["anulada"]), 2)
    unid = lambda prod: padrao.get(prod, {}).get("unidade", "CAR")
    credito_f = {f["fatura"]: round(f["total"] - f["tpa"] - f["numerario"] - f["deposito"], 2) for f in facturas if not f["anulada"]}
    dep_f = {f["fatura"]: f["deposito"] for f in facturas if not f["anulada"]}
    total = tot("total")
    desconto = 0.0
    abaixo_min = 0
    for l in linhas:
        p = padrao.get(l["produto"])
        if p:
            desconto += p["std"] * l["qtd"] - l["valor"]
            if l["preco"] < p["minimo"]:
                abaixo_min += 1
    resumo = dict(
        chave=dia, data=dia, total=total, numerario=tot("numerario"), tpa=tot("tpa"), deposito=tot("deposito"),
        liquido=tot("liquido"), iva=tot("iva"),
        higitec=round(sum(f["total"] for f in facturas if "HIGITEC" in f["empresa"].upper()), 2),
        naval=round(sum(f["total"] for f in facturas if "NAVAL" in f["empresa"].upper()), 2),
        foodtec=round(sum(f["total"] for f in facturas if "FOODTEC" in norm(f["empresa"])), 2),
        credito=round(sum(credito_f.values()), 2),
        sacos=sum(l["qtd"] for l in linhas if unid(l["produto"]) == "BAG"),
        facturas=len([f for f in facturas if not f["anulada"]]), anuladas=len([f for f in facturas if f["anulada"]]),
        clientes=len({norm(f["cliente"]) for f in facturas}),
        caixas=sum(l["qtd"] for l in linhas if unid(l["produto"]) != "BAG"),
        numerario_entregue=round(caixa_tot, 2), tpa_bancos=round(tpa_tot, 2),
        dif_numerario=round(caixa_tot - tot("numerario"), 2), dif_tpa=round(tpa_tot - tot("tpa"), 2),
        saldo_inicial=round(saldo_ini, 2), retirada=round(retirada, 2), saldo_final=round(saldo_fim, 2),
        desconto=round(desconto, 2), linhas_abaixo_minimo=abaixo_min,
    )

    # ---------- Vendedores ----------
    vend = {}
    for l in linhas:
        v = vend.setdefault(l["vendedor"], dict(facturado=0, caixas=0, docs=set(), desconto=0))
        v["facturado"] += l["valor"]
        if unid(l["produto"]) != "BAG":
            v["caixas"] += l["qtd"]
        v["docs"].add(l["doc"])
        p = padrao.get(l["produto"])
        if p:
            v["desconto"] += p["std"] * l["qtd"] - l["valor"]
    vendedores = []
    for n in sorted(set(vend) | set(caixa_v) | set(tpa_v)):
        v = vend.get(n, dict(facturado=0, caixas=0, docs=set(), desconto=0))
        nu, tp = caixa_v.get(n, 0), tpa_v.get(n, 0)
        dep = round(sum(dep_f.get(d, 0) for d in v["docs"]), 2)
        cred = round(sum(credito_f.get(d, 0) for d in v["docs"]), 2)
        vendedores.append(dict(chave=f"{dia}|{nomes.get(n, n.title())}", data=dia, vendedor=nomes.get(n, n.title()),
                               facturado=round(v["facturado"], 2), numerario=round(nu, 2), tpa=round(tp, 2),
                               deposito=dep, credito=cred,
                               diferenca=round(nu + tp + dep + cred - v["facturado"], 2), facturas=len(v["docs"]),
                               caixas=v["caixas"], desconto=round(v["desconto"], 2)))

    # ---------- Stock ----------
    stock = []
    for r in K["stock"][1:]:
        if r[1] not in (None, "") and r[2]:
            stock.append(dict(chave=f"{dia}|{r[2]}|{r[1]}|{r[5]}", data=dia, codigo=str(r[2]),
                              produto=str(r[3]).replace("\xa0", " ").strip(), centro=str(r[1]),
                              armazem=str(r[5]), inicial=num(r[7]), entradas=num(r[8]),
                              saidas=-num(r[9]), final=num(r[10])))

    # ---------- Produtos ----------
    prod = {}
    for l in linhas:
        p = prod.setdefault(l["produto"], dict(desc=l["desc"], qtd=0, valor=0, std=0))
        p["qtd"] += l["qtd"]
        p["valor"] += l["valor"]
    st_fim, st_nome = {}, {}
    for s in stock:
        st_fim[s["codigo"]] = st_fim.get(s["codigo"], 0) + s["final"]
        st_nome[s["codigo"]] = s["produto"]
    produtos = []
    for c in sorted(set(prod) | set(st_fim)):
        p = prod.get(c, dict(desc=None, qtd=0, valor=0))
        std = padrao.get(c, {}).get("std", 0)
        produtos.append(dict(chave=f"{dia}|{c}", data=dia, codigo=c,
                             produto=p["desc"] or padrao.get(c, {}).get("desc") or st_nome.get(c, c),
                             caixas=p["qtd"], unidade=unid(c), valor=round(p["valor"], 2),
                             preco_medio=round(p["valor"] / p["qtd"], 2) if p["qtd"] else None,
                             preco_padrao=std or None,
                             desconto=round(std * p["qtd"] - p["valor"], 2) if std else 0,
                             stock_final=st_fim.get(c)))

    # ---------- Facturas ----------
    fact = []
    for f in facturas:
        fact.append(dict(chave=f"{dia}|{f['fatura']}", data=dia, factura=f["fatura"],
                         empresa=f["empresa"].replace(" LDA", "").replace(" GENERAL TRAD.", "").title(),
                         cliente=f["cliente"], vendedor=nomes.get(doc_vend.get(f["fatura"], ""), ""),
                         tpa=f["tpa"], numerario=f["numerario"], deposito=f["deposito"], total=f["total"],
                         estado="Anulada" if f["anulada"] else "Válida"))
    nomes_ = nomes
    out = dict(resumo=[resumo], vendedores=vendedores, produtos=produtos, facturas=fact, stock=stock)
    if "transporte" in K:
        _, carga = parse_carga(K["transporte"])
        out["transportes"] = aplicar_vendas(carga, dia, linhas, nomes)
    out["_linhas"], out["_nomes"] = linhas, nomes
    return dia, out


def categoria(desc):
    d = norm(desc)
    if d.startswith("FRALDA") or "DIAPER" in d:
        return "fraldas"
    if d.startswith("SABONETE") or "BEAUTY SOAP" in d:
        return "sabonete"
    if d.startswith("SABAO") or d.startswith("SOAP"):
        return "sabao"
    return None


def parse_carga(T):
    """Plano de cargas: um bloco por vendedor (linha com data) seguido de linhas de zonas e itens."""
    blocos, cur, dia = [], None, None
    for r in T[2:]:
        r = list(r) + [None] * 13
        if isinstance(r[0], dt.datetime) and r[1]:
            dia = dia or r[0].date().isoformat()
            cur = dict(vendedor_id=norm(r[1]), vendedor=re.sub(r"\s+", " ", str(r[1])).strip().title(),
                       carregamento=str(r[2] or "").strip().title(), viagens=num(r[4]) or 1,
                       motorista=re.sub(r"\s+", " ", str(r[7] or "")).strip().title(),
                       matricula=str(r[9] or "").strip(), viatura=str(r[10] or "").strip().title(),
                       transportadora=str(r[11] or "").strip().title(), _zonas=[],
                       carga_fraldas=0.0, carga_sabao=0.0, carga_sabonete=0.0)
            blocos.append(cur)
        if cur is None:
            continue
        if r[3]:
            z = re.sub(r"\s+", " ", str(r[3])).strip()
            if z and z.upper() not in [x.upper() for x in cur["_zonas"]]:
                cur["_zonas"].append(z)
        if r[5]:
            c = categoria(r[5])
            if c:
                cur["carga_" + c] += num(r[6])
    for b in blocos:
        b["zonas"] = ", ".join(z.title() for z in b.pop("_zonas"))
        b["n_zonas"] = len([z for z in b["zonas"].split(", ") if z])
        b["carga"] = b["carga_fraldas"] + b["carga_sabao"] + b["carga_sabonete"]
    return dia, blocos


def aplicar_vendas(blocos, dia, linhas, nomes):
    """Junta a cada camião as caixas facturadas pelo vendedor. Sem linhas de vendas (None) fica pendente."""
    vend = {}
    for l in linhas or []:
        c = categoria(l["desc"])
        if c:
            v = vend.setdefault(l["vendedor"], dict(fraldas=0.0, sabao=0.0, sabonete=0.0))
            v[c] += l["qtd"]
    rows = []
    for b in blocos:
        b = dict(b)
        vid = b.get("vendedor_id") or norm(b["vendedor"])
        nome = (nomes or {}).get(vid, b["vendedor"])
        if linhas is None:
            v = None
        else:
            v = vend.get(vid, dict(fraldas=0.0, sabao=0.0, sabonete=0.0))
        b.update(chave=f"{dia}|{nome}", data=dia, vendedor=nome, vendedor_id=vid,
                 vend_fraldas=v["fraldas"] if v else None, vend_sabao=v["sabao"] if v else None,
                 vend_sabonete=v["sabonete"] if v else None)
        b["vendido"] = None if v is None else v["fraldas"] + v["sabao"] + v["sabonete"]
        b["escoamento"] = round(b["vendido"] / b["carga"], 4) if (v is not None and b["carga"]) else None
        rows.append(b)
    return rows


def main():
    src, hist, out = sys.argv[1], sys.argv[2], sys.argv[3]
    paths = [p for p in glob.glob(os.path.join(src, "**", "*.xlsx"), recursive=True)]
    K = identify(paths)
    hist_tr = []
    if hist != "-" and os.path.exists(os.path.join(hist, "transportes.json")):
        hist_tr = json.load(open(os.path.join(hist, "transportes.json")))
    if "registo" not in K:
        dia, carga = parse_carga(K["transporte"])
        novos = dict(transportes=aplicar_vendas(carga, dia, None, None))
        print("Modo só plano de cargas: vendas pendentes para", dia)
    else:
        dia, novos = parse_day(K)
        if "transportes" not in novos:
            antigos = [t for t in hist_tr if t["data"] == dia]
            if antigos:
                novos["transportes"] = aplicar_vendas(antigos, dia, novos["_linhas"], novos["_nomes"])
                print("Plano de cargas do histórico reutilizado para", dia)
            else:
                print("Aviso: sem relatório de transportes neste dia")
    novos.pop("_linhas", None); novos.pop("_nomes", None)
    os.makedirs(out, exist_ok=True)
    for nome, rows in novos.items():
        antigos = []
        hp = os.path.join(hist, nome + ".json") if hist != "-" else None
        if hp and os.path.exists(hp):
            antigos = [r for r in json.load(open(hp)) if r["data"] != dia]  # reprocessar o dia substitui-o
        novos_campos = dict(resumo=dict(foodtec=0, credito=0, sacos=0), vendedores=dict(deposito=0, credito=0),
                            produtos=dict(unidade="CAR")).get(nome, {})
        for r in antigos:
            for k, v in novos_campos.items():
                r.setdefault(k, v)
        todos = sorted(antigos + rows, key=lambda r: (r["data"], r["chave"]))
        json.dump(todos, open(os.path.join(out, nome + ".json"), "w"), ensure_ascii=False)
    print("Dia processado:", dia)
    if "resumo" in novos:
        print(json.dumps(novos["resumo"][0], ensure_ascii=False, indent=1))
    for v in novos.get("vendedores", []):
        print(v["vendedor"], v["facturado"], v["numerario"], v["tpa"], v["diferenca"])
    for t in novos.get("transportes", []):
        print("TR", t["vendedor"], t["motorista"], t["matricula"], t["n_zonas"], t["carga"], t["vendido"], t["escoamento"])


if __name__ == "__main__":
    main()
