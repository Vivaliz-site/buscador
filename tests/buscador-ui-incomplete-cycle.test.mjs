import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../admin/buscador.php', import.meta.url), 'utf8');
const script = source.split('<script>\n')[1]?.split('</script>')[0]
  ?.replace(/const CSRF=<\?=.+?\?>;/, "const CSRF='fixture';");
assert.ok(script?.includes('function handleEvent(e)'), 'Script do Buscador ausente');

const nodes = new Map();
const element = (id) => {
  if (!nodes.has(id)) {
    nodes.set(id, {
      innerHTML: '', textContent: '', dataset: {}, value: '',
      style: {}, classList: { toggle() {} },
      addEventListener() {}, appendChild() {},
    });
  }
  return nodes.get(id);
};
const document = {
  getElementById: element,
  querySelector: element,
  createElement: () => ({ innerHTML: '', className: '', style: {} }),
};
const context = { document, fetch: () => new Promise(() => {}), console, window: {} };
vm.createContext(context);
vm.runInContext(script, context, { filename: 'buscador-admin-script.js' });

const event = (ok, completeCoverage, consensus) => ({
  type: 'cycle_finished', ok, cycle_id: 'fixture',
  duration_ms: 150, complete_provider_coverage: completeCoverage,
  consensus_available: consensus, provider_status: {}, provider_phase_status: {},
});
context.handleEvent(event(false, false, false));
assert.equal(element('phase').textContent, 'Incompleto',
  'Ciclo sem cobertura nao pode aparecer como Concluido');
assert.match(element('consensus').textContent, /Cobertura incompleta/);

context.handleEvent(event(false, true, false));
assert.equal(element('phase').textContent, 'Incompleto',
  'Falha de moderacao nao pode aparecer como Concluido');
assert.match(element('consensus').textContent, /Consenso indisponivel|Consenso indisponível/,
  'Ausencia da sintese deve ficar visivel em vez de parecer pendente');

context.handleEvent(event(true, true, true));
assert.equal(element('phase').textContent, 'Concluído');
console.log('BUSCADOR_INCOMPLETE_CYCLE=PASS');
