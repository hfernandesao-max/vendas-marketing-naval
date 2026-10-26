// Testes automáticos do portal (Playwright).
//  - projecto «portal»: o portal no navegador, servido como ficheiros estáticos, com a API do Pinguim BO simulada;
//  - projecto «servidor»: os serviços PHP com contas, perfis e um Pinguim BO de teste (tests/servidor/).
// Localmente: npx playwright install chromium (uma vez) e npm test.
import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';

// Nomes de ficheiro com acentos (ex.: «Gestão») precisam de um ambiente UTF-8 no navegador dos testes
process.env.LC_ALL = process.env.LC_ALL || 'C.UTF-8';

const ENV_TESTE = fileURLToPath(new URL('./tests/servidor/ambiente/.env', import.meta.url));

export default defineConfig({
  timeout: 60_000,
  expect: { timeout: 10_000 },
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['list'], ['github'], ['html', { open: 'never' }]] : [['list']],
  use: { timezoneId: 'Africa/Luanda', locale: 'pt-PT', trace: 'retain-on-failure' },
  projects: [
    { name: 'portal', testDir: 'tests/portal', use: { baseURL: 'http://127.0.0.1:8765' } },
    { name: 'servidor', testDir: 'tests/servidor', use: { baseURL: 'http://127.0.0.1:8810' } },
  ],
  webServer: [
    {
      command: 'python3 -m http.server 8765 --bind 127.0.0.1',
      url: 'http://127.0.0.1:8765/reports/index.html',
      reuseExistingServer: !process.env.CI,
      stderr: 'ignore',
    },
    {
      command: 'php -S 127.0.0.1:8801 -t tests/servidor/pinguim',
      url: 'http://127.0.0.1:8801/ping.php',
      reuseExistingServer: !process.env.CI,
      stderr: 'ignore',
    },
    {
      command: 'php tests/servidor/preparar.php && php -S 127.0.0.1:8810',
      url: 'http://127.0.0.1:8810/geo/login.php',
      reuseExistingServer: !process.env.CI,
      env: { NAVAL_ENV: ENV_TESTE },
      stderr: 'ignore',
    },
  ],
});
