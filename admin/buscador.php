<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/bootstrap-env.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['buscador_csrf'])) {
    $_SESSION['buscador_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['buscador_csrf'];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buscador — Pesquisa e Debate</title>
<style>
:root{
  color-scheme:light;
  --bg:#f4f6f8;--panel:#fff;--ink:#17212b;--muted:#66727f;--line:#dfe5ea;
  --navy:#1f3a70;--ok:#137a4b;--warn:#9a6700;--bad:#b42318;--soft:#eef3f8;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}
header{background:var(--navy);color:#fff;padding:22px 24px}
header h1{margin:0 0 5px;font-size:1.55rem}
header p{margin:0;opacity:.86}
main{max-width:1280px;margin:0 auto;padding:22px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
.controls{display:grid;grid-template-columns:minmax(0,1fr) 220px 190px;gap:12px;align-items:end}
label{display:block;font-size:.8rem;font-weight:700;color:var(--muted);margin-bottom:6px}
textarea,select{width:100%;border:1px solid #cbd5df;border-radius:10px;background:#fff;color:var(--ink);font:inherit}
textarea{min-height:132px;resize:vertical;padding:12px;line-height:1.45}
select{padding:11px}
.actions{display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap}
button{border:0;border-radius:10px;padding:11px 16px;font-weight:750;cursor:pointer}
button.primary{background:var(--navy);color:#fff}
button.secondary{background:#e9eef4;color:var(--ink)}
button:disabled{opacity:.55;cursor:not-allowed}
.health{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:#fff;border-radius:999px;padding:6px 9px;font-size:.76rem}
.dot{width:8px;height:8px;border-radius:50%;background:#999}
.dot.ok{background:var(--ok)}.dot.warn{background:var(--warn)}.dot.bad{background:var(--bad)}
.meta{margin-top:12px;color:var(--muted);font-size:.83rem}
.workspace{display:grid;grid-template-columns:minmax(0,2fr) minmax(310px,1fr);gap:16px;margin-top:16px}
.feed{min-height:360px}
.empty{padding:48px 18px;text-align:center;color:var(--muted)}
.phase{display:flex;align-items:center;gap:10px;margin:18px 0 9px;color:var(--muted);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.phase:after{content:"";height:1px;background:var(--line);flex:1}
.msg{border:1px solid var(--line);border-radius:12px;padding:14px;margin:10px 0;background:#fff}
.msg.openai{border-left:4px solid #1f3a70}
.msg.anthropic{border-left:4px solid #78573a}
.msg.gemini{border-left:4px solid #3c6ea8}
.msg.error{border-left:4px solid var(--bad);background:#fff7f6}
.msg.manual{border-left:4px solid #b7791f;background:#fffaf0}
.manual-note{font-size:.82rem;color:var(--muted);margin:8px 0}
.manual-prompt{white-space:pre-wrap;overflow-wrap:anywhere;background:#fff;border:1px solid var(--line);border-radius:8px;padding:10px;max-height:320px;overflow:auto}
.manual-copy{margin-top:10px;border:1px solid var(--line);background:#fff;border-radius:8px;padding:8px 12px;font-weight:700;cursor:pointer}
.msg-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:8px}
.agent{font-weight:850}.agent small{font-weight:500;color:var(--muted);display:block;margin-top:2px}
.latency{font-size:.75rem;color:var(--muted);white-space:nowrap}
.msg-body{white-space:pre-wrap;line-height:1.5;overflow-wrap:anywhere}
.sources{margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);font-size:.78rem}
.sources a{display:block;color:#245b93;text-decoration:none;margin:3px 0;overflow-wrap:anywhere}
.side{display:flex;flex-direction:column;gap:16px}
.consensus{border:2px solid #b7c8db;background:#fbfdff}
.consensus h2{margin:0 0 10px;font-size:1.1rem}
.consensus-body{white-space:pre-wrap;line-height:1.52}
.cycle-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.stat{background:var(--soft);border-radius:10px;padding:10px}
.stat b{display:block;font-size:1.02rem}.stat span{font-size:.72rem;color:var(--muted)}
.progress{height:6px;background:#e6ebf0;border-radius:999px;overflow:hidden;margin-top:12px}
.progress>span{display:block;height:100%;width:0;background:var(--navy);transition:width .25s}
.running .progress>span{animation:pulse 1.2s ease-in-out infinite alternate}
@keyframes pulse{from{width:22%}to{width:82%}}
@media(max-width:900px){.controls{grid-template-columns:1fr}.workspace{grid-template-columns:1fr}.health{margin-left:0}}
</style>
</head>
<body>
<header>
  <h1>Buscador — Pesquisa e Debate</h1>
  <p>OpenAI + Claude + Gemini pesquisando, criticando e convergindo com evidências ao vivo.</p>
</header>
<main>
  <section class="panel">
    <div class="controls">
      <div>
        <label for="message">Tarefa</label>
        <textarea id="message" placeholder="Ex.: Pesquise profundamente as melhores oportunidades de raquetes premium em queima de estoque e chegue a 3 escolhas em comum."></textarea>
      </div>
      <div>
        <label for="profile">Perfil de raciocínio</label>
        <select id="profile">
          <option value="deep_research" selected>Pesquisa profunda</option>
          <option value="balanced">Equilibrado</option>
          <option value="fast">Rápido</option>
        </select>
      </div>
      <div>
        <label for="mode">Modo</label>
        <select id="mode">
          <option value="research" selected>Pesquisa + debate completo</option>
          <option value="debate">Pesquisa + contraditório</option>
          <option value="parallel">Pesquisa paralela</option>
        </select>
      </div>
    </div>
    <div class="actions">
      <button id="run" class="primary">Iniciar Buscador</button>
      <button id="clear" class="secondary" type="button">Limpar tela</button>
      <div class="health" id="health"></div>
    </div>
    <div class="meta" id="models">Carregando configuração dos provedores…</div>
    <div class="progress" id="progress"><span></span></div>
  </section>

  <div class="workspace">
    <section class="panel feed" id="feed">
      <div class="empty" id="empty">A interação dos três agentes aparecerá aqui, rodada por rodada.</div>
    </section>

    <aside class="side">
      <section class="panel consensus">
        <h2>Consenso</h2>
        <div class="consensus-body" id="consensus">Ainda não calculado.</div>
      </section>
      <section class="panel">
        <div class="cycle-grid">
          <div class="stat"><b id="cycle">—</b><span>Ciclo</span></div>
          <div class="stat"><b id="phase">—</b><span>Fase atual</span></div>
          <div class="stat"><b id="messages">0</b><span>Respostas válidas</span></div>
          <div class="stat"><b id="duration">—</b><span>Duração</span></div>
        </div>
      </section>
    </aside>
  </div>
</main>

<script>
const API='/api/agent/buscador.php';
const CSRF=<?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
const names={openai:'OpenAI',anthropic:'Claude',gemini:'Gemini'};
const transportNames={codex_chatgpt:'via ChatGPT/Codex',openrouter:'via OpenRouter',manual_chatgpt:'ChatGPT manual'};
let running=false;
let count=0;

function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function phaseName(p){return ({research:'Pesquisa independente',critique:'Contraditório',converge:'Convergência',consensus:'Síntese de consenso'}[p]||p);}
function transportLabel(t){return transportNames[t]||String(t||'direto');}
function fmtMs(ms){if(!Number.isFinite(ms))return '—';return ms<1000?ms+' ms':(ms/1000).toFixed(1)+' s';}
function healthState(p){
  const state=String(p?.health||'');
  if(['verified','configured_unverified','unavailable'].includes(state))return state;
  return p?.configured===true?'configured_unverified':'unavailable';
}
function healthClass(state){return state==='verified'?'ok':state==='configured_unverified'?'warn':'bad';}
function healthLabel(state){return state==='verified'?'verificado':state==='configured_unverified'?'configurado · não verificado':'indisponível';}
function setProviderHealth(provider,state,note=''){
  const pill=document.getElementById('health-'+String(provider||''));
  if(!pill)return;
  pill.innerHTML='<span class="dot '+healthClass(state)+'"></span>'+esc(names[provider]||provider)+' · '+esc(healthLabel(state));
  pill.title=note||healthLabel(state);
}
function linkSource(url){const u=esc(url);return '<a target="_blank" rel="noopener noreferrer" href="'+u+'">'+u+'</a>';}

function addPhase(phase){
  document.getElementById('empty')?.remove();
  const div=document.createElement('div');
  div.className='phase';
  div.textContent=phaseName(phase);
  document.getElementById('feed').appendChild(div);
  document.getElementById('phase').textContent=phaseName(phase);
}

function addMessage(e){
  document.getElementById('empty')?.remove();
  const div=document.createElement('article');
  div.className='msg '+(e.provider||'')+(e.ok===false?' error':'');
  const sources=(e.sources||[]).slice(0,12);
  div.innerHTML='<div class="msg-head"><div class="agent">'+esc(names[e.provider]||e.provider||'Agente')+
    '<small>'+esc(e.model||phaseName(e.phase||''))+' · '+esc(transportLabel(e.transport||'direct'))+'</small></div>'+
    '<div class="latency">'+(e.latency_ms?fmtMs(e.latency_ms):'')+'</div></div>'+
    '<div class="msg-body">'+esc(e.text||e.error||'Sem resposta.')+'</div>'+
    (sources.length?'<div class="sources"><b>Fontes detectadas</b>'+sources.map(linkSource).join('')+'</div>':'');
  document.getElementById('feed').appendChild(div);
  div.scrollIntoView({behavior:'smooth',block:'nearest'});
}

function addManual(e){
  document.getElementById('empty')?.remove();
  const div=document.createElement('article');
  div.className='msg openai manual';

  const head=document.createElement('div');
  head.className='msg-head';
  const agent=document.createElement('div');
  agent.className='agent';
  agent.textContent='OpenAI — intervenção manual necessária';
  const model=document.createElement('small');
  model.textContent=String(e.model||'')+' · '+transportLabel('manual_chatgpt');
  agent.appendChild(model);
  head.appendChild(agent);
  div.appendChild(head);

  const note=document.createElement('div');
  note.className='manual-note';
  note.textContent='Os perfis ChatGPT/Codex ficaram sem capacidade. Use o ChatGPT autenticado para executar este prompt sem recorrer à OpenAI Platform API.';
  div.appendChild(note);

  if(Array.isArray(e.attempts)&&e.attempts.length){
    const attempts=document.createElement('div');
    attempts.className='manual-note';
    attempts.textContent='Tentativas automáticas: '+e.attempts.map(a=>(transportLabel(a.transport)+': '+String(a.class||'falha'))).join(' · ');
    div.appendChild(attempts);
  }

  const promptNode=document.createElement('pre');
  promptNode.className='manual-prompt';
  promptNode.textContent=e.prompt||'';
  div.appendChild(promptNode);

  const button=document.createElement('button');
  button.type='button';
  button.className='manual-copy';
  button.textContent='Copiar prompt';
  button.addEventListener('click',async()=>{
    try{
      await navigator.clipboard.writeText(e.prompt||'');
      button.textContent='Prompt copiado';
    }catch(_){
      button.textContent='Copie o texto acima';
    }
  });
  div.appendChild(button);

  const openChat=document.createElement('button');
  openChat.type='button';
  openChat.className='manual-copy';
  openChat.textContent='Abrir ChatGPT';
  openChat.addEventListener('click',()=>window.open('https://chatgpt.com/','_blank','noopener,noreferrer'));
  div.appendChild(openChat);

  document.getElementById('feed').appendChild(div);
  div.scrollIntoView({behavior:'smooth',block:'nearest'});
}

function setRunning(v){
  running=v;
  document.getElementById('run').disabled=v;
  document.getElementById('profile').disabled=v;
  document.getElementById('mode').disabled=v;
  document.querySelector('.panel')?.classList.toggle('running',v);
  if(!v)document.querySelector('#progress span').style.width='100%';
}

function handleEvent(e){
  if(e.type==='cycle_started'){
    count=0;
    document.getElementById('cycle').textContent=e.cycle_id||'—';
    document.getElementById('messages').textContent='0';
    document.getElementById('duration').textContent='—';
  }else if(e.type==='phase_started'){
    addPhase(e.phase);
  }else if(e.type==='agent_started'){
    setProviderHealth(e.provider,'configured_unverified','Executando chamada real no ciclo atual.');
  }else if(e.type==='agent_message'){
    setProviderHealth(e.provider,'verified','Resposta real recebida no ciclo atual.');
    count++;document.getElementById('messages').textContent=String(count);addMessage(e);
  }else if(e.type==='agent_manual_required'){
    setProviderHealth(e.provider,'configured_unverified','Automação indisponível; intervenção manual necessária.');
    addManual(e);
  }else if(e.type==='agent_error'||e.type==='moderator_error'){
    if(e.provider)setProviderHealth(e.provider,'unavailable','Falha real no ciclo atual.');
    addMessage({...e,text:'Falha do provedor: '+(e.error||'erro não especificado'),ok:false});
  }else if(e.type==='consensus'){
    document.getElementById('consensus').textContent=e.text||'Consenso vazio.';
  }else if(e.type==='cycle_finished'){
    document.getElementById('duration').textContent=fmtMs(e.duration_ms);
    document.getElementById('phase').textContent='Concluído';
  }
}

async function loadHealth(){
  const profile=document.getElementById('profile').value;
  try{
    const r=await fetch(API+'?health=1&profile='+encodeURIComponent(profile),{credentials:'same-origin'});
    const j=await r.json();
    if(!r.ok||j.ok!==true||j.endpoint!=='buscador'||!j.providers||typeof j.providers!=='object'){
      throw new Error('invalid_health_response');
    }
    const health=document.getElementById('health');health.innerHTML='';
    const models=[];
    for(const [id,p] of Object.entries(j.providers||{})){
      const state=healthState(p);
      const pill=document.createElement('span');pill.className='pill';pill.id='health-'+id;
      pill.innerHTML='<span class="dot '+healthClass(state)+'"></span>'+esc(names[id]||id)+' · '+esc(healthLabel(state));
      pill.title=state==='verified'?'Autenticação e disponibilidade comprovadas pelo transporte atual.':state==='configured_unverified'?'Configuração detectada; autenticação só é comprovada por probe/execução real.':'Provider indisponível no health atual.';
      health.appendChild(pill);
      let detail=(names[id]||id)+': '+p.model+' · '+p.reasoning+' · '+healthLabel(state);
      if(id==='openai'&&Array.isArray(p.transport_order)){
        detail+=' · '+p.transport_order.map(transportLabel).join(' → ');
        if(p.codex_web_search_mode){detail+=' · web '+p.codex_web_search_mode;}
      }
      models.push(detail);
    }
    document.getElementById('models').textContent=models.join('  |  ')+'  |  Fable: desabilitado';
  }catch(err){
    document.getElementById('models').textContent='Não foi possível consultar o health do Buscador.';
  }
}

async function run(){
  if(running)return;
  const message=document.getElementById('message').value.trim();
  if(!message){document.getElementById('message').focus();return;}
  document.getElementById('feed').innerHTML='';
  document.getElementById('consensus').textContent='Aguardando debate…';
  document.querySelector('#progress span').style.width='0';
  setRunning(true);
  try{
    const r=await fetch(API,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body:JSON.stringify({message,profile:document.getElementById('profile').value,mode:document.getElementById('mode').value,stream:true})
    });
    if(!r.ok||!r.body){
      const txt=await r.text();throw new Error(txt||('HTTP '+r.status));
    }
    const reader=r.body.getReader();const decoder=new TextDecoder();let buf='';
    while(true){
      const {value,done}=await reader.read();
      if(done)break;
      buf+=decoder.decode(value,{stream:true});
      const lines=buf.split('\n');buf=lines.pop()||'';
      for(const line of lines){
        if(!line.trim())continue;
        try{handleEvent(JSON.parse(line));}catch(_){}
      }
    }
    if(buf.trim()){try{handleEvent(JSON.parse(buf));}catch(_){}}
  }catch(err){
    addMessage({provider:'system',ok:false,text:'Falha ao executar o ciclo: '+String(err.message||err)});
  }finally{
    setRunning(false);
  }
}

document.getElementById('run').addEventListener('click',run);
document.getElementById('clear').addEventListener('click',()=>{
  if(running)return;
  document.getElementById('feed').innerHTML='<div class="empty" id="empty">A interação dos três agentes aparecerá aqui, rodada por rodada.</div>';
  document.getElementById('consensus').textContent='Ainda não calculado.';
  document.getElementById('cycle').textContent='—';document.getElementById('phase').textContent='—';
  document.getElementById('messages').textContent='0';document.getElementById('duration').textContent='—';
  document.querySelector('#progress span').style.width='0';
});
document.getElementById('profile').addEventListener('change',loadHealth);
loadHealth();
</script>
</body>
</html>
