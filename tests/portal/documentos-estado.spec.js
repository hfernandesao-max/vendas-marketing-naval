// Vendas › Documentos válidos e anulados: linhas por artigo com o estado e o motivo, totais por promotor e por motivo,
// filtro de estado no portal (sem nova leitura) e de tipo de documentos (nova leitura).
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linha = (doc_id, data, rota, promotor, documento, codigo, artigo, quantidade, valor, estado, motivo, anulado_em) => ({
  doc_id,
  data,
  hora: '10:00',
  documento,
  tipo: 'FR',
  tipo_nome: 'Factura-recibo',
  rota,
  promotor,
  codigo,
  artigo,
  quantidade,
  valor,
  estado,
  motivo,
  anulado_em,
  cliente: '',
});

test('documentos válidos e anulados: estado e motivo por linha, % de anulações por promotor e filtro de estado', async ({ page }) => {
  const pedidos = [];
  await page.route('**/v1/documentos-estado**', (r) => {
    pedidos.push(new URL(r.request().url()).searchParams.get('grupo'));
    return r.fulfill({
      json: {
        ok: true,
        linhas: [
          linha('1', '2026-11-26', '1005', 'Olindo Baião', 'FR FR0126S77768N/54', '10000332', 'FRALDAS NAVAL', 100, 1500000, 'valido', '', ''),
          linha(
            '2',
            '2026-11-27',
            '1005',
            'Olindo Baião',
            'FR FR0126S77768N/60',
            '10000015',
            'FRALDAS MIMO',
            50,
            700000,
            'anulado',
            'Armazém errado',
            '2026-11-27 09:12',
          ),
          linha('3', '2026-11-27', '1001', 'Andrea Santos', 'FR FR0126S78519N/40', '10000332', 'FRALDAS NAVAL', 10, 150000, 'valido', '', ''),
        ],
      },
    });
  });
  const erros = await abrir(page, '#documentos-estado', { periodo: { preset: 'semana' } });
  const ORDEM = ['Documentos', 'Anulações', 'Valor anulado', 'Motivo mais frequente'];
  const tile = (n) => page.locator('.tile').nth(ORDEM.indexOf(n));
  await expect(tile('Documentos')).toContainText('Documentos');
  await expect(tile('Documentos')).toContainText('3');
  await expect(tile('Documentos')).toContainText('2 válidos · 1 anulados');
  await expect(tile('Anulações')).toContainText('33');
  await expect(tile('Motivo mais frequente')).toContainText('Armazém errado');
  const t = page.locator('#s-de-linhas');
  await expect(t).toContainText('FR FR0126S77768N/60');
  await expect(t).toContainText('Armazém errado');
  await expect(t.locator('.p-bad')).toHaveText('Anulado');
  await expect(page.locator('#s-de-promotores')).toContainText('Olindo Baião');
  await expect(page.locator('#s-de-motivos')).toContainText('Armazém errado');
  // só anulados: filtra no portal, sem nova leitura
  await page.locator('.mov-filtros select').nth(1).selectOption('anulado');
  await expect(t).not.toContainText('FR FR0126S77768N/54');
  await expect(t).toContainText('FR FR0126S77768N/60');
  expect(pedidos).toEqual(['vendas']);
  // cargas: nova leitura
  await page.locator('.mov-filtros select').nth(0).selectOption('cargas');
  await expect.poll(() => pedidos).toEqual(['vendas', 'cargas']);
  expect(erros).toEqual([]);
});
