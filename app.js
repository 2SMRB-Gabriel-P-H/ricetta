(() => {
  const STORAGE_KEY = 'mesa-comida-v1';
  const API_BASE = (() => {
    const path = location.pathname;
    if (path.endsWith('/')) return `${path}api`;
    if (path.endsWith('.html')) return `${path.slice(0, path.lastIndexOf('/') + 1)}api`;
    return `${path}/api`;
  })();
  const mealTypes = [
    { key: 'breakfast', label: 'Desayuno', emoji: '☀' },
    { key: 'lunch', label: 'Comida', emoji: '◒' },
    { key: 'snack', label: 'Merienda', emoji: '◉' },
    { key: 'dinner', label: 'Cena', emoji: '☾' }
  ];
  const defaultState = { recipes: [], planner: {} };
  let state = defaultState;
  let writeQueue = Promise.resolve();
  let weekOffset = 0;
  let toastTimer;
  let currentDetailId = null;
  let currentUser = '';
  let currentEmail = '';
  let emailVerified = false;
  let pendingEmail = null;
  const resetToken = new URLSearchParams(location.hash.slice(1)).get('reset') || new URLSearchParams(location.search).get('reset') || '';
  let emailRequired = false;
  let sharedItems = [];
  let saveFailureAt = 0;
  let pendingSave = false;
  let saveGeneration = 0;
  let stateVersion = 0;
  let syncPaused = false;
  let conflictData = null;
  let lastTabSyncAt = 0;
  const SYNC_KEY = 'mesa-comida-sync';
  let syncChannel = null;
  try { syncChannel = 'BroadcastChannel' in window ? new BroadcastChannel('ricetta-state-sync') : null; } catch { syncChannel = null; }
  const $ = (selector) => document.querySelector(selector);
  const showDialog = (selector) => { const dialog = $(selector); if (dialog && !dialog.open) dialog.showModal(); };
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  function normalizeState(value) {
    if (!value || typeof value !== 'object') return { recipes: [], planner: {} };
    const recipes = Array.isArray(value.recipes)
      ? value.recipes.filter((recipe) => recipe && typeof recipe === 'object' && typeof recipe.id === 'string' && typeof recipe.name === 'string')
      : [];
    return {
      recipes,
      planner: value.planner && typeof value.planner === 'object' && !Array.isArray(value.planner) ? value.planner : {}
    };
  }
  function setStorageStatus(online, message='') {
    const pill = $('.today-pill');
    const text = message || (online ? 'Guardado en tu cuenta' : 'Sin conexión · copia local sin sincronizar');
    pill.innerHTML = `<i></i> ${text}`;
    pill.title = online ? 'Tus recetas y planificación se guardan en tu cuenta.' : 'La copia local todavía no se ha sincronizado con el servidor.';
  }
  function cacheLocally() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch { /* El servidor sigue siendo el almacenamiento principal. */ }
  }
  function broadcastSaved() {
    try { syncChannel?.postMessage({ type: 'saved', version: stateVersion }); } catch { /* Sin canal entre pestañas. */ }
    try { localStorage.setItem(SYNC_KEY, String(Date.now())); } catch { /* Sin almacenamiento compartido. */ }
  }
  function handleConflict(conflict) {
    if (!conflict || !Number.isInteger(conflict.stateVersion) || !conflict.state) return;
    syncPaused = true;
    pendingSave = true;
    conflictData = conflict;
    setStorageStatus(false, 'Cambios pendientes · elige qué datos conservar');
    $('#conflict-message').textContent = 'Otra pestaña o dispositivo ha guardado cambios en tu recetario. Elige qué versión quieres conservar; la otra se descartará.';
    showDialog('#conflict-dialog');
    $('#conflict-keep').focus();
  }
  function resolveConflict(useServer) {
    const conflict = conflictData;
    conflictData = null;
    const dialog = $('#conflict-dialog');
    if (dialog.open) dialog.close();
    if (!conflict) { syncPaused = false; return; }
    if (useServer) {
      state = normalizeState(conflict.state);
      stateVersion = conflict.stateVersion;
      cacheLocally();
      pendingSave = false;
      syncPaused = false;
      setStorageStatus(true);
      renderPlanner(); renderRecipes(); renderShared();
      showToast('Se han cargado los datos más recientes.');
    } else {
      stateVersion = conflict.stateVersion;
      syncPaused = false;
      save();
      showToast('Se conservarán los datos de este dispositivo.');
    }
  }
  async function syncFromOtherTab() {
    if (!currentUser || pendingSave || syncPaused || document.querySelector('dialog[open]')) return;
    const now = Date.now();
    if (now - lastTabSyncAt < 1500) return;
    lastTabSyncAt = now;
    try { await loadCurrentUser(); } catch { /* Se conserva la copia local hasta la próxima sincronización. */ }
  }
  function save() {
    cacheLocally();
    pendingSave = true;
    const generation = ++saveGeneration;
    writeQueue = writeQueue.catch(() => {}).then(async () => {
      if (generation !== saveGeneration || syncPaused) return false;
      const response = await fetch(`${API_BASE}/state`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ state, baseVersion: stateVersion }) });
      if (response.status === 401) { handleSessionExpired(); throw new Error('session-expired'); }
      if (response.status === 409) {
        const conflict = await response.json().catch(() => null);
        if (conflict && Number.isInteger(conflict.stateVersion) && conflict.state) {
          handleConflict(conflict);
          return false;
        }
        throw new Error('No se pudo guardar en el servidor');
      }
      if (!response.ok) throw new Error('No se pudo guardar en el servidor');
      const result = await response.json().catch(() => ({}));
      if (Number.isInteger(result.stateVersion)) stateVersion = result.stateVersion;
      saveFailureAt = 0;
      if (generation === saveGeneration) {
        pendingSave = false;
        setStorageStatus(true);
      }
      broadcastSaved();
      return true;
    }).catch((error) => {
      if (error?.message !== 'session-expired' && generation === saveGeneration) {
        pendingSave = true;
        setStorageStatus(false);
        if (Date.now() - saveFailureAt > 8000) {
          saveFailureAt = Date.now();
          showToast('No se pudo guardar en el servidor. Los cambios siguen guardados en este dispositivo.');
        }
      }
      return false;
    });
    return writeQueue;
  }
  async function apiPost(path, body={}) {
    let response;
    try {
      response = await fetch(`${API_BASE}${path}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    } catch {
      throw new Error('No se pudo conectar con el servidor. Revisa tu conexión.');
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.error || 'No se pudo completar la operación.');
      error.status = response.status;
      error.data = data;
      throw error;
    }
    return data;
  }
  function showAuthError(panel, message) { $(`#${panel}-error`).textContent = message || ''; }
  function showAuthPanel(panel) {
    const isRegister = panel === 'register';
    for (const name of ['login','register']) $('#'+name+'-panel').classList.toggle('hidden', name!==panel);
    for (const name of ['login','register']) showAuthError(name, '');
    const field = panel==='register' ? $('#register-username') : $('#login-username');
    field.focus();
  }
  function handleSessionExpired() {
    try { localStorage.removeItem(STORAGE_KEY); } catch { /* Sin almacenamiento disponible. */ }
    currentUser = ''; currentEmail = ''; emailVerified = false; pendingEmail = null;
    sharedItems = []; state = { recipes: [], planner: {} }; pendingSave = false;
    stateVersion = 0; syncPaused = false; conflictData = null; saveGeneration++;
    if ($('#conflict-dialog').open) $('#conflict-dialog').close();
    if ($('#email-dialog').open) $('#email-dialog').close();
    $('#app-shell').classList.add('hidden');
    $('#auth-screen').classList.remove('hidden');
    showAuthPanel('login');
    showToast('Tu sesión ha caducado. Vuelve a iniciar sesión.');
  }
  function enterApp(data) {
    currentUser = data.user;
    currentEmail = data.email || '';
    emailVerified = Boolean(data.emailVerified);
    pendingEmail = data.pendingEmail || null;
    state = normalizeState(data.state);
    stateVersion = Number.isInteger(data.stateVersion) ? data.stateVersion : 0;
    syncPaused = false; conflictData = null; saveGeneration++;
    if ($('#conflict-dialog').open) $('#conflict-dialog').close();
    sharedItems = Array.isArray(data.shares) ? data.shares : [];
    saveFailureAt = 0;
    pendingSave = false;
    $('#user-name').textContent = currentUser;
    $('#user-avatar').textContent = currentUser.slice(0,1).toUpperCase();
    $('#user-email').textContent = currentEmail ? `${currentEmail}${emailVerified ? '' : ' · sin verificar'}` : 'Correo sin añadir';
    $('#session-loading').classList.add('hidden');
    $('#auth-screen').classList.add('hidden');
    $('#app-shell').classList.remove('hidden');
    cacheLocally();
    setStorageStatus(true);
    renderPlanner(); renderRecipes(); renderShared();
    if (!currentEmail) openEmailDialog(true);
  }
  function openEmailDialog(required=false) {
    emailRequired = required;
    $('#account-email-input').value = currentEmail;
    $('#email-current-password').value = '';
    $('#email-error').textContent = '';
    $('#email-help').textContent = pendingEmail
      ? `Pendiente de confirmar: ${pendingEmail}. Puedes solicitar otra confirmación escribiendo una dirección nueva.`
      : !emailVerified
        ? 'Confirma que tienes acceso a este correo para poder recuperar tu cuenta. Escribe la misma dirección y confirma tu contraseña.'
      : 'El correo actual seguirá activo hasta que confirmes el enlace que enviaremos a la nueva dirección.';
    $('#email-dialog-close').classList.toggle('hidden', required);
    $('#email-cancel').classList.toggle('hidden', required);
    showDialog('#email-dialog');
  }
  async function loadCurrentUser() {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    let response;
    try {
      response = await fetch(`${API_BASE}/me`, { cache: 'no-store', signal: controller.signal });
    } finally {
      clearTimeout(timeout);
    }
    if (response.status === 401) {
      if (currentUser) { handleSessionExpired(); return false; }
      $('#session-loading').classList.add('hidden'); $('#auth-screen').classList.remove('hidden'); $('#app-shell').classList.add('hidden'); showAuthPanel('login'); return false;
    }
    if (!response.ok) throw new Error('No se pudo cargar la cuenta desde el servidor.');
    enterApp(await response.json());
    return true;
  }
  async function initialize() {
    try { await loadCurrentUser(); }
    catch { $('#session-loading').classList.add('hidden'); $('#auth-screen').classList.remove('hidden'); $('#app-shell').classList.add('hidden'); showAuthPanel('login'); showAuthError('login', 'No se pudo conectar con el servidor. Recarga la página para volver a intentarlo.'); }
  }

  const pad = (number) => String(number).padStart(2, '0');
  const dateKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  const mondayOf = (date) => { const result = new Date(date); result.setHours(12,0,0,0); result.setDate(result.getDate() - ((result.getDay() + 6) % 7)); return result; };
  const formatLong = (date) => new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'long' }).format(date);
  const formatShort = (date) => new Intl.DateTimeFormat('es-ES', { day: 'numeric' }).format(date);
  function showToast(message) { const toast = $('#toast'); toast.textContent = message; toast.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(() => toast.classList.remove('show'), 2400); }

  function renderPlanner() {
    const today = new Date(); today.setHours(12,0,0,0);
    const start = mondayOf(today); start.setDate(start.getDate() + weekOffset * 7);
    const end = new Date(start); end.setDate(end.getDate() + 6);
    $('#week-label').textContent = start.getMonth() === end.getMonth() ? `${start.getDate()} – ${end.getDate()} ${new Intl.DateTimeFormat('es-ES',{month:'long'}).format(end)} ${end.getFullYear()}` : `${formatLong(start)} – ${formatLong(end)} ${end.getFullYear()}`;
    const grid = $('#week-grid'); grid.innerHTML = '';
    for (let i=0;i<7;i++) {
      const date = new Date(start); date.setDate(start.getDate()+i);
      const key = dateKey(date); const dayPlan = state.planner[key] || {};
      const dayName = new Intl.DateTimeFormat('es-ES',{weekday:'short'}).format(date).replace('.','');
      const card = document.createElement('article'); card.className = `day-card${dateKey(today)===key?' today':''}`;
      card.innerHTML = `<div class="day-head"><span class="day-name">${esc(dayName)}</span><span class="day-number">${formatShort(date)}</span></div><div class="meal-list">${mealTypes.map(meal => {
        const selected = dayPlan[meal.key]; const recipe = selected && state.recipes.find(item=>item.id===selected);
        return `<div class="meal-row"><div class="meal-label"><span class="meal-emoji">${meal.emoji}</span>${meal.label}</div>${recipe ? `<div class="meal-item ${meal.key}"><span>${esc(recipe.name)}</span><button class="remove-meal" data-remove-meal="${esc(key)}" data-type="${meal.key}" aria-label="Quitar ${esc(recipe.name)}">×</button></div>` : ''}<button class="add-meal" data-add-meal="${esc(key)}" data-type="${meal.key}" aria-label="Añadir ${meal.label.toLowerCase()}">+</button></div>`;
      }).join('')}</div>`;
      grid.appendChild(card);
    }
  }

  function renderRecipes() {
    const query = ($('#recipe-search')?.value || '').trim().toLocaleLowerCase('es');
    const recipes = state.recipes.filter(recipe => `${recipe.name} ${recipe.category || ''} ${recipe.ingredients || ''}`.toLocaleLowerCase('es').includes(query));
    $('#recipe-count').textContent = state.recipes.length;
    $('#recipe-total').textContent = `${state.recipes.length} ${state.recipes.length === 1 ? 'receta guardada' : 'recetas guardadas'}`;
    const grid = $('#recipe-grid');
    if (!recipes.length) {
      grid.innerHTML = `<div class="empty-state"><div class="empty-icon">${state.recipes.length ? '⌕' : '♡'}</div><h3>${state.recipes.length ? 'No encontramos esa receta' : 'Tu recetario empieza aquí'}</h3><p>${state.recipes.length ? 'Prueba con otro nombre o ingrediente.' : 'Guarda tus platos favoritos y organízalos en tu menú semanal.'}</p>${state.recipes.length ? '' : '<button class="primary-button" data-new-recipe>＋ Añadir mi primera receta</button>'}</div>`;
      return;
    }
    grid.innerHTML = recipes.map((recipe,index) => `<article class="recipe-card" data-open-recipe="${esc(recipe.id)}"><div class="recipe-art art-${index%6}"><span class="category-tag">${esc(recipe.category)}</span>${esc(recipe.emoji || ['🥗','🍲','🥑','🍝','🥕','🍰'][index%6])}</div><div class="recipe-body"><h3>${esc(recipe.name)}</h3><div class="recipe-meta"><span>◷ ${Number(recipe.time)||0} min</span><span>♧ ${Number(recipe.servings)||1} ${Number(recipe.servings)===1?'ración':'raciones'}</span></div><div class="recipe-actions"><button data-share-recipe="${esc(recipe.id)}">Compartir</button><button data-edit-recipe="${esc(recipe.id)}">Editar</button><button class="delete-recipe" data-delete-recipe="${esc(recipe.id)}">Eliminar</button></div></div></article>`).join('');
  }

  function renderShared() {
    $('#shared-count').textContent = sharedItems.filter(item => !item.copied_at).length;
    const grid = $('#shared-grid');
    if (!sharedItems.length) {
      grid.innerHTML = '<div class="empty-state"><div class="empty-icon">⇩</div><h3>Aún no te han compartido nada</h3><p>Cuando otra persona comparta una receta o una planificación contigo, aparecerá aquí.</p></div>';
      return;
    }
    grid.innerHTML = sharedItems.map(item => `<article class="shared-card"><div class="shared-icon">${item.kind === 'recipe' ? '▤' : '▦'}</div><div class="shared-info"><span class="shared-kind">${item.kind === 'recipe' ? 'RECETA' : 'PLANIFICACIÓN SEMANAL'}</span><h3>${esc(item.title)}</h3><p>Compartido por <strong>${esc(item.sender)}</strong></p></div><div class="shared-actions">${item.copied_at ? '<span class="copied-label">✓ Copiada a tu cuenta</span>' : `<button class="primary-button" data-copy-share="${item.id}">Guardar una copia</button>`}<button class="text-button" data-dismiss-share="${item.id}">Quitar</button></div></article>`).join('');
  }

  function openShareDialog(kind, title, payload) {
    $('#share-kind').value = kind;
    $('#share-payload').value = JSON.stringify(payload);
    $('#share-title').textContent = kind === 'recipe' ? 'Compartir receta' : 'Compartir planificación';
    $('#share-preview').textContent = title;
    $('#share-recipient').value = '';
    $('#share-error').textContent = '';
    showDialog('#share-dialog');
  }

  function shareWeek() {
    const today = new Date(); today.setHours(12,0,0,0);
    const start = mondayOf(today); start.setDate(start.getDate() + weekOffset * 7);
    const planner = {}; const recipeIds = new Set();
    for (let i=0;i<7;i++) {
      const date=new Date(start); date.setDate(start.getDate()+i); const key=dateKey(date);
      const dayPlan=state.planner[key];
      if (dayPlan) {
        const valid={};
        for (const [slot,id] of Object.entries(dayPlan)) {
          if (state.recipes.some(recipe=>recipe.id===id)) { valid[slot]=id; recipeIds.add(id); }
        }
        if (Object.keys(valid).length) planner[key]=valid;
      }
    }
    if (!Object.keys(planner).length) { showToast('Añade alguna comida antes de compartir la semana.'); return; }
    const recipes=state.recipes.filter(recipe=>recipeIds.has(recipe.id));
    const end=new Date(start); end.setDate(start.getDate()+6);
    const label=`Semana del ${formatLong(start)} al ${formatLong(end)}`;
    openShareDialog('planner',label,{weekStart:dateKey(start),planner,recipes});
  }

  function openRecipeForm(recipe=null) {
    $('#recipe-form').reset(); $('#recipe-id').value = recipe?.id || '';
    $('#recipe-dialog-title').textContent = recipe ? 'Editar receta' : 'Nueva receta';
    $('#recipe-name').value = recipe?.name || ''; $('#recipe-category').value = recipe?.category || 'Comida'; $('#recipe-time').value = recipe?.time || 30; $('#recipe-servings').value = recipe?.servings || 2;
    $('#recipe-ingredients').value = recipe?.ingredients || ''; $('#recipe-preparation').value = recipe?.preparation || '';
    showDialog('#recipe-dialog');
  }
  function openMealForm(day,type) {
    if (!state.recipes.length) { showToast('Guarda una receta primero para añadirla al menú.'); openRecipeForm(); return; }
    const date = new Date(`${day}T12:00:00`); const meal = mealTypes.find(item=>item.key===type);
    $('#meal-day').value=day; $('#meal-type').value=type; $('#meal-date-label').textContent=`${meal.label.toUpperCase()} · ${formatLong(date).toUpperCase()}`;
    $('#meal-title').textContent=`Añadir ${meal.label.toLowerCase()}`;
    $('#meal-recipe').innerHTML=state.recipes.map(recipe=>`<option value="${esc(recipe.id)}">${esc(recipe.name)} · ${esc(recipe.category)}</option>`).join('');
    showDialog('#meal-dialog');
  }
  function showRecipe(id) {
    const recipe=state.recipes.find(item=>item.id===id); if(!recipe)return; currentDetailId=id;
    $('#detail-category').textContent=recipe.category.toUpperCase(); $('#detail-title').textContent=recipe.name;
    $('#detail-meta').innerHTML=`<span>◷ ${Number(recipe.time)||0} min</span><span>♧ ${Number(recipe.servings)||1} ${Number(recipe.servings)===1?'ración':'raciones'}</span>`;
    $('#detail-ingredients').innerHTML=String(recipe.ingredients||'').split('\n').filter(Boolean).map(line=>`<li>${esc(line)}</li>`).join('');
    $('#detail-preparation').textContent=recipe.preparation || 'Aún no has añadido los pasos de preparación.'; showDialog('#detail-dialog');
  }
  function showView(view) {
    $('#planner-view').classList.toggle('hidden',view!=='planner');
    $('#recipes-view').classList.toggle('hidden',view!=='recipes');
    $('#shared-view').classList.toggle('hidden',view!=='shared');
    document.querySelectorAll('.nav-link').forEach(link=>{
      const active=link.dataset.view===view;
      link.classList.toggle('active',active);
      if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
    });
    $('#crumb-current').textContent=view==='planner'?'Planificación':view==='recipes'?'Mis recetas':'Compartido contigo';
    if (view==='recipes') renderRecipes(); if (view==='shared') renderShared();
  }

  document.querySelectorAll('[data-toggle-password]').forEach(button=>{
    const input=$(`#${button.dataset.togglePassword}`);
    button.addEventListener('mousedown',event=>event.preventDefault());
    button.addEventListener('click',event=>{
      event.preventDefault();
      const start=input.selectionStart, end=input.selectionEnd;
      const visible=input.type==='password';
      input.type=visible?'text':'password';
      button.setAttribute('aria-pressed',String(visible));
      button.setAttribute('aria-label',visible?'Ocultar contraseña':'Mostrar contraseña');
      button.title=visible?'Ocultar contraseña':'Mostrar contraseña';
      input.focus({preventScroll:true});
      if(start!==null&&end!==null)input.setSelectionRange(start,end);
    });
  });
  $('#show-register').addEventListener('click',()=>showAuthPanel('register'));
  $('#show-login').addEventListener('click',()=>showAuthPanel('login'));
  $('#show-password-reset').addEventListener('click',()=>{
    $('#reset-request-form').reset(); $('#reset-request-error').textContent=''; showDialog('#reset-request-dialog');
  });
  $('#reset-request-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#reset-request-form button[type=submit]'); button.disabled=true; $('#reset-request-error').textContent='';
    try { const result=await apiPost('/password-reset-request',{email:$('#reset-request-email').value.trim()}); $('#reset-request-error').textContent=result.message; }
    catch(error){$('#reset-request-error').textContent='No se pudo procesar la solicitud. Inténtalo de nuevo más tarde.';}
    finally{button.disabled=false;}
  });
  $('#reset-password-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#reset-password-form button[type=submit]'); button.disabled=true; $('#reset-password-error').textContent='';
    try {
      if(!resetToken) throw new Error('El enlace no contiene un token válido. Solicita uno nuevo.');
      if($('#reset-new-password').value!==$('#reset-confirm-password').value) throw new Error('Las contraseñas no coinciden.');
      await apiPost('/password-reset',{token:resetToken,new_password:$('#reset-new-password').value,new_password_confirm:$('#reset-confirm-password').value});
      try { await apiPost('/logout'); } catch {}
      try { localStorage.removeItem(STORAGE_KEY); } catch {}
      $('#reset-password-dialog').close(); $('#reset-password-form').reset(); history.replaceState(null,'',location.pathname);
      $('#app-shell').classList.add('hidden'); $('#auth-screen').classList.remove('hidden');
      showAuthPanel('login'); showToast('Contraseña actualizada. Ya puedes iniciar sesión.');
    } catch(error){$('#reset-password-error').textContent=error.message;}
    finally{button.disabled=false;}
  });
  $('#login-form').addEventListener('submit',async event=>{
    event.preventDefault(); const username=$('#login-username').value.trim(); const password=$('#login-password').value;
    const button=$('#login-form button[type=submit]'); button.disabled=true; showAuthError('login','');
    try {
      await apiPost('/login',{username,password});
      if(!await loadCurrentUser()) throw new Error('No se pudo iniciar la sesión. Vuelve a intentarlo.');
    } catch(error){showAuthError('login',error.message);}
    finally{button.disabled=false;}
  });
  $('#register-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#register-form button[type=submit]'); button.disabled=true; showAuthError('register','');
    try {
      const password=$('#register-password').value;
      if(password!==$('#register-confirm-password').value) throw new Error('Las contraseñas no coinciden.');
      let legacyState=null;
      try{const cached=localStorage.getItem(STORAGE_KEY);if(cached)legacyState=normalizeState(JSON.parse(cached));}catch{/* El servidor intentará recuperar la copia antigua. */}
      const data=await apiPost('/register',{username:$('#register-username').value.trim(),email:$('#register-email').value.trim(),password,password_confirm:$('#register-confirm-password').value,legacyState});
      enterApp(data);showToast('Cuenta creada. Confirma tu correo para poder recuperar la contraseña.');
    } catch(error){showAuthError('register',error.message);}
    finally{button.disabled=false;}
  });
  $('#logout-button').addEventListener('click',async()=>{try{await apiPost('/logout');}catch{} try{localStorage.removeItem(STORAGE_KEY);}catch{} if($('#email-dialog').open)$('#email-dialog').close();if($('#conflict-dialog').open)$('#conflict-dialog').close();currentUser='';currentEmail='';emailVerified=false;pendingEmail=null;emailRequired=false;sharedItems=[];state={recipes:[],planner:{}};weekOffset=0;pendingSave=false;stateVersion=0;syncPaused=false;conflictData=null;saveGeneration++;$('#app-shell').classList.add('hidden');$('#auth-screen').classList.remove('hidden');$('#login-form').reset();$('#register-form').reset();showAuthPanel('login');});
  $('#open-delete-account').addEventListener('click',()=>{
    $('#delete-account-form').reset(); $('#delete-account-error').textContent=''; showDialog('#delete-account-dialog');
  });
  $('#delete-account-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#delete-account-form button[type=submit]'); button.disabled=true; $('#delete-account-error').textContent='';
    try {
      await apiPost('/delete-account',{password:$('#delete-account-password').value,confirmation:$('#delete-account-confirmation').value});
      try { localStorage.removeItem(STORAGE_KEY); } catch {}
      $('#delete-account-dialog').close(); if($('#email-dialog').open)$('#email-dialog').close(); if($('#conflict-dialog').open)$('#conflict-dialog').close();
      currentUser=''; currentEmail=''; emailVerified=false; pendingEmail=null; emailRequired=false; sharedItems=[]; state={recipes:[],planner:{}}; weekOffset=0; pendingSave=false; stateVersion=0; syncPaused=false; conflictData=null; saveGeneration++;
      $('#app-shell').classList.add('hidden'); $('#auth-screen').classList.remove('hidden');
      showAuthPanel('login'); showToast('La cuenta y sus datos se han eliminado.');
    } catch(error){$('#delete-account-error').textContent=error.message;}
    finally{button.disabled=false;}
  });
  $('#password-button').addEventListener('click',()=>{
    $('#password-form').reset(); $('#password-error').textContent=''; showDialog('#password-dialog');
  });
  $('#password-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#password-form button[type=submit]'); button.disabled=true; $('#password-error').textContent='';
    try {
      if($('#new-password').value!==$('#confirm-new-password').value) throw new Error('Las contraseñas nuevas no coinciden.');
      await apiPost('/password',{current_password:$('#current-password').value,new_password:$('#new-password').value,new_password_confirm:$('#confirm-new-password').value});
      $('#password-dialog').close(); $('#password-form').reset(); showToast('Contraseña actualizada.');
    } catch(error){$('#password-error').textContent=error.message;}
    finally{button.disabled=false;}
  });
  $('#email-button').addEventListener('click',()=>openEmailDialog(false));
  $('#email-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#email-form button[type=submit]'); button.disabled=true; $('#email-error').textContent='';
    try {
      const result=await apiPost('/email',{email:$('#account-email-input').value.trim(),current_password:$('#email-current-password').value});
      currentEmail=result.email||''; pendingEmail=result.pendingEmail||null; emailVerified=Boolean(result.emailVerified); $('#user-email').textContent=currentEmail?`${currentEmail}${emailVerified?'':' · sin verificar'}`:'Correo sin añadir';
      emailRequired=false; $('#email-dialog').close(); $('#email-form').reset();
      showToast(result.unchanged?'Ese ya es tu correo actual.':result.verificationOnly?'Te hemos enviado un enlace para verificar tu correo.':'Te hemos enviado un enlace para confirmar el nuevo correo.');
    }
    catch(error){$('#email-error').textContent=error.message;}
    finally{button.disabled=false;}
  });
  $('#email-dialog').addEventListener('cancel',event=>{if(emailRequired)event.preventDefault();});
  document.querySelectorAll('.nav-link').forEach(link=>link.addEventListener('click',()=>showView(link.dataset.view)));
  $('#prev-week').addEventListener('click',()=>{weekOffset--;renderPlanner();}); $('#next-week').addEventListener('click',()=>{weekOffset++;renderPlanner();}); $('#today-button').addEventListener('click',()=>{weekOffset=0;renderPlanner();}); $('#share-week-button').addEventListener('click',shareWeek);
  $('#add-recipe-top').addEventListener('click',()=>openRecipeForm()); $('#add-recipe-library').addEventListener('click',()=>openRecipeForm());
  $('#recipe-search').addEventListener('input',renderRecipes);
  $('#week-grid').addEventListener('click',event=>{
    const add=event.target.closest('[data-add-meal]'); if(add){openMealForm(add.dataset.addMeal,add.dataset.type);return;}
    const remove=event.target.closest('[data-remove-meal]'); if(remove){const {removeMeal:day,type}=remove.dataset; if(state.planner[day])delete state.planner[day][type];save();renderPlanner();showToast('Comida quitada del menú.');}
  });
  $('#recipe-form').addEventListener('submit',event=>{
    event.preventDefault(); const id=$('#recipe-id').value; const old=state.recipes.find(item=>item.id===id);
    const recipe={id:id||`r-${Date.now()}-${Math.random().toString(36).slice(2,7)}`,name:$('#recipe-name').value.trim(),category:$('#recipe-category').value,time:Number($('#recipe-time').value),servings:Number($('#recipe-servings').value),ingredients:$('#recipe-ingredients').value.trim(),preparation:$('#recipe-preparation').value.trim(),emoji:old?.emoji||null};
    if(id)state.recipes=state.recipes.map(item=>item.id===id?recipe:item);else state.recipes.unshift(recipe);
    save();$('#recipe-dialog').close();renderRecipes();renderPlanner();showToast(id?'Receta actualizada.':'Receta guardada en tu colección.');
  });
  $('#meal-form').addEventListener('submit',event=>{event.preventDefault();const day=$('#meal-day').value,type=$('#meal-type').value;state.planner[day]=state.planner[day]||{};state.planner[day][type]=$('#meal-recipe').value;save();$('#meal-dialog').close();renderPlanner();showToast('Receta añadida al menú.');});
  $('#recipe-grid').addEventListener('click',event=>{
    const create=event.target.closest('[data-new-recipe]');if(create){openRecipeForm();return;}
    const share=event.target.closest('[data-share-recipe]'); if(share){event.stopPropagation();const recipe=state.recipes.find(item=>item.id===share.dataset.shareRecipe);if(recipe)openShareDialog('recipe',recipe.name,{recipe});return;}
    const edit=event.target.closest('[data-edit-recipe]'); if(edit){event.stopPropagation();openRecipeForm(state.recipes.find(item=>item.id===edit.dataset.editRecipe));return;}
    const remove=event.target.closest('[data-delete-recipe]');if(remove){event.stopPropagation();const id=remove.dataset.deleteRecipe;const recipe=state.recipes.find(item=>item.id===id);if(!recipe)return;if(!confirm(`¿Eliminar “${recipe.name}” de tu colección? También se quitará del menú semanal.`))return;state.recipes=state.recipes.filter(item=>item.id!==id);Object.values(state.planner).forEach(day=>Object.keys(day).forEach(type=>{if(day[type]===id)delete day[type];}));save();renderRecipes();renderPlanner();showToast('Receta eliminada.');return;}
    const card=event.target.closest('[data-open-recipe]');if(card)showRecipe(card.dataset.openRecipe);
  });
  $('#share-detail').addEventListener('click',()=>{const recipe=state.recipes.find(item=>item.id===currentDetailId);if(recipe){$('#detail-dialog').close();openShareDialog('recipe',recipe.name,{recipe});}});
  $('#edit-detail').addEventListener('click',()=>{const recipe=state.recipes.find(item=>item.id===currentDetailId);$('#detail-dialog').close();openRecipeForm(recipe);});
  $('#share-form').addEventListener('submit',async event=>{
    event.preventDefault(); const button=$('#share-form button[type=submit]'); button.disabled=true; $('#share-error').textContent='';
    try {
      await apiPost('/share',{kind:$('#share-kind').value,title:$('#share-preview').textContent,recipient:$('#share-recipient').value.trim(),payload:JSON.parse($('#share-payload').value)});
      $('#share-dialog').close(); showToast('Enviado a '+$('#share-recipient').value.trim()+'.');
    } catch(error) { $('#share-error').textContent=error.message; }
    finally { button.disabled=false; }
  });
  $('#shared-grid').addEventListener('click',async event=>{
    const copy=event.target.closest('[data-copy-share]'); const dismiss=event.target.closest('[data-dismiss-share]');
    if(copy){const item=sharedItems.find(share=>share.id===Number(copy.dataset.copyShare));if(!item)return;
      if(item.kind==='planner'&&!confirm('Copiar esta planificación sustituirá las comidas de esos días que ya tengas planificadas. ¿Continuar?'))return;
      copy.disabled=true;
      try{
        const saved=await save();
        if(!saved||syncPaused){copy.disabled=false;return;}
        const result=await apiPost(`/shares/${item.id}/copy`,{baseVersion:stateVersion});
        state=normalizeState(result.state);
        if(Number.isInteger(result.stateVersion))stateVersion=result.stateVersion;
        cacheLocally();if(!pendingSave)setStorageStatus(true);
        item.copied_at='copied';renderPlanner();renderRecipes();renderShared();broadcastSaved();
        showToast(item.kind==='recipe'?'Receta copiada a tu colección.':'Planificación copiada a tu semana.');
      }catch(error){
        if(error.status===409&&error.data&&error.data.state)handleConflict(error.data);
        else showToast(error.message);
        copy.disabled=false;
      }
      return;}
    if(dismiss){const id=Number(dismiss.dataset.dismissShare);try{await apiPost(`/shares/${id}/dismiss`);sharedItems=sharedItems.filter(item=>item.id!==id);renderShared();}catch(error){showToast(error.message);}}
  });
  $('#share-dialog').querySelectorAll('[data-close]').forEach(button=>button.addEventListener('click',event=>{
    event.preventDefault();
    $('#share-dialog').close();
  }));
  document.addEventListener('click',event=>{
    const closeButton=event.target instanceof Element ? event.target.closest('[data-close]') : null;
    const dialog=closeButton?.closest('dialog');
    if(dialog?.open)dialog.close();
  });
  $('#conflict-keep').addEventListener('click',()=>resolveConflict(false));
  $('#conflict-reload').addEventListener('click',()=>resolveConflict(true));
  $('#conflict-dialog').addEventListener('cancel',event=>event.preventDefault());
  if(syncChannel)syncChannel.addEventListener('message',event=>{if(event.data?.type==='saved')syncFromOtherTab();});
  window.addEventListener('storage',event=>{if(event.key===SYNC_KEY)syncFromOtherTab();});
  window.addEventListener('focus',()=>{if(currentUser)syncFromOtherTab();});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&currentUser)syncFromOtherTab();});
  $('#help-button').addEventListener('click',()=>showToast('Añade recetas a tu colección y pulsa + para planificar cada comida.'));
  window.addEventListener('online',()=>{if(pendingSave&&currentUser&&!syncPaused)save();});
  window.addEventListener('beforeunload',event=>{
    if(!pendingSave)return;
    event.preventDefault();
    event.returnValue='';
  });
  setInterval(()=>{if(pendingSave&&currentUser&&!syncPaused)save();},30000);
  initialize().finally(()=>{if(resetToken){showDialog('#reset-password-dialog');}});
})();
