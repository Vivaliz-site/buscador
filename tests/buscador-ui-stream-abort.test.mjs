import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../admin/buscador.php', import.meta.url), 'utf8');
const script = source.split('<script>\n')[1]?.split('</script>')[0]
  ?.replace(/const CSRF=<\?=.+?\?>;/, "const CSRF='fixture';");
assert.ok(script?.includes('async function run()'));
async function simulate(lines) {
  const nodes = new Map();
  const element = id => {
    if (!nodes.has(id)) nodes.set(id, {
      innerHTML:'', textContent:'', value:id==='message'?'Teste seguro':'',
      style:{}, dataset:{}, classList:{ toggle() {} }, children:[],
      addEventListener() {}, appendChild(child) { this.children.push(child); },
      remove() {}, scrollIntoView() {}, focus() {},
    });
    return nodes.get(id);
  };
  const document = {
    getElementById:element, querySelector:element,
    createElement:()=>({className:'',innerHTML:'',scrollIntoView(){}}),
  };
  const fetch = async (_,options) => {
    if(options?.method !== 'POST')return new Promise(()=>{});
    let position=0;
    return {ok:true,body:{getReader:()=>({
      read:async()=>position<lines.length
        ? {value:new TextEncoder().encode(lines[position++]),done:false}
        : {done:true},
    })}};
  };
  const context=vm.createContext({document,fetch,console,window:{},TextDecoder});
  vm.runInContext(script,context);
  await context.run();
  return {element};
}
const interrupted=await simulate([
  JSON.stringify({type:'cycle_started',cycle_id:'interrupted'})+'\n',
  JSON.stringify({type:'phase_started',phase:'research'})+'\n',
]);
assert.equal(interrupted.element('phase').textContent,'Interrompido',
  'Sem cycle_finished a UI deve informar interrupção do fluxo');
assert.match(interrupted.element('consensus').textContent,/incomplet|interrompid|confirmad/i,
  'O consenso não pode ficar em "Aguardando debate" após EOF inesperado');
assert.notEqual(interrupted.element('#progress span').style.width,'100%',
  'EOF inesperado não deve aparentar execução completa');
const complete=await simulate([
  JSON.stringify({type:'cycle_finished',ok:true,duration_ms:200,
    complete_provider_coverage:true,consensus_available:true,provider_status:{}})+'\n',
]);
assert.equal(complete.element('phase').textContent,'Concluído');
assert.equal(complete.element('#progress span').style.width,'100%');
console.log('BUSCADOR_UI_STREAM_ABORT=PASS');
