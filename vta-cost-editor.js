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
    const pendingPropertyMeta = new Map();
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
    // Approved demo layout: option-first accommodation cards, always backed by VS2.1 API.
    function optionStay(r,p,i) {
      const l=line(p,r);
      if(!l || !l.active) return '';
      const star=r.category==='HOTEL'?parseInt(p.hotel_level,10):p.cruise_level;
      const key=r.category==='HOTEL'?'hotel_names':'cruise_names';
      const property=(r.metadata?.[key]||{})[star]||'';
      const destination=r.scope?.destination||'Destination';
      const rate=l.coverage_state==='INCLUDED'?'Included':input('type="number" min="0" step="0.01" inputmode="decimal" data-option-rate aria-label="VND unit rate"',''+(l.unit_rate_vnd??''),'vta-option-rate','placeholder="Need rate"');
      return '<div class="vta-option-stay" data-vta-option-stay data-requirement="'+r.id+'" data-index="'+i+'">' +
        '<div class="vta-option-stay-head"><strong>'+escapeHTML(titles[r.category]||r.category)+' · '+escapeHTML(destination)+'</strong>' +
        '<small>'+escapeHTML(r.service_units||1)+' '+escapeHTML(multipliers[r.category]||'Qty')+'</small></div>' +
        input('data-option-property aria-label="Hotel or cruise property" maxlength="255"',property,'vta-option-property','placeholder="Hotel / Cruise name"') +
        '<div class="vta-option-rate-row"><label>VND / pax / '+(r.category==='HOTEL'?'night':'package')+rate+'</label>' +
        '<span class="vta-option-line-total">Total <b>'+money(l.total_vnd)+'</b></span></div>' +
        (l.review_required||l.coverage_state==='UNRESOLVED'?'<small class="vta-option-review">Supplier review required</small>':'')+
        detailsFor(r,p,i) + '</div>';
    }
    function buildSummary(p,i) {
      const star=parseInt(p.hotel_level,10);
      const per=p.pricing?.cost_per_paying_pax ?? (p.pricing?.cost_total_vnd!=null&&Number(context.guests.paying_pax)>0?
        Number(p.pricing.cost_total_vnd)/Number(context.guests.paying_pax):null);
      const cruise=p.cruise_level==null?'—':p.cruise_level+'★';
      const stays=visible().filter(isStay);
      return '<article class="vta-cost-summary" data-vta-summary="'+i+'">' +
        '<div class="vta-option-head"><strong>OPTION '+String.fromCharCode(65+i)+'</strong><span>Hotel '+star+'★ / Cruise '+cruise+'</span></div>' +
        '<div class="vta-option-price"><b data-per-pax>'+money(per)+'</b><small>VND / paying pax</small></div>' +
        '<div class="vta-option-total">Total tour cost <b data-total>'+money(p.pricing?.cost_total_vnd)+'</b> VND</div>' +
        '<div class="vta-mix-selectors"><label>Hotel '+selectHTML([[3,'3 Stars'],[4,'4 Stars'],[5,'5 Stars']],star,'data-mix-hotel="'+i+'"'+(!edit?' disabled':'')) +
        '</label><label>Cruise '+selectHTML([['','None'],[3,'3 Stars'],[4,'4 Stars'],[5,'5 Stars']],p.cruise_level??'','data-mix-cruise="'+i+'"'+(!edit?' disabled':''))+'</label></div>' +
        '<div class="vta-option-stays">'+stays.map(r=>optionStay(r,p,i)).join('')+'</div>' +
        '</article>';
    }
    // Read-only private pax-group matrix. Scenario math and resource counts remain server-owned.
    async function loadGroupMatrix() {
      const target=host.querySelector('[data-pax-matrix-body]');
      if(!target)return;
      const requested=ids();
      const initial=[2,4,6,8,10,12,16].map(n=>String(n));
      try {
        const matrix=await api.request(base+'/price-matrix');
        if(!target.isConnected)return;
        const source=(matrix.cells||[]).filter(c=>c.result?.mode==='PRIVATE');
        const configured=(matrix.config?.bands||[]).map(b=>b.min===b.max?String(b.min):String(b.min)+'–'+String(b.max));
        const bands=[...new Set([...configured,...source.map(c=>String(c.band_key))])];
        const list=bands.length?bands:initial;
        const selectCell=(band,variant)=>source.filter(c=>
          String(c.band_key)===String(band) && Number(c.variant_id)===Number(variant))
          .sort((a,b)=>['B2B_AGENT','B2C_DIRECT'].indexOf(a.channel)-['B2B_AGENT','B2C_DIRECT'].indexOf(b.channel))[0];
        const rows=list.sort((a,b)=>(parseInt(a,10)||0)-(parseInt(b,10)||0));
        target.innerHTML=rows.map(band=>'<div class="vta-group-row">' +
          '<div class="vta-group-size"><b>'+escapeHTML(band)+'</b><small>paying pax / group</small></div>' +
          requested.map(id=>{
            const cell=selectCell(band,id),scenarios=cell?.result?.scenarios||[];
            const cost=scenarios[0]?.pricing?.cost_per_paying_pax_vnd;
            const valid=cost!==undefined&&cost!==null&&cost!=='';
            return '<div class="vta-group-value"><strong>'+ (valid?money(cost):'—') +'</strong>' +
              '<small>'+ (cell?escapeHTML(cell.channel||'Private')+' · '+escapeHTML(cell.status||'Review required'):'Chưa có kịch bản')+'</small></div>';
          }).join('')+'</div>').join('');
        const note=host.querySelector('[data-group-status]');
        if(note)note.textContent=source.length?'Dữ liệu Pricing Matrix hiện có · rà soát trạng thái từng kịch bản trước báo giá':
          'Chưa tạo Pricing Matrix cho Private Tour. Hãy cấu hình từng nhóm khách trong phần Price.';
      }catch(_error){
        if(!target.isConnected)return;
        target.innerHTML=initial.map(n=>'<div class="vta-group-row">' +
          '<div class="vta-group-size"><b>'+n+'</b><small>paying pax / group</small></div>' +
          requested.map(()=>'<div class="vta-group-value"><strong>—</strong><small>Chưa có giá xác nhận</small></div>').join('') + '</div>').join('');
        const note=host.querySelector('[data-group-status]');
        if(note)note.textContent='Chưa truy cập được Pricing Matrix. Không sử dụng giá từ quy mô nhóm hiện tại để ngoại suy.';
      }
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
      host.innerHTML='<div class="page cost-sheet vta-direct-cost vta-approved-cost">' +
        '<div class="page-head"><div><small class="vta-demo-eyebrow">VTA TOUR OPERATOR / SMART COST</small><h1>' + escapeHTML(version.tour_name || quote.quote_ref) + '</h1>' +
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
        '<label>FOC Guests<input class="vta-guest-input" type="text" value="'+escapeHTML(guest.foc ?? Math.max(0,Number(guest.total_guests||0)-Number(guest.paying_pax||0)))+'" disabled aria-label="FOC Guests"></label>' +
        '<label>Total Guests<input class="vta-guest-input" type="text" value="'+escapeHTML(guest.total_guests??'')+'" disabled aria-label="Total Guests"></label>' +
        '<label>Hotel Pax' + input('type="number" min="0" max="10000" data-guest="hotel_pax" inputmode="numeric"',profile.hotel_pax ?? '','vta-guest-input','placeholder="Review"') + '</label>' +
        '<label>Cruise Pax' + input('type="number" min="0" max="10000" data-guest="cruise_pax" inputmode="numeric"',profile.cruise_pax ?? '','vta-guest-input','placeholder="Review"') +
        '</label><small>Guest composition is managed in Info. FOC costs stay included; cost/pax uses Paying Pax.</small></div>' +
        '<div class="vta-summary-title"><span>Three live costing options</span><small>All costs are calculated by the server · VND</small></div>' +
        '<div class="vta-cost-summaries">' + packages.map(buildSummary).join('') + '</div>' +
        '<section class="vta-cost-section vta-private-groups" data-private-groups>' +
        '<header><div><small class="vta-group-label">PRIVATE TOUR · GROUP SIZE COMPARISON</small>' +
        '<h2>Tour riêng theo từng nhóm khách</h2><p>So sánh chi phí mỗi khách theo số lượng người trả tiền</p></div>' +
        '<button type="button" class="btn" data-open-group-price>Mở Price →</button></header>' +
        '<div class="vta-group-columns"><span>Nhóm khách</span>' +
        packages.map((p,i)=>'<span>Option '+String.fromCharCode(65+i)+' · H'+escapeHTML(parseInt(p.hotel_level,10))+'★ / C'+escapeHTML(p.cruise_level??'—')+'★</span>').join('')+'</div>' +
        '<div class="vta-group-data" data-pax-matrix-body role="status">Đang tải báo giá theo quy mô đoàn…</div>' +
        '<p class="vta-group-footnote">Chỉ hiển thị chi phí do Pricing Matrix tính trên dữ liệu đã lưu. Chưa có kịch bản thì để trống, không tự suy diễn giá xe/guide hoặc tỷ lệ FOC. Đơn vị: VND / paying pax.</p>' +
        '<p class="vta-group-status" data-group-status role="status"></p>' +
        '</section>' +
        '<div class="vta-add-bar">' +
        (edit?selectHTML(options,'HOTEL','data-new-service aria-label="Service to add"') +
          '<button type="button" class="btn primary" data-add>+ Add Service</button>':'') +
        (pendingUndo && edit ? '<button type="button" class="btn" data-undo>Undo remove</button>':'') + '</div>' +
        card('A. Common Services',common,false) +
        '<details class="vta-advanced-stays"><summary>B. Hotel & Cruise · Detailed rate matrix</summary>' +
        card('B. Hotel & Cruise · Each destination has its own rate',stays,true) + '</details>' +
        '<section class="vta-cost-section vta-overview"><header><h2>C. Costing Overview</h2>' +
        '<small>3 options above · paying PAX excludes FOC</small></header>' +
        '<p>Rates, reviews and totals originate from the VTA server. No artificial sample rates are used in this quotation.</p></section>' +
        '<div class="vta-cost-bottom"><button type="button" class="btn" data-check>Check Quote</button>' +
        '<button type="button" class="btn primary" data-next>Next: Price →</button></div>' +
        '<div class="vta-validation" data-validation></div>' +
        '<p class="vta-disclaimer">Cost by pax: Hotel = pax × nights × VND/pax/night; Cruise = pax × packages × VND/pax/package. ' +
        'Missing supplier rates remain unresolved, never zero. Supplier approval and pricing validation are enforced by the server.</p>' +
        '</div>';
      bind();
      loadGroupMatrix();
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
      if(isStay(r)){
        const tier = r.category === 'HOTEL' ? parseInt(p.hotel_level,10) : p.cruise_level;
        const matching=packages.filter(candidate=>
          (r.category === 'HOTEL'?parseInt(candidate.hotel_level,10):candidate.cruise_level)===tier);
        const variantIds=matching.map(candidate=>Number(candidate.variant_id));
        return sheet({requirement_id:Number(r.id),shared:false,variant_ids:variantIds,
          lines:Object.fromEntries(variantIds.map(id=>[id,manual]))});
      }
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
        const draft={...r.metadata,...pendingPropertyMeta.get(id)};
        const names={...(draft[key]||{})};
        names[star]=e.target.value.trim();
        draft[key]=names;pendingPropertyMeta.set(id,draft);
        sheet({requirement:{id,metadata:draft},line:{}},true);
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
      host.querySelector('[data-open-group-price]').onclick=()=>{host.dataset.sheetStep='price';refresh();};
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
      // Inline Hotel/Cruise inputs share the same persisted requirement and supplier validation.
      host.querySelectorAll('[data-vta-option-stay]').forEach(card=>{
        const id=Number(card.dataset.requirement),index=Number(card.dataset.index);
        const r=context.requirements.find(x=>Number(x.id)===id),p=packages[index];
        if(!r || !p || !edit)return;
        const storeRate=()=>{
          const field=card.querySelector('[data-option-rate]');
          if(!field || field.value==='' || !Number.isFinite(Number(field.value)) || Number(field.value)<0){
            status('Enter a valid rate; missing rate is not zero');return;
          }
          const proof=card.querySelector('[data-proof]'),supplier=proof?.querySelector('[data-supplier]')?.value,
            reason=proof?.querySelector('[data-evidence]')?.value.trim();
          if(!supplier || !reason){
            if(proof)proof.open=true;
            status('Select supplier and evidence before saving this rate');
            proof?.querySelector(!supplier?'[data-supplier]':'[data-evidence]')?.focus();
            return;
          }
          const manual={supplier_id:Number(supplier),original_currency:'VND',
            unit_amount_original:field.value,manual_reason:reason,
            manual_contract:{evidence:reason,tax_basis:'NET'}};
          sheet({requirement_id:id,shared:false,variant_ids:[Number(p.variant_id)],
            lines:{[p.variant_id]:manual}},true);
        };
        card.querySelector('[data-option-rate]')?.addEventListener('change',storeRate);
        card.querySelector('[data-apply]')?.addEventListener('click',storeRate);
        card.querySelector('[data-option-property]')?.addEventListener('change',event=>{
          const key=r.category==='HOTEL'?'hotel_names':'cruise_names';
          const star=r.category==='HOTEL'?parseInt(p.hotel_level,10):p.cruise_level;
          const names={...(r.metadata?.[key]||{})};
          names[star]=event.target.value.trim();
          sheet({requirement:{id,metadata:{...r.metadata,[key]:names}},line:{}},true);
        });
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
