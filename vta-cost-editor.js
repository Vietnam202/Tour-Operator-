(function () {
  'use strict';
  // A compact editor over the existing VS2.1 cost-sheet API. No financial arithmetic is trusted from the browser.
  const previous = window.mountCostSheet;
  const escapeHTML = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString('en-US', {maximumFractionDigits:0});
  const options = [
    ['HOTEL','Hotel'],['CRUISE','Cruise'],['TRANSPORT','Transfer'],['ATTRACTION','Tickets'],
    ['TOUR','Group Tour / SIC'],['GUIDE','Tour Guide'],['MEAL','Meals'],['VISA','Visa'],['OTHER','Other']
  ];
  const sources = {HOTEL:'HOTEL_PAX',CRUISE:'CRUISE_PAX',TRANSPORT:'CUSTOM_QTY',GUIDE:'CUSTOM_QTY',
    ATTRACTION:'TICKET_PAX',TOUR:'TOTAL_GUESTS',MEAL:'MEAL_PAX',VISA:'VISA_PAX',OTHER:'CUSTOM_QTY'};
  const titles = {HOTEL:'Hotel',CRUISE:'Cruise',TRANSPORT:'Transfer',GUIDE:'Tour Guide',
    ATTRACTION:'Tickets',TOUR:'Group Tour / SIC',MEAL:'Meals',VISA:'Visa',OTHER:'Other Service'};
  const multipliers = {HOTEL:'Nights',CRUISE:'Packages',TRANSPORT:'Trips',GUIDE:'Days',
    ATTRACTION:'Tickets',TOUR:'Tours',MEAL:'Meals',VISA:'Qty',OTHER:'Qty'};
  const isStay = r => r.category === 'HOTEL' || r.category === 'CRUISE';
  const dateSequence = (first, qty) => {
    if(!/^\d{4}-\d{2}-\d{2}$/.test(first) || !Number.isInteger(qty) || qty<1 || qty>100) return null;
    const date=new Date(first+'T00:00:00Z');
    if(Number.isNaN(date.getTime()) || date.toISOString().slice(0,10)!==first) return null;
    return Array.from({length:qty},(_,i)=>{
      const day=new Date(date.getTime());day.setUTCDate(day.getUTCDate()+i);
      return day.toISOString().slice(0,10);
    });
  };
  const selectHTML = (items, selected, attr) => '<select ' + attr + '>' + items.map(item =>
    '<option value="' + escapeHTML(item[0]) + '"' + (String(item[0]) === String(selected) ? ' selected' : '') +
    '>' + escapeHTML(item[1]) + '</option>').join('') + '</select>';
  const guestCount = (req, line, context) => {
    const override = line && (line.quantity_override != null ? line.quantity_override : line.custom_quantity);
    if (override != null) return override;
    if (line && line.resolved_quantity != null) return line.resolved_quantity;
    const k = (req.default_quantity_source || '').toLowerCase();
    return (context.profile && context.profile[k]) ?? (context.guests && context.guests[k]) ?? '';
  };
  window.mountCostSheet = async function (host, env) {
    const {api,quoteData,can,navigate,toast,refresh,onProposal} = env;
    if (!previous || host.dataset.sheetStep === 'price' || !can('quote.view_cost')) {
      return previous(host,env);
    }
    const version = quoteData.version, quote = quoteData.quote;
    const locked = ['SENT','CONFIRMED','SUPERSEDED'].includes(version.version_status);
    const edit = can('quote.edit') && !locked;
    const base = 'quote-versions/' + version.id;
    let context = env.ctx, data = env.data;
    let revision = context.costing_revision;
    const mode = host.dataset.sheetMode || data.items.find(x => Number(x.is_offered))?.costing_mode || 'PRIVATE';
    const getPackages = () => {
      let selection;
      try {selection = JSON.parse(host.dataset.vtaCostVariants || 'null');} catch (_) {}
      if (Array.isArray(selection) && selection.length === 3) {
        const picked = selection.map(id => data.items.find(x => Number(x.variant_id) === Number(id)));
        if (new Set(selection.map(Number)).size === 3 && picked.every(p => p && p.costing_mode === mode)) return picked;
      }
      return [3,4,5].map(star => data.items.find(p => p.costing_mode === mode &&
        parseInt(p.hotel_level,10) === star && p.variant_key === mode.toLowerCase() + '-' + star + '-' + star) ||
        data.items.find(p => p.costing_mode === mode && parseInt(p.hotel_level,10) === star));
    };
    let packages = getPackages();
    if (packages.some(p => !p)) return previous(host,env);
    const ids = () => packages.map(p => Number(p.variant_id));
    const line = (p,r) => p.lines.find(l => Number(l.requirement_id) === Number(r.id) && l.line_kind === 'SERVICE');
    const visible = () => context.requirements.filter(r => r.requirement_state !== 'NOT_APPLICABLE' &&
      packages.some(p => line(p,r)?.active));
    let pendingUndo = null;
    let queue = Promise.resolve();
    const status = msg => {const node = host.querySelector('[data-vta-status]');if(node)node.textContent = msg;};
    const setMoney = (node, value) => {if(node)node.textContent = money(value);};
    const updateTotals = () => {
      packages.forEach((p,i) => {
        const card = host.querySelector('[data-vta-summary="' + i + '"]');
        if (card) {
          setMoney(card.querySelector('[data-total]'),p.pricing?.cost_total_vnd);
          const per = p.pricing?.cost_per_paying_pax ?? ((p.pricing?.cost_total_vnd != null && Number(context.guests.paying_pax)>0) ? Number(p.pricing.cost_total_vnd)/Number(context.guests.paying_pax) : null);
          setMoney(card.querySelector('[data-per-pax]'),per);
        }
      });
      visible().forEach(r => {
        const row = host.querySelector('[data-vta-row="' + r.id + '"]');
        if (!row) return;
        packages.forEach((p,i) => {
          const l = line(p,r), output = row.querySelector('[data-line-total="' + i + '"]');
          if (output) output.textContent = l?.coverage_state === 'INCLUDED' ? 'Included' : money(l?.total_vnd);
          const warning = row.querySelector('[data-review="' + i + '"]');
          if (warning) warning.hidden = !(l?.review_required || l?.coverage_state === 'UNRESOLVED');
          const rate = row.querySelector('[data-rate="' + i + '"]');
          if (rate && rate !== host.ownerDocument.activeElement) {
            const next = l?.unit_rate_vnd ?? '';
            if (rate.value !== String(next)) rate.value = String(next);
          }
          const review = row.querySelector('[data-review-action="' + i + '"]');
          if (review) review.hidden = !(l?.review_required && ['PRICED','INCLUDED','NO_COST'].includes(l?.coverage_state));
        });
      });
    };
    const reloadData = async () => {
      const [fresh, freshContext] = await Promise.all([
        api.request(base + '/options'), api.request(base + '/smart-costing/context')
      ]);
      data = fresh; context = freshContext; revision = freshContext.costing_revision;
      packages = getPackages();
      updateTotals();
    };
    const send = (payload, endpoint = '/smart-costing/sheet', method = 'POST', redraw = false) => {
      queue = queue.catch(() => {}).then(async () => {
        status('Saving…');
        try {
          const result = await api.request(base + endpoint,{method,body:{...payload,expected_revision:revision}});
          if (result.costing_revision !== undefined) revision = result.costing_revision;
          await reloadData();
          if (redraw) render();
          status('Saved');
          return result;
        } catch (error) {
          status('Save failed — changes not saved');
          toast(error.message || 'Could not save cost',true);
          return null;
        }
      });
      return queue;
    };
    const sheet = (body,redraw=false) => send({variant_ids:ids(),...body},'/smart-costing/sheet','POST',redraw);
    const detailsFor = (r,p,i) => {
      const l = line(p,r), supplier = l?.supplier_id || '', reason = l?.manual_reason || '',
        choices = [['','Supplier…'],...(data.supplier_choices || []).map(x => [x.id,x.name])];
      return '<details class="vta-rate-source" data-proof="' + i + '"><summary>Supplier / source</summary>' +
        '<div class="vta-proof-fields"><label>Supplier ' + selectHTML(choices,supplier,'data-supplier aria-label="Supplier for option ' + (i+1) + '"') +
        '</label><label>Evidence<input data-evidence value="' + escapeHTML(reason) +
        '" placeholder="Contract / rate reason" ' + (!edit?'disabled':'') + '></label>' +
        (edit?'<button type="button" class="vta-proof-apply" data-apply="' + i + '">Apply rate</button>':'') +
        (edit?'<label>Review reason<input data-review-note placeholder="Reviewed with supplier / source"></label>' +
          '<button type="button" data-review-action="' + i + '"' +
          (!(l?.review_required && ['PRICED','INCLUDED','NO_COST'].includes(l?.coverage_state))?' hidden':'') +
          '>✓ Confirm reviewed cost</button>':'') +
        '</div></details>';
    };
    const input = (attr,value,classes='',extra='') => '<input class="' + classes + '" ' + attr +
      ' value="' + escapeHTML(value ?? '') + '" ' + (!edit?'disabled':'') + ' ' + extra + '>';
    function cellRate(r,p,i) {
      const l = line(p,r), rate = l?.coverage_state === 'INCLUDED' ? '' : (l?.unit_rate_vnd ?? '');
      const stay = isStay(r),star = r.category === 'HOTEL' ? parseInt(p.hotel_level,10) : p.cruise_level,
        savedName = (r.metadata?.[r.category === 'HOTEL'?'hotel_names':'cruise_names'] || {})[star] || '';
      return '<div class="vta-rate-cell">' +
        (stay ? input('data-property="' + i + '" aria-label="Property name for ' + escapeHTML(star) + ' stars"',savedName,'vta-property','placeholder="Hotel / Cruise name"') : '') +
        (l?.coverage_state === 'INCLUDED'?'<span>Included</span>':input('type="number" min="0" step="0.01" inputmode="decimal" data-rate="' + i + '" aria-label="Rate option ' + (i+1) + '"',rate,'vta-rate','placeholder="Need rate"')) +
        '<span class="vta-money" data-line-total="' + i + '">' + money(l?.total_vnd) + '</span>' +
        '<small data-review="' + i + '" ' + (!(l?.review_required || l?.coverage_state==='UNRESOLVED')?'hidden':'') + '>Review</small>' +
        detailsFor(r,p,i) + '</div>';
    }
    function buildRow(r) {
      const p=packages[0],l=line(p,r),stay=isStay(r),category=r.category;
      const count=guestCount(r,l,context),units=l?.units_override??r.service_units??1;
      const common = !stay;
      const service='<div class="vta-service"><strong>' + escapeHTML(titles[category] || category) + '</strong>' +
        input('data-name aria-label="Service name" maxlength="255"',r.service_name,'vta-name') +
        input('data-destination aria-label="Destination" maxlength="160"',r.scope?.destination || '','vta-destination','placeholder="Destination"') +
        (['HOTEL','CRUISE','GUIDE'].includes(category)?'<label class="vta-date-label">' +
          escapeHTML(category==='HOTEL'?'First hotel night':category==='CRUISE'?'Cruise departure':'First guide day') +
          input('type="date" data-service-date aria-label="First service date"',
            (r.scope?.dates?.[0] || r.service_date || ''),'vta-service-date') +
          '</label>':'') + '</div>';
      const qty='<div class="vta-quantities">' +
        '<label>' + escapeHTML(['TRANSPORT','GUIDE'].includes(category)?(category==='GUIDE'?'Guides':'Vehicles'):'Pax / Units') +
        input('type="number" min="0" max="10000" data-count inputmode="numeric"',count,'vta-small') +
        '</label><label>' + escapeHTML(multipliers[category] || 'Qty') +
        input('type="number" min="0" max="10000" data-units inputmode="numeric"',units,'vta-small') +
        '</label></div>';
      const body=common ? '<div class="vta-common-rate">' + cellRate(r,p,0) + '</div>' :
        '<div class="vta-star-grid">' + packages.map((pack,i) =>
          '<div class="vta-star-column"><span class="vta-star-caption">' +
          escapeHTML(r.category==='HOTEL'?'Hotel '+parseInt(pack.hotel_level,10)+'★':'Cruise '+(pack.cruise_level==null?'—':pack.cruise_level+'★')) +
          '</span>' + cellRate(r,pack,i) + '</div>').join('') + '</div>';
      return '<article class="vta-cost-line '+(stay?'vta-stay-line':'vta-common-line')+'" data-vta-row="' + r.id + '">' +
        service + qty + body +
        (edit?'<button type="button" class="vta-remove" data-remove aria-label="Remove service">×</button>' +
        '<div class="vta-remove-confirm" hidden><label>Removal reason<input data-remove-reason placeholder="Not included in this quote"></label>' +
        '<button type="button" data-confirm-remove>Remove</button><button type="button" data-cancel-remove>Cancel</button></div>' +
        '<div class="vta-override" hidden><label>Reason for custom Pax / Qty<input data-why placeholder="Supplier / client confirmed quantity"></label>' +
        '<button type="button" data-apply-count>Apply</button><button type="button" data-cancel-count>Cancel</button></div>':'') +
        '</article>';
    }
    function buildSummary(p,i) {
      const star=parseInt(p.hotel_level,10);
      const per=p.pricing?.cost_per_paying_pax ?? (p.pricing?.cost_total_vnd!=null&&Number(context.guests.paying_pax)>0?
        Number(p.pricing.cost_total_vnd)/Number(context.guests.paying_pax):null);
      return '<article class="vta-cost-summary" data-vta-summary="' + i + '">' +
        '<strong>Option ' + String.fromCharCode(65+i) + ' · Hotel ' + star + '★ / Cruise ' +
        (p.cruise_level==null?'—':p.cruise_level+'★') + '</strong>' +
        '<div class="vta-mix-selectors"><label>Hotel ' + selectHTML([[3,'3★'],[4,'4★'],[5,'5★']],star,'data-mix-hotel="' + i + '"' + (!edit?' disabled':'')) +
        '</label><label>Cruise ' + selectHTML([['','None'],[3,'3★'],[4,'4★'],[5,'5★']],p.cruise_level ?? '','data-mix-cruise="' + i + '"' + (!edit?' disabled':'')) + '</label></div>' +
        '<span>Total cost <b data-total>' + money(p.pricing?.cost_total_vnd) + '</b> VND</span>' +
        '<span>Cost / paying pax <b data-per-pax>' + money(per) + '</b> VND</span>' +
        '</article>';
    }
    function render() {
      packages=getPackages();
      const rows=visible(),common=rows.filter(r=>!isStay(r)),stays=rows.filter(isStay);
      const modeChoices=[['PRIVATE','Private'],['SIC','SIC / Group'],['HYBRID','Hybrid']];
      const card=(title,arr,stay)=>'<section class="vta-cost-section"><header><h2>' + title +
        '</h2><small>' + arr.length + ' services</small></header>' +
        '<div class="vta-section-grid '+(stay?'vta-stay-grid':'vta-shared-grid')+'">' +
        (stay?'<div class="vta-table-header"><span>Service / Destination</span><span>Pax / Qty</span>' +
          packages.map((p,i)=>'<span>Option '+String.fromCharCode(65+i)+'</span>').join('') + '</div>':
          '<div class="vta-table-header"><span>Service / Destination</span><span>Pax / Qty</span><span>Unit Rate / Total</span></div>') +
        arr.map(buildRow).join('') + (arr.length?'':'<p class="vta-empty">No services yet. Use + Add.</p>') +
        '</div></section>';
      const guest=context.guests || {},profile=context.profile || {};
      host.innerHTML='<div class="page cost-sheet vta-direct-cost">' +
        '<div class="page-head"><div><h1>' + escapeHTML(version.tour_name || quote.quote_ref) + '</h1>' +
        '<p>' + escapeHTML(quote.quote_ref) + ' · Cost Sheet · V' + escapeHTML(version.version_no) + '</p></div>' +
        '<button type="button" class="btn" data-sales>Sales</button></div>' +
        '<nav class="quote-steps"><button type="button" data-info>Info</button><button type="button" data-itinerary>Itinerary</button>' +
        '<button type="button" class="active">Cost</button><button type="button" data-price>Price</button><button type="button" data-proposal>Proposal / Send</button></nav>' +
        '<div class="vta-toolbar"><label>Mode ' + selectHTML(modeChoices,mode,'data-mode') + '</label>' +
        '<span>' + escapeHTML(guest.paying_pax) + ' paying · ' + escapeHTML(guest.total_guests) + ' guests</span>' +
        '<span>' + escapeHTML(profile.hotel_pax ?? '—') + ' hotel pax · ' + escapeHTML(profile.cruise_pax ?? '—') + ' cruise pax</span>' +
        '<span class="vta-save-state" role="status" data-vta-status>' + (locked?'Read-only':'Saved') + '</span></div>' +
        '<div class="vta-guest-controls">' +
        '<label>Paying Pax' + input('type="number" min="1" max="10000" data-guest="paying_pax" inputmode="numeric"',guest.paying_pax,'vta-guest-input') + '</label>' +
        '<label>Hotel Pax' + input('type="number" min="0" max="10000" data-guest="hotel_pax" inputmode="numeric"',profile.hotel_pax ?? '','vta-guest-input','placeholder="Review"') + '</label>' +
        '<label>Cruise Pax' + input('type="number" min="0" max="10000" data-guest="cruise_pax" inputmode="numeric"',profile.cruise_pax ?? '','vta-guest-input','placeholder="Review"') +
        '</label><small>Guest composition is managed in Info. FOC costs stay included; cost/pax uses Paying Pax.</small></div>' +
        card('A. Common Services',common,false) + card('B. Hotel & Cruise · Each destination has its own rate',stays,true) +
        '<div class="vta-add-bar">' +
        (edit?selectHTML(options,'HOTEL','data-new-service aria-label="Service to add"') +
          '<button type="button" class="btn primary" data-add>+ Add Service</button>':'') +
        (pendingUndo && edit ? '<button type="button" class="btn" data-undo>Undo remove</button>':'') + '</div>' +
        '<section class="vta-cost-section"><header><h2>C. Total Tour Cost</h2><small>Server-calculated · VND</small></header>' +
        '<div class="vta-cost-summaries">' + packages.map(buildSummary).join('') + '</div></section>' +
        '<div class="vta-cost-bottom"><button type="button" class="btn" data-check>Check Quote</button>' +
        '<button type="button" class="btn primary" data-next>Next: Price →</button></div>' +
        '<div class="vta-validation" data-validation></div>' +
        '<p class="vta-disclaimer">Cost by pax: Hotel = pax × nights × VND/pax/night; Cruise = pax × packages × VND/pax/package. ' +
        'Missing supplier rates remain unresolved, never zero. Supplier approval and pricing validation are enforced by the server.</p>' +
        '</div>';
      bind();
    }
    function saveRate(row, index) {
      const r=context.requirements.find(x=>String(x.id)===row.dataset.vtaRow),p=packages[index],
        rate=row.querySelector('[data-rate="' + index + '"]');
      if(!r||!p||!rate || rate.value === ''){status('Rate is required; blank is not zero');return;}
      if(!Number.isFinite(Number(rate.value)) || Number(rate.value)<0){status('Invalid unit rate');return;}
      const proof=row.querySelector('[data-proof="' + index + '"]'),supplier=proof.querySelector('[data-supplier]').value,
        reason=proof.querySelector('[data-evidence]').value.trim();
      if(!supplier||!reason){proof.open=true;status('Select supplier and evidence before saving this rate');proof.querySelector(!supplier?'[data-supplier]':'[data-evidence]').focus();return;}
      const manual={supplier_id:Number(supplier),original_currency:'VND',unit_amount_original:rate.value,
        manual_reason:reason,manual_contract:{evidence:reason,tax_basis:'NET'}};
      if(isStay(r))return sheet({requirement_id:Number(r.id),shared:false,variant_ids:[Number(p.variant_id)],
        lines:{[p.variant_id]:manual}});
      return sheet({requirement_id:Number(r.id),shared:true,line:manual});
    }
    function bindRow(row) {
      const id=Number(row.dataset.vtaRow),r=context.requirements.find(x=>Number(x.id)===id);
      if(!r||!edit)return;
      row.querySelector('[data-name]').onchange=e=>{
        const value=e.target.value.trim();
        if (!value) {status('Service name is required');return;}
        sheet({requirement:{id,service_name:value},line:{}});
      };
      row.querySelector('[data-destination]').onchange=e=>{
        sheet({requirement:{id,scope:{...r.scope,destination:e.target.value.trim()}},line:{}});
      };
      row.querySelector('[data-units]').onchange=e=>{
        const units=Number(e.target.value);
        if(!Number.isInteger(units)||units<0){status('Whole number required');return;}
        const first=row.querySelector('[data-service-date]')?.value || r.scope?.dates?.[0] || '';
        const dates=['HOTEL','GUIDE'].includes(r.category) && first ? dateSequence(first,units) : null;
        if(['HOTEL','GUIDE'].includes(r.category) && first && !dates){status('Select a valid date and quantity 1–100');return;}
        sheet({requirement:{id,service_units:units,...(dates?{scope:{...r.scope,dates}}:{})},line:{}},true);
      };
      row.querySelector('[data-service-date]')?.addEventListener('change',e=>{
        const first=e.target.value,units=Number(row.querySelector('[data-units]').value);
        if(!first){status('Choose a service date');return;}
        const dates=['HOTEL','GUIDE'].includes(r.category)?dateSequence(first,units):null;
        if(['HOTEL','GUIDE'].includes(r.category)&&!dates){status('Valid first date and 1–100 nights/days required');return;}
        sheet({requirement:{id,service_date:first,scope:{...r.scope,...(dates?{dates}:{})}},line:{}},true);
      });
      const count=row.querySelector('[data-count]');
      const saveCount=()=>{
        const val=Number(count.value),custom=r.default_quantity_source==='CUSTOM_QTY';
        if(!Number.isInteger(val)||val<0){status('Whole number required');return;}
        if (custom) return sheet({requirement_id:id,line:{custom_quantity:val}});
        const more=row.querySelector('.vta-override'),reason=more.querySelector('[data-why]').value.trim();
        if(!reason){more.hidden=false;status('Explain service pax override');return;}
        more.hidden=true;return sheet({requirement_id:id,line:{quantity_override:val,override_reason:reason}});
      };
      count.onchange=saveCount;
      row.querySelector('[data-apply-count]').onclick=saveCount;
      row.querySelector('[data-cancel-count]').onclick=()=>{row.querySelector('.vta-override').hidden=true;count.value=guestCount(r,line(packages[0],r),context);};
      row.querySelectorAll('[data-rate]').forEach(input=>input.onchange=()=>saveRate(row,Number(input.dataset.rate)));
      row.querySelectorAll('[data-apply]').forEach(btn=>btn.onclick=()=>saveRate(row,Number(btn.dataset.apply)));
      row.querySelectorAll('[data-review-action]').forEach(btn=>btn.onclick=()=>{
        const target=Number(btn.dataset.reviewAction);
        const proof=row.querySelector('[data-proof="' + target + '"]');
        const reason=proof.querySelector('[data-review-note]').value.trim();
        if(!reason){proof.open=true;status('Review reason is required');proof.querySelector('[data-review-note]').focus();return;}
        const pack=packages[target];
        sheet({action:'review',requirement_id:id,variant_ids:
          isStay(r)?[Number(pack.variant_id)]:ids(),review_reason:reason},true);
      });
      row.querySelectorAll('[data-property]').forEach(input=>input.onchange=e=>{
        const idx=Number(e.target.dataset.property),p=packages[idx];
        const key=r.category==='HOTEL'?'hotel_names':'cruise_names';
        const star=r.category==='HOTEL'?parseInt(p.hotel_level,10):p.cruise_level;
        const names={...(r.metadata?.[key]||{})};
        names[star]=e.target.value.trim();
        sheet({requirement:{id,metadata:{...r.metadata,[key]:names}},line:{}});
      });
      const remove=row.querySelector('[data-remove]'),confirm=row.querySelector('.vta-remove-confirm');
      remove.onclick=()=>{confirm.hidden=false;confirm.querySelector('[data-remove-reason]').focus();};
      row.querySelector('[data-cancel-remove]').onclick=()=>{confirm.hidden=true;};
      row.querySelector('[data-confirm-remove]').onclick=()=>{
        const reason=confirm.querySelector('[data-remove-reason]').value.trim();
        if(!reason){status('Removal reason is required');return;}
        sheet({action:'remove',requirement_id:id,reason},true).then(result=>{
          if (result){pendingUndo={id,reason};render();}
        });
      };
    }
    function bind() {
      host.querySelector('[data-sales]').onclick=()=>navigate('sales-list',{salesTab:'quotes'});
      host.querySelector('[data-info]').onclick=()=>{
        if(typeof env.modal!=='function')return;
        const field=(label,name,value,type='text')=>'<label>'+escapeHTML(label)+
          '<input name="'+name+'" type="'+type+'" value="'+escapeHTML(value??'')+'"'+(!edit?' disabled':'')+'></label>';
        const body='<form data-vta-info-form class="vta-info-fields">'+
          field('Tour Name','tour_name',version.tour_name)+
          field('Start Date','start_date',version.start_date,'date')+
          field('End Date','end_date',version.end_date,'date')+
          field('FX · VND per USD','fx_rate',version.fx_rate,'number')+'</form>';
        const m=env.modal('Quote Info',body,edit?'<button type="button" class="btn primary" data-vta-info-save>Save</button>':'');
        if(edit)m.querySelector('[data-vta-info-save]').onclick=()=>{
          const changes=Object.fromEntries(new FormData(m.querySelector('form')));
          env.closeModal?.();
          send(changes,'/smart-costing/context','PUT',true);
        };
      };
      host.querySelector('[data-itinerary]').onclick=()=>onProposal?.();
      host.querySelector('[data-price]').onclick=()=>{host.dataset.sheetStep='price';refresh();};
      host.querySelector('[data-proposal]').onclick=()=>onProposal?.();
      host.querySelector('[data-next]').onclick=()=>{host.dataset.sheetStep='price';refresh();};
      host.querySelector('[data-mode]').onchange=e=>{host.dataset.sheetMode=e.target.value;refresh();};
      host.querySelector('[data-check]').onclick=async()=>{
        try{const r=await api.request(base+'/smart-costing/validation');
          const box=host.querySelector('[data-validation]');
          box.textContent=r.valid?'Quote checks passed':(r.errors||[]).concat(r.warnings||[]).map(x=>x.code).join(' · ') || 'Review cost and supplier rates';
        }catch(e){toast(e.message,true);}
      };
      host.querySelectorAll('[data-mix-hotel],[data-mix-cruise]').forEach(control=>control.onchange=()=>{
        const index=Number(control.dataset.mixHotel ?? control.dataset.mixCruise);
        const card=host.querySelector('[data-vta-summary="' + index + '"]');
        const hotel=Number(card.querySelector('[data-mix-hotel]').value);
        const cruiseValue=card.querySelector('[data-mix-cruise]').value;
        const cruise=cruiseValue===''?null:Number(cruiseValue);
        const existing=data.items.find(p=>p.costing_mode===mode && parseInt(p.hotel_level,10)===hotel &&
          (p.cruise_level==null?null:Number(p.cruise_level))===cruise);
        const selectVariant=id=>{
          const chosen=ids();chosen[index]=Number(id);
          if(new Set(chosen).size!==3){
            render();
            status('This combination is already selected in another option');return false;
          }
          host.dataset.vtaCostVariants=JSON.stringify(chosen);
          packages=getPackages();render();return true;
        };
        if(existing){selectVariant(existing.variant_id);return;}
        send({variant_ids:ids(),action:'mix',mode,hotel_level:hotel,cruise_level:cruise})
          .then(result=>{if(result?.variant_id && selectVariant(result.variant_id)){
            status('Combination saved · supplier rates require review');}});
      });
      host.querySelectorAll('[data-guest]').forEach(input=>input.onchange=()=>{
        if(!edit)return;
        const raw=input.value.trim(),key=input.dataset.guest;
        const n=Number(raw),total=Number(context.guests.total_guests),foc=Number(context.guests.foc);
        if(!/^\d+$/.test(raw) || n>10000 || (key==='paying_pax'&&(n<1 || n+foc>total)) ||
          (key!=='paying_pax'&&n>total)){
          input.value=String(key==='paying_pax'?context.guests.paying_pax:context.profile[key]??'');
          status('Invalid guest count');return;
        }
        send({[key]:Number(raw),review_reason:'Service population edited in Cost'},
          '/smart-costing/context','PUT',true);
      });
      host.querySelectorAll('[data-vta-row]').forEach(bindRow);
      host.querySelector('[data-add]')?.addEventListener('click',()=>{
        const category=host.querySelector('[data-new-service]').value;
        sheet({requirement:{category,service_name:titles[category],service_date:version.start_date||null,
          service_units:1,default_quantity_source:sources[category],service_mode:'BOTH',
          requirement_state:'REQUIRED',scope:{}},
          line:category==='TRANSPORT'||category==='GUIDE'||category==='OTHER'?{custom_quantity:1}:{}},true);
      });
      host.querySelector('[data-undo]')?.addEventListener('click',()=>{
        if(!pendingUndo)return;
        const id=pendingUndo.id;
        sheet({requirement:{id,requirement_state:'REQUIRED'},line:{}},true).then(result=>{
          if(result){pendingUndo=null;render();}
        });
      });
    }
    render();
  };
})();
