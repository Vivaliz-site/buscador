import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const credentialFile = process.env.BUSCADOR_ADMIN_CREDENTIAL_FILE || '/home/ubuntu/.config/shopvivaliz-admin-test.credentials.json';
const screenshotPath = process.env.BUSCADOR_UI_SCREENSHOT || '/tmp/buscador-ui-final.png';
const configuredBrowserPath = String(process.env.SHOPVIVALIZ_CHROMIUM_PATH || '').trim();
const configuredProfileDir = String(process.env.BUSCADOR_UI_PROFILE || '').trim();
const ephemeralProfile = configuredProfileDir === '';
const profileDir = configuredProfileDir || fs.mkdtempSync(path.join(os.tmpdir(), 'sv-buscador-audit-'));
const playwrightCandidates = [
  String(process.env.BUSCADOR_PLAYWRIGHT_MODULE || '').trim(),
  '/home/ubuntu/shopvivaliz-deploy/repo/node_modules/playwright/index.js',
  '/home/ubuntu/shopvivaliz-browser-worker/node_modules/playwright-core/index.js',
  '/home/ubuntu/solange-rolla-consultorio/node_modules/playwright/index.js',
];

async function loadChromium() {
  for (const candidate of playwrightCandidates.filter(Boolean)) {
    if (!fs.existsSync(candidate)) continue;
    const mod = await import('file://' + candidate);
    const chromium = mod.chromium || mod.default?.chromium;
    if (chromium) return chromium;
  }
  throw new Error('playwright_runtime_missing');
}

function fail(message) {
  throw new Error('BUSCADOR_UI_AUDIT_FAIL: ' + message);
}

const raw = JSON.parse(fs.readFileSync(credentialFile, 'utf8'));
if (!raw.email || !raw.password) fail('credential_file_invalid');
if (!ephemeralProfile) fs.mkdirSync(profileDir, { recursive: true, mode: 0o700 });

const chromium = await loadChromium();
const browserPath = configuredBrowserPath || chromium.executablePath();
if (!browserPath || !fs.existsSync(browserPath)) fail('chromium_missing');
const context = await chromium.launchPersistentContext(profileDir, {
  executablePath: browserPath,
  headless: true,
  viewport: { width: 1440, height: 900 },
  env: { ...process.env, LIBGL_ALWAYS_SOFTWARE: '1' },
  args: [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-vulkan',
    '--use-gl=swiftshader',
    '--use-angle=swiftshader',
  ],
});
const page = context.pages()[0] || await context.newPage();
page.setDefaultTimeout(20000);

try {
  await page.goto('https://shopvivaliz.com.br/admin/buscador.php', { waitUntil: 'domcontentloaded', timeout: 45000 });
  if (page.url().includes('/auth/login.php')) {
    await page.locator('#email').fill(raw.email);
    await page.locator('#password').fill(raw.password);
    await Promise.all([
      page.waitForLoadState('domcontentloaded').catch(() => {}),
      page.locator('button[type="submit"]').click(),
    ]);
  }

  await page.waitForURL(/\/admin\/buscador\.php/, { timeout: 30000 });
  await page.locator('h1').filter({ hasText: 'Buscador' }).waitFor({ state: 'visible' });
  await page.waitForFunction(() => {
    const value = document.querySelector('#models')?.textContent || '';
    return value && !value.includes('Carregando') && !value.includes('Não foi possível');
  }, null, { timeout: 30000 });

  const modelText = (await page.locator('#models').innerText()).trim();
  for (const expected of ['gpt-5.6-terra', 'claude-sonnet-5', 'gemini-2.5-flash', 'Fable: desabilitado']) {
    if (!modelText.includes(expected)) fail('model_contract_missing_' + expected);
  }
  if ((modelText.match(/medium/gi) || []).length < 3) fail('medium_reasoning_not_visible');

  await page.locator('#profile').selectOption('deep_research');
  await page.locator('#mode').selectOption('research');
  const prompt = [
    'Pesquise ofertas reais no Brasil de raquetes de tênis novas, premium/primeira linha.',
    'Critérios: 295–305 g sem corda; cabeça preferencialmente 100 in², aceitando 98–102 in² em oportunidade excepcional; modelos 2020–2025 aceitos.',
    'Priorize liquidação/queima real e desconto alvo acima de 40%, mas registre as melhores oportunidades mesmo abaixo disso.',
    'Compare Head Speed/Extreme/Gravity/Radical MP, Babolat Pure Drive/Pure Aero 98/Pure Strike, Wilson Blade 98/Clash 98/Pro Staff 97, Yonex Ezone 100/VCore 100 e Tecnifibre TF40/TFight 300.',
    'Para cada oferta, exija loja, URL, preço atual, preço anterior quando verificável, desconto calculado e especificações. Não invente preço, desconto ou disponibilidade.',
    'Os três agentes devem pesquisar independentemente, criticar os achados uns dos outros e convergir para 3 oportunidades factualmente sustentadas.',
  ].join(' ');
  await page.locator('#message').fill(prompt);

  await page.locator('#run').click();
  await page.waitForFunction(() => document.querySelector('#run')?.disabled === true, null, { timeout: 5000 });
  await page.waitForFunction(() => {
    const phase = (document.querySelector('#phase')?.textContent || '').trim();
    const run = document.querySelector('#run');
    return phase === 'Concluído' && run && run.disabled === false;
  }, null, { timeout: 1100000 });

  const summary = await page.evaluate(() => {
    const phases = [];
    const messages = [];
    let currentPhase = '';
    for (const node of document.querySelectorAll('#feed > *')) {
      if (node.classList.contains('phase')) {
        currentPhase = (node.textContent || '').trim();
        phases.push(currentPhase);
        continue;
      }
      if (!node.classList.contains('msg')) continue;
      const provider = ['openai','anthropic','gemini'].find(p => node.classList.contains(p)) || 'unknown';
      messages.push({
        phase: currentPhase,
        provider,
        error: node.matches('.msg.error'),
        manual: node.matches('.msg.manual'),
        sourceLinks: node.querySelectorAll('.sources a').length,
        meta: (node.querySelector('.agent small')?.textContent || '').trim(),
      });
    }
    return {
      cycle: (document.querySelector('#cycle')?.textContent || '').trim(),
      phase: (document.querySelector('#phase')?.textContent || '').trim(),
      messageCount: Number((document.querySelector('#messages')?.textContent || '0').trim()),
      duration: (document.querySelector('#duration')?.textContent || '').trim(),
      consensusLength: (document.querySelector('#consensus')?.textContent || '').trim().length,
      phases,
      messages,
      health: Array.from(document.querySelectorAll('#health .pill')).map(x => (x.textContent || '').trim()),
    };
  });

  const expectedPhases = ['Pesquisa independente', 'Contraditório', 'Convergência', 'Síntese de consenso'];
  for (const phase of expectedPhases) {
    if (!summary.phases.includes(phase)) fail('phase_missing_' + phase);
  }
  if (summary.phase !== 'Concluído') fail('cycle_not_finished');
  if (summary.messageCount !== 9 || summary.messages.length !== 9) fail('expected_9_messages');
  if (summary.messages.some(x => x.error)) fail('msg.error_present');
  if (summary.messages.some(x => x.manual)) fail('msg.manual_present');

  for (const provider of ['openai','anthropic','gemini']) {
    const providerMessages = summary.messages.filter(x => x.provider === provider);
    if (providerMessages.length !== 3) fail('provider_message_count_' + provider);
    const research = providerMessages.find(x => x.phase === 'Pesquisa independente');
    if (!research || research.sourceLinks < 1) fail('research_sources_missing_' + provider);
  }
  if (summary.consensusLength < 120) fail('consensus_too_short');
  if (summary.health.length !== 3 || summary.health.some(x => !x.includes('verificado'))) {
    fail('final_provider_health_not_verified');
  }

  await page.screenshot({ path: screenshotPath, fullPage: true });
  console.log(JSON.stringify({
    cycle: summary.cycle,
    phase: summary.phase,
    messages: summary.messageCount,
    phases: summary.phases,
    providerCounts: Object.fromEntries(['openai','anthropic','gemini'].map(p => [p, summary.messages.filter(x => x.provider === p).length])),
    researchSourceCounts: Object.fromEntries(['openai','anthropic','gemini'].map(p => [p, summary.messages.find(x => x.provider === p && x.phase === 'Pesquisa independente')?.sourceLinks || 0])),
    health: summary.health,
    consensusLength: summary.consensusLength,
    duration: summary.duration,
    screenshot: screenshotPath,
  }));
  console.log('BUSCADOR_UI_AUDIT=PASS');
} finally {
  await context.close().catch(() => {});
  if (ephemeralProfile) fs.rmSync(profileDir, { recursive: true, force: true });
}
