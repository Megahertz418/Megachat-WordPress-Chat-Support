(function(){
  const cfg = (window.megahertzChatData || {});
  const EP_CHAT = cfg.ep_chat || '';
  const EP_SEND = cfg.ep_send || '';
  const EP_INBX = cfg.ep_inbx || '';
  const LOGO    = cfg.logo_light || '';
  const root  = document.getElementById('mz-support-root');
  if(!root) return;
  const fab   = document.getElementById('mz-fab');
  const panel = document.getElementById('mz-panel');
  const close = document.getElementById('mz-close');
  const logWrap = document.querySelector('.mz-logwrap');
  const log  = document.getElementById('mz-log');
  const msgI = document.getElementById('mz-msg');
  const send = document.getElementById('mz-send');
  const status = document.getElementById('mz-status');
  const themeBtn = document.getElementById('mz-theme-toggle');
  function setLogo(url){
    if(!url) return;
    const left = root.querySelector('.mz-left');
    if(!left) return;
    let img = left.querySelector('img.mz-logo');
    if(!img){
      img = document.createElement('img');
      img.className = 'mz-logo';
      img.alt = 'logo';
      img.loading = 'lazy';
      img.decoding = 'async';
      left.insertBefore(img, left.firstChild);
    }
    if (img.getAttribute('src') !== url){
      img.style.display = '';
      img.onerror = function(){ this.style.display='none'; };
      img.src = url;
    }
  }
  function ensureLogo(){ if (LOGO) setLogo(LOGO); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureLogo);
  } else { ensureLogo(); }
  try{
    const mo = new MutationObserver(()=> ensureLogo());
    mo.observe(root, { childList:true, subtree:true });
  }catch(_) {}
  const THEME_KEY = 'digitup_theme';
  function applyTheme(t){ if(t==='dark') root.classList.add('mz-dark'); else root.classList.remove('mz-dark'); }
  function currentTheme(){ return root.classList.contains('mz-dark') ? 'dark' : 'light'; }
  (function initTheme(){
    try{
      const saved = localStorage.getItem(THEME_KEY);
      if(saved==='dark' || saved==='light'){ applyTheme(saved); }
      else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) { applyTheme('dark'); }
    }catch(_){}
  })();
  if (themeBtn) {
    themeBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      const next = currentTheme()==='dark' ? 'light' : 'dark';
      applyTheme(next);
      try{ localStorage.setItem(THEME_KEY, next); }catch(_){}
      themeBtn.blur();
    });
  }
  let pending = false;
  function toggle(){ root.classList.toggle('mz-open'); if(root.classList.contains('mz-open') && msgI) msgI.focus(); }
  if (fab)   fab.addEventListener('click', toggle);
  if (close) close.addEventListener('click', toggle);
  function showStatus(el, msg){
    if (!el) return;
    el.textContent = msg || '';
    requestAnimationFrame(()=> el.classList.add('show'));
    setTimeout(()=>{
      el.classList.remove('show');
      const onEnd = (e)=>{
        if(e.propertyName === 'max-height'){
          el.textContent='';
          el.removeEventListener('transitionend', onEnd);
        }
      };
      el.addEventListener('transitionend', onEnd, { once:true });
    }, 1800);
  }
  function showOk(el, msg){
    if(!el) return;
    el.classList.remove('err','ok');
    el.classList.add('ok');
    showStatus(el, msg);
    setTimeout(()=> el.classList.remove('ok'), 2200);
  }
  function showErr(el, msg){
    if(!el) return;
    el.classList.remove('ok','err');
    el.classList.add('err');
    showStatus(el, msg);
    setTimeout(()=> el.classList.remove('err'), 2200);
  }
  function sanitizeAnswerHTML(dirty){
    const allowed = { p:true, ol:true, ul:true, li:true, a:true, strong:true, em:true, br:true };
    const tmp = document.createElement('div'); tmp.innerHTML = dirty;
    (function walk(node){
      Array.from(node.childNodes).forEach(ch=>{
        if(ch.nodeType===1){
          const tag = ch.tagName.toLowerCase();
          if(!allowed[tag]) { ch.replaceWith(document.createTextNode(ch.textContent)); return; }
          if(tag==='a'){
            Array.from(ch.attributes).forEach(a=>{ if(!['href','target','rel'].includes(a.name)) ch.removeAttribute(a.name); });
            const href = ch.getAttribute('href')||'';
            if(!/^https?:\/\//i.test(href)) ch.removeAttribute('href');
            ch.setAttribute('target','_blank'); ch.setAttribute('rel','nofollow noopener noreferrer');
          } else {
            Array.from(ch.attributes).forEach(a=> ch.removeAttribute(a.name));
          }
          walk(ch);
        } else if(ch.nodeType!==3){ ch.remove(); }
      });
    })(tmp);
    return tmp.innerHTML;
  }
  function bubble(html, who){
    const b = document.createElement('div');
    b.className = 'mz-bubble ' + (who==='user'?'mz-user':'mz-bot');
    b.setAttribute('dir','auto');
    if(who==='bot'){ b.innerHTML = sanitizeAnswerHTML(html); } else { b.textContent = html; }
    log.appendChild(b);
    if (logWrap) logWrap.scrollTop = logWrap.scrollHeight;
    return b;
  }
  let typingEl = null;
  function setTyping(show){
    if(show){
      if(typingEl) return;
      typingEl = document.createElement('div');
      typingEl.className = 'mz-bubble mz-bot';
      typingEl.innerHTML = '<div class="mz-typing-bubble"><span></span><span></span><span></span></div>';
      log.appendChild(typingEl);
      if (logWrap) logWrap.scrollTop = logWrap.scrollHeight;
    } else {
      if(typingEl){ typingEl.remove(); typingEl = null; }
    }
  }
  function humanizeChatError(code){
    switch(String(code||'').toLowerCase()){
      case 'too_many_requests': return 'Please wait a few seconds and try again.';
      case 'missing_openai_key':
      case 'missing_gemini_key': return 'AI provider is not configured. Add the API key in Settings.';
      case 'openai_bad_status':
      case 'gemini_bad_status': return 'Provider returned an error. Check your model/API key.';
      case 'bridge_failed': return 'Upstream bridge failed. Try again shortly.';
      case 'empty_message': return 'Type a message first.';
      default: return 'Request failed. Try again.';
    }
  }
  async function sendMsg(){
    if(pending){ showErr(status,'Please wait until the previous reply finishes.'); return; }
    const text = (msgI && (msgI.value||'').trim()) || '';
    if(!text) return;
    if(msgI) msgI.value='';
    pending = true;
    if(send) send.disabled = true;
    bubble(text,'user');
    setTyping(true);
    try{
      const res = await fetch(EP_CHAT, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({message:text})
      });
      let data = null;
      const raw = await res.text();
      try { data = JSON.parse(raw); } catch(_){ data = null; }
      if(!res.ok){
        const msg = res.status === 429 ? 'Please wait a few seconds and try again.' : 'Server error ('+res.status+').';
        setTyping(false);
        bubble('<p>Sorry, something went wrong.</p>','bot');
        showErr(status, msg);
        console.error('CHAT HTTP error', res.status, raw);
        return;
      }
      if(!data || !data.ok){
        const err = (data && data.error) ? humanizeChatError(data.error) : 'Request failed. Try again.';
        setTyping(false);
        bubble('<p>Sorry, something went wrong.</p>','bot');
        showErr(status, err);
        console.error('CHAT logic error', data || raw);
        return;
      }
      const ans = (data.answer || '').trim();
      setTyping(false);
      bubble(ans || '<p>…</p>','bot');
    }catch(err){
      setTyping(false);
      bubble('<p>Sorry, something went wrong.</p>','bot');
      showErr(status, 'Network error. Check connection.');
      console.error('CHAT fetch error', err);
    }finally{
      pending = false;
      if(send) send.disabled = false;
      if(msgI) msgI.focus();
    }
  }
  if (send) send.addEventListener('click', sendMsg);
  if (msgI) msgI.addEventListener('keydown', (e)=>{
    if(e.key==='Enter' && !e.shiftKey){
      e.preventDefault();
      sendMsg();
    }
  });
  const agentOpen = document.getElementById('mz-agent-open');
  const agentBack = document.getElementById('mz-agent-back');
  const agentChat = document.getElementById('mz-agent-chat');
  const agentInput = document.getElementById('mz-agent-input');
  const agentSend = document.getElementById('mz-agent-send');
  const statusAgent = document.getElementById('mz-status-agent');
  const nameI  = document.getElementById('mz-agent-name');
  const phoneI = document.getElementById('mz-agent-phone');
  const emailI = document.getElementById('mz-agent-email');
  const emailWrap = document.getElementById('mz-email-wrap');
  const LS_SID   = 'digitup_sid';
  const LS_NAME  = 'digitup_name';
  const LS_PHONE = 'digitup_phone';
  const LS_EMAIL = 'digitup_email';
  function restoreUser(){
    try{
      const n = localStorage.getItem(LS_NAME) || '';
      const p = localStorage.getItem(LS_PHONE) || '';
      const e = localStorage.getItem(LS_EMAIL) || '';
      if(nameI && n) nameI.value = n;
      if(phoneI && p) phoneI.value = p;
      if(emailI && e) emailI.value = e;
    }catch(_){}
    updateEmailRequiredUI();
  }
  restoreUser();
  function saveUser(){
    try{
      if(nameI)  localStorage.setItem(LS_NAME,  (nameI.value||'').trim());
      if(phoneI) localStorage.setItem(LS_PHONE, (phoneI.value||'').trim());
      if(emailI) localStorage.setItem(LS_EMAIL, (emailI.value||'').trim());
    }catch(_){}
  }
  function getSid(){
    let sid = null;
    try { sid = localStorage.getItem(LS_SID) || null; } catch(_){}
    if(!sid){
      sid = 'sid_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      try { localStorage.setItem(LS_SID, sid); } catch(_){}
    }
    return sid;
  }
  function isValidEmail(s){ return /^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i.test((s||'').trim()); }
  function updateEmailRequiredUI(){
    if(!emailWrap || !emailI) return;
    const has = isValidEmail(emailI.value);
    emailWrap.classList.toggle('need-star', !has);
  }
  if (emailI) emailI.addEventListener('input', updateEmailRequiredUI);
  function agentBubble(text, who, replyToCmid){
    const el = document.createElement('div');
    el.className = 'mz-agent-msg ' + (who==='user' ? 'mz-agent-user' : 'mz-agent-op');
    el.setAttribute('dir','auto');
    if (replyToCmid) {
      const hint = document.createElement('div');
      hint.className = 'mz-agent-reply';
      hint.textContent = 'Reply to: ' + replyToCmid.slice(0,8);
      el.appendChild(hint);
    }
    const content = document.createElement('div');
    content.innerHTML = sanitizeAnswerHTML(text);
    el.appendChild(content);
    agentChat.appendChild(el);
    const wr = document.querySelector('.mz-agent-chatwrap') || agentChat.parentElement;
    if (wr) wr.scrollTop = wr.scrollHeight;
    return el;
  }
  function openAgent(){ root.classList.add('mz-agent-mode'); if(agentInput) agentInput.focus(); startPolling(); }
  function closeAgent(){ root.classList.remove('mz-agent-mode'); stopPolling(); if(msgI) msgI.focus(); }
  if (agentOpen) agentOpen.addEventListener('click', (e)=>{ e.preventDefault(); openAgent(); });
  if (agentBack) agentBack.addEventListener('click', (e)=>{ e.preventDefault(); closeAgent(); });
  function autoResizeTA(){
    if(!agentInput) return;
    agentInput.style.height = 'auto';
    agentInput.style.height = Math.min(180, agentInput.scrollHeight) + 'px';
  }
  if (agentInput) agentInput.addEventListener('input', autoResizeTA);
  let sendingAgent = false;
  function updASend(){
    if(!agentSend) return;
    const hasText = !!(agentInput && agentInput.value.trim().length>0);
    agentSend.disabled = sendingAgent || !hasText;
  }
  if (emailI) emailI.addEventListener('input', updASend);
  if (agentInput) agentInput.addEventListener('input', ()=>{ updASend(); autoResizeTA(); });
  if (agentInput) agentInput.addEventListener('keydown', (e)=>{
    if(e.key==='Enter' && !e.shiftKey){
      e.preventDefault();
      if(agentSend && !agentSend.disabled) agentSend.click();
    }
  });
  if (agentSend) agentSend.addEventListener('click', async ()=>{
    if(sendingAgent){ showErr(statusAgent, 'Sending in progress…'); return; }
    const em = (emailI && emailI.value || '').trim();
    const text = (agentInput && agentInput.value || '').trim();
    if(!text){ updASend(); return; }
    if(!isValidEmail(em)){
      updateEmailRequiredUI();
      showErr(statusAgent, 'Please enter a valid email.');
      if(emailI) emailI.focus();
      return;
    }
    const cmid = self.crypto?.randomUUID ? crypto.randomUUID() : (Math.random().toString(36).slice(2)+'-'+Date.now());
    const el = agentBubble(text,'user');
    el.dataset.cmid = cmid;
    saveUser();
    if(agentInput){ agentInput.value=''; autoResizeTA(); }
    sendingAgent = true; updASend();
    try{
      const res = await fetch(EP_SEND, {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({
          sid: getSid(),
          email: em,
          name: (nameI && nameI.value || '').trim(),
          phone: (phoneI && phoneI.value || '').trim(),
          message: text,
          client_msg_id: cmid
        })
      });
      const data = await res.json();
      if(!data || !data.ok){
        agentBubble('Sorry, sending failed. Please try again.','op');
        showErr(statusAgent, 'Send failed.');
      }else{
        showOk(statusAgent, 'Message sent to admin');
      }
    }catch(_){
      agentBubble('Network error. Please try again.','op');
      showErr(statusAgent, 'Network error');
    }finally{
      sendingAgent=false; updASend();
    }
  });
  let pollTimer = null;
  let polling = false;
  async function pollInbox(){
    if(polling) return;
    polling = true;
    try{
      const sid = getSid();
      const url = new URL(EP_INBX, window.location.origin || undefined);
      url.searchParams.set('sid', sid);
      url.searchParams.set('_t', Date.now());
      const res = await fetch(url.toString(), { method:'GET', headers:{'Accept':'application/json'}, cache:'no-store' });
      const data = await res.json();
      if(data && data.ok && Array.isArray(data.inbox)){
        data.inbox.forEach(item=>{
          if(!item || typeof item.text !== 'string') return;
          agentBubble(item.text, 'op', item.reply_to_client_msg_id || null);
        });
      }
    }catch(_){ /* silent */ }
    finally{ polling = false; }
  }
  function startPolling(){ stopPolling(); pollInbox(); pollTimer = setInterval(pollInbox, 4000); }
  function stopPolling(){ if(pollTimer){ clearInterval(pollTimer); pollTimer = null; } }
  if (panel) {
    panel.addEventListener('transitionend', (e)=>{
      if(root.classList.contains('mz-open') && e.propertyName==='transform' && msgI){ msgI.focus(); }
    });
  }
  updASend();
})();