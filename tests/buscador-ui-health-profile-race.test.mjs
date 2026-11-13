import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Reproduce out-of-order network responses while an operator switches profiles.
const html = readFileSync(new URL('../admin/buscador.php', import.meta.url), 'utf8');
const script = html.split('<script>\n')[1]?.split('</script>')[0]
  ?.replace(/const CSRF=<\?=.+?\?>;/, "const CSRF='fixture';");
assert.ok(script?.includes('async function loadHealth()'), 'Buscador health script missing');

const nodes = new Map();
const listeners = new Map();
const element = (id) => {
  if (!nodes.has(id)) {
    nodes.set(id, {
      value: id === 'profile' ? 'deep_research' : '',
      innerHTML: '', textContent: '', title: '', style: {}, children: [],
      classList: { toggle() {} },
      addEventListener(type, fn) { listeners.set(id + ':' + type, fn); },
      appendChild(node) { this.children.push(node); },
    });
  }
  return nodes.get(id);
};
const pending = [];
const fetch = (url) => new Promise(resolve => pending.push({ url, resolve }));
const document = {
  getElementById: element,
  querySelector: (selector) => element(selector),
  createElement: (tag) => ({ tag, className: '', id: '', innerHTML: '', title: '' }),
};
vm.runInNewContext(script, { document, fetch, console, window: {} }, {
  filename: 'admin/buscador.php:script',
});
assert.equal(pending.length, 1);
const originalRequest = pending.shift();
assert.match(originalRequest.url, /profile=deep_research/);

element('profile').value = 'fast';
listeners.get('profile:change')();
assert.equal(pending.length, 1);
const latestRequest = pending.shift();
assert.match(latestRequest.url, /profile=fast/);

const response = (model) => ({
  ok: true,
  json: async () => ({
    ok: true, endpoint: 'buscador',
    providers: { openai: {
      model, reasoning: 'medium', health: 'verified', configured: true,
      transport_order: ['codex_chatgpt'],
    } },
  }),
});
latestRequest.resolve(response('fast-current-model'));
await new Promise(resolve => setImmediate(resolve));
assert.match(element('models').textContent, /fast-current-model/);

originalRequest.resolve(response('slow-obsolete-model'));
await new Promise(resolve => setImmediate(resolve));
assert.match(element('models').textContent, /fast-current-model/,
  'An earlier slow health response must not replace the current profile health');
assert.doesNotMatch(element('models').textContent, /slow-obsolete-model/);
console.log('BUSCADOR_HEALTH_PROFILE_RACE=PASS');
