const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');

// Exercise the shipped module and its DOM events; only the API and app shell are doubles.
const source = fs.readFileSync(path.join(__dirname, '../operations-center.js'), 'utf8');
const tiles = ['intake', 'today', 'bookings', 'arrivals', 'schedule', 'guides', 'transport', 'hotel', 'activities', 'confirmations', 'vouchers', 'passengers', 'tasks', 'incidents', 'payments', 'reports'];
const date = new Date().toISOString().slice(0, 10);
const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
const copy = value => JSON.parse(JSON.stringify(value));

function fixture() {
  const booking = { id: 1, booking_ref: 'VTA-OPS-001', lead_guest_name: 'Alex Nguyen', title: 'Vietnam Discovery', start_date: date, end_date: date, total_guests: 2, operations_status: 'READY', customer_payment_status: 'PAID', readiness_pct: 100, risk_level: 'LOW' };
  const service = { id: 11, booking_id: 1, booking_ref: booking.booking_ref, lead_guest_name: booking.lead_guest_name, category: 'TRANSPORT', service_name: 'Airport pickup', service_date: date, start_time: '09:00:00', end_time: '10:00:00', booking_status: 'CONFIRMED', supplier_name: 'VTA Transport', pickup_location: 'Noi Bai', dropoff_location: 'Hanoi Hotel', driver_name: 'Driver Linh', driver_mobile: '0900000000', vehicle_type: 'Sedan', vehicle_plate: '30A-12345' };
  const services = [service, { ...service, id: 12, category: 'HOTEL', service_name: 'Hanoi Hotel', room_type: 'Twin', check_in: date, check_out: date }, { ...service, id: 13, category: 'GUIDE', service_name: 'Hanoi city guide', guide_name: 'Guide Mai' }, { ...service, id: 14, category: 'CRUISE', service_name: 'Ha Long Cruise' }, { ...service, id: 15, category: 'ATTRACTION', service_name: 'Cooking class' }];
  return {
    'v3/operations': { date, movement: services, readiness: [{ booking_id: 1, booking_ref: booking.booking_ref, readiness_pct: 100, ready: true, checks: { supplier_confirmation: true, resource_assignment: true, guest_list: true, no_critical_issues: false, current_travel_pack: true }, services: [{ service_id: 11, service_name: service.service_name, resources_ready: true, confirmed: true, assigned: [{ id: 21, kind: 'DRIVER', name: 'Driver Linh', starts_at: `${date} 08:00:00`, ends_at: `${date} 18:00:00` }] }] }], issues: [{ id: 31, booking_id: 1, booking_ref: booking.booking_ref, title: 'Flight time changed', severity: 'MEDIUM', status: 'OPEN' }] },
    'v3/resources': { items: [{ id: 21, kind: 'GUIDE', name: 'Guide Mai', phone: '0900000001', status: 'ACTIVE' }, { id: 22, kind: 'DRIVER', name: 'Driver Linh', phone: '0900000000', status: 'ACTIVE' }, { id: 23, kind: 'VEHICLE', name: 'Sedan', capacity: 4, status: 'ACTIVE' }] },
    bookings: { items: [booking] },
    'bookings/1': { booking, trip: { title: booking.title, schedule_json: JSON.stringify([{ day: 1, date, title: 'Hanoi arrival', description: 'Welcome to Vietnam' }]) }, services, guests: [{ id: 1, full_name: 'Alex Nguyen', room_group: 'A', room_preference: 'TWIN - quiet room', nationality: 'VN' }, { id: 2, full_name: 'Sam Nguyen', room_group: 'A', room_preference: 'TWIN', nationality: 'VN' }], flights: [{ id: 1, flight_type: 'ARRIVAL', flight_date: date, arrival_time: '08:00:00', origin_code: 'SGN', destination_code: 'HAN', flight_number: 'VN123', departure_time: '06:00:00', status: 'CONFIRMED' }] },
    'supplier-orders': { items: [{ id: 41, booking_id: 1, booking_ref: booking.booking_ref, order_ref: 'SO-001', supplier_name: 'VTA Transport', status: 'CONFIRMED', start_date: date, service_count: 1 }] },
    tasks: { items: [{ id: 51, title: 'Recheck pickup', entity_type: 'booking', entity_id: 1, booking_id: 1, booking_ref: booking.booking_ref, priority: 'HIGH', status: 'OPEN', due_at: `${date} 07:00:00`, owner_name: 'Operations' }] },
    'finance/ap': { items: [{ id: 61, booking_id: 1, booking_ref: booking.booking_ref, payable_ref: 'AP-001', supplier_name: 'VTA Transport', total_amount: 1200000, paid_amount: 0, balance: 1200000, currency: 'VND', due_date: date, status: 'UNPAID' }] },
    'tasks/51/complete': { ok: true }, 'services/11/assign': { ok: true, id: 71 }, 'issues/31/resolve': { ok: true, status: 'RESOLVED' }, 'payables/61/payments': { ok: true }
  };
}

function harness({ responses = fixture(), can = () => true, fail = () => false, wait = async () => {} } = {}) {
  const dom = new JSDOM('<main id="workspace" data-view="operations"></main>', { url: 'https://localhost/', runScripts: 'outside-only' });
  const w = dom.window, d = w.document;
  const calls = [], navigation = [], documents = [], serviceDetails = [], errors = [];
  w.addEventListener('error', event => { errors.push(event.error || event.message); event.preventDefault(); });
  const api = { request: async (route, options = {}) => {
    calls.push({ route, options });
    await wait(route);
    if (fail(route)) throw new Error('Operations API unavailable');
    const base = route.split(/[?&]/)[0];
    assert(Object.hasOwn(responses, base), 'Unexpected API request: ' + route);
    return copy(responses[base]);
  } };
  const deps = { api, esc, can, navigate: (...args) => navigation.push(args), toast: () => {}, openTravelDocuments: id => documents.push(id), openServiceDetails: id => serviceDetails.push(id), closeModal: () => d.querySelector('[data-test-modal]')?.remove(), modal: (title, body, footer = '') => {
    const m = d.createElement('section');
    m.dataset.testModal = '';
    m.innerHTML = `<h2>${esc(title)}</h2>${body}<footer>${footer}</footer>`;
    d.body.append(m);
    return m;
  } };
  responses['operations/intake']={items:[]};
  w.eval(fs.readFileSync(path.join(__dirname,'../handover.js'),'utf8'));
  w.eval(source);
  const render = async () => { await w.VTAOperationsCenter(deps); await settle(); };
  return { dom, w, d, calls, navigation, documents, serviceDetails, errors, render, close: () => w.close() };
}

async function settle() {
  // API promises resolve immediately; drain the event handler's follow-up renders.
  for (let i = 0; i < 6; i++) await new Promise(resolve => setImmediate(resolve));
}
async function click(h, selector) {
  const element = h.d.querySelector(selector);
  assert(element, 'Missing interactive element: ' + selector);
  element.click();
  await settle();
  return element;
}
async function open(h, key) {
  await click(h, `[data-op-tile="${key}"]`);
  assert(h.d.querySelector('[data-op-home]'), 'Module ' + key + ' must offer return to the card center');
  assert.equal(h.errors.length, 0, 'Module ' + key + ' raised a browser error');
}
async function home(h) { await click(h, '[data-op-home]'); }
async function submit(h, selector, values) {
  const form = h.d.querySelector(selector);
  assert(form, 'Missing form: ' + selector);
  for (const [name, value] of Object.entries(values)) {
    const input = form.querySelector('[name="' + name + '"]');
    assert(input, 'Missing form field: ' + name);
    input.value = value;
  }
  form.dispatchEvent(new h.w.Event('submit', { bubbles: true, cancelable: true }));
  await settle();
}
function selectBooking(h) {
  const select = h.d.querySelector('[data-op-booking-select]');
  assert(select, 'Booking module must offer a booking selector');
  select.value = '1';
  select.dispatchEvent(new h.w.Event('change', { bubbles: true }));
  return settle();
}

(async () => {
  let h = harness();
  try {
    await h.render();
    assert.deepEqual([...h.d.querySelectorAll('[data-op-tile]')].map(tile => tile.dataset.opTile).sort(), [...tiles].sort());
    assert(h.d.body.textContent.includes('Operations Control Center'));
    for (const key of tiles) { await open(h, key); await home(h); }
    assert(h.calls.every(call => !call.route.includes('&date=')), 'Dates must use a real query string');
    console.log('PASS all 16 operations cards open functional modules and return home');
  } finally { h.close(); }

  const twoBookings = fixture();
  const second = { ...twoBookings.bookings.items[0], id: 2, booking_ref: 'VTA-OPS-002', lead_guest_name: 'Linh Tran' };
  twoBookings.bookings.items.push(second);
  twoBookings['bookings/2'] = { ...copy(twoBookings['bookings/1']), booking: second };
  h = harness({ responses: twoBookings });
  try {
    await h.render();
    await open(h, 'passengers');
    const select = h.d.querySelector('[data-op-booking-select]');
    select.value = '2';
    select.dispatchEvent(new h.w.Event('change', { bubbles: true }));
    await settle();
    assert(h.calls.some(call => call.route === 'bookings/2'));
    await click(h, '[data-op-open-booking]');
    assert.deepEqual(copy(h.navigation.at(-1)), ['booking', { bookingId: 2, bookingTab: 'guests' }]);
    await home(h);
    await open(h, 'hotel');
    assert.equal(h.d.querySelector('[data-op-booking-select]').value, '2');
    await click(h, '[data-op-open-booking]');
    assert.deepEqual(copy(h.navigation.at(-1)), ['booking', { bookingId: 2, bookingTab: 'services' }]);
    console.log('PASS changing booking reloads the right record and preserves selection across modules');
  } finally { h.close(); }

  let release;
  const pendingBookings = new Promise(resolve => { release = resolve; });
  h = harness({ wait: route => route === 'bookings' ? pendingBookings : Promise.resolve() });
  try {
    await h.render();
    await open(h, 'arrivals');
    await home(h);
    release();
    await settle();
    assert.equal(h.d.querySelectorAll('[data-op-tile]').length, tiles.length);
    assert.equal(h.d.querySelector('[data-op-booking-select]'), null);
    console.log('PASS a late booking response cannot replace a newer navigation');
  } finally { release(); h.close(); }

  let releaseTask;
  const pendingTask = new Promise(resolve => { releaseTask = resolve; });
  h = harness({ wait: route => route === 'tasks/51/complete' ? pendingTask : Promise.resolve() });
  try {
    await h.render();
    await open(h, 'tasks');
    await click(h, '[data-op-task-done="51"]');
    const workspace = h.d.querySelector('#workspace');
    workspace.dataset.view = 'booking';
    workspace.innerHTML = '<article data-booking-workspace>Booking VTA-OPS-001</article>';
    releaseTask();
    await settle();
    assert(h.d.querySelector('[data-booking-workspace]'), 'Late completion overwrote the new booking workspace');
    assert.equal(workspace.dataset.view, 'booking');
    assert.equal(h.d.querySelector('.ops-center'), null);
    console.log('PASS late task completion leaves an external booking workspace intact');
  } finally { releaseTask(); h.close(); }

  let releaseIncident;
  const pendingIncident = new Promise(resolve => { releaseIncident = resolve; });
  h = harness({ wait: route => route === 'bookings' ? pendingIncident : Promise.resolve() });
  try {
    await h.render();
    await open(h, 'incidents');
    await click(h, '[data-op-new-incident]');
    await home(h);
    releaseIncident();
    await settle();
    assert.equal(h.d.querySelectorAll('[data-op-tile]').length, tiles.length);
    assert.equal(h.d.querySelector('[data-test-modal]'), null, 'Late incident loader opened a dialog after navigation');
    console.log('PASS late incident loader cannot open a dialog after returning home');
  } finally { releaseIncident(); h.close(); }

  h = harness();
  try {
    await h.render();
    await open(h, 'activities');
    assert(h.d.body.textContent.includes('Cooking class'), 'ATTRACTION service must appear in Cruise & Activities');
    await home(h);
    await open(h, 'passengers');
    assert(h.d.body.textContent.includes('TWIN - quiet room'), 'Actual guest room_preference must appear in the passenger list');
    console.log('PASS actual attraction category and guest room preference are displayed');
  } finally { h.close(); }

  h = harness();
  try {
    await h.render();
    await open(h, 'today');
    const form = h.d.querySelector('[data-op-date-form]');
    assert(form, 'Operations date filter must be a form');
    const input = form.querySelector('[name="date"]');
    assert(input);
    input.value = '2026-12-09';
    const before = h.calls.length;
    form.dispatchEvent(new h.w.Event('submit', { bubbles: true, cancelable: true }));
    await settle();
    assert(h.calls.slice(before).some(call => call.route === 'v3/operations?date=2026-12-09'));
    assert.equal(h.d.querySelector('[data-op-date-form] [name="date"]').value, '2026-12-09');
    const refreshStart = h.calls.length;
    await click(h, '[data-op-refresh]');
    assert(h.calls.slice(refreshStart).some(call => call.route === 'v3/operations?date=2026-12-09'));
    console.log('PASS movement date and refresh request the selected date with correct route contract');
  } finally { h.close(); }

  for (const [key, endpoint, value] of [['guides', 'v3/resources', { items: {} }], ['payments', 'finance/ap', { items: null }], ['bookings', 'bookings', { items: 'invalid' }], ['confirmations', 'supplier-orders', {}], ['tasks', 'tasks', { items: {} }]]) {
    const responses = fixture();
    responses[endpoint] = value;
    h = harness({ responses });
    try {
      await h.render();
      await open(h, key);
      assert(h.d.querySelector('[data-op-retry]'), 'Malformed ' + endpoint + ' response must offer retry');
      assert(h.d.querySelector('[role="alert"]'), 'Malformed ' + endpoint + ' must be an error rather than a misleading empty list');
    } finally { h.close(); }
  }
  for (const field of ['services', 'guests', 'flights']) {
    const responses = fixture();
    responses['bookings/1'][field] = {};
    h = harness({ responses });
    try {
      await h.render();
      await open(h, 'passengers');
      assert(h.d.querySelector('[data-op-retry]'), 'Malformed booking ' + field + ' must offer retry');
    } finally { h.close(); }
  }
  console.log('PASS malformed list endpoints and booking detail arrays show a visible retry');

  h = harness();
  try {
    await h.render();
    await open(h, 'bookings');
    await click(h, '[data-op-open-booking]');
    assert.deepEqual(copy(h.navigation.at(-1)), ['booking', { bookingId: 1, bookingTab: 'overview' }]);
    await home(h);
    for (const [key, tab] of [['arrivals', 'flights'], ['schedule', 'services'], ['hotel', 'services'], ['passengers', 'guests']]) {
      await open(h, key);
      await selectBooking(h);
      const action = h.d.querySelector('[data-op-open-booking]');
      assert(action, key + ' must provide an action to its booking workspace');
      action.click(); await settle();
      assert.deepEqual(copy(h.navigation.at(-1)), ['booking', { bookingId: 1, bookingTab: tab }]);
      await home(h);
    }
    console.log('PASS booking modules deep-link to the selected booking and relevant tab');
  } finally { h.close(); }

  const malformed = {
    'v3/operations': { date, movement: { invalid: true }, readiness: null, issues: 'invalid' },
    'v3/resources': { items: {} }, bookings: { items: null },
    'bookings/1': { booking: {}, trip: {}, services: {}, guests: null, flights: 'invalid' },
    'supplier-orders': { items: null }, tasks: { items: 'invalid' }, 'finance/ap': { items: {} }
  };
  h = harness({ responses: malformed });
  try {
    await h.render();
    for (const key of tiles) {
      await open(h, key);
      assert(!/cannot read properties|\.map is not a function|unable to load this workspace/i.test(h.d.body.textContent), 'Malformed arrays broke ' + key);
      await home(h);
    }
    console.log('PASS missing and non-array API fields render empty or retry states without map crashes');
  } finally { h.close(); }

  h = harness({ can: permission => permission === 'operations.view' });
  try {
    await h.render();
    for (const key of ['payments', 'vouchers']) {
      const tile = h.d.querySelector(`[data-op-tile="${key}"]`);
      assert(tile, key + ' remains discoverable with a permission state');
      assert(tile.disabled || tile.getAttribute('aria-disabled') === 'true', key + ' must be disabled without permission');
      const before = h.calls.length;
      tile.click(); await settle();
      assert.equal(h.calls.length, before, 'A disabled module made an API request');
    }
    assert(!h.calls.some(call => call.route.startsWith('finance/ap') || call.route.includes('travel-documents')));
    assert.equal(h.documents.length, 0);
    await open(h, 'today');
    assert(!h.d.querySelector('[data-op-assign], [data-op-resolve]'), 'Manage actions must be absent without service.manage');
    console.log('PASS restricted cards stay disabled and do not fetch forbidden data');
  } finally { h.close(); }

  h = harness();
  try {
    await h.render();
    await open(h, 'today');
    await click(h, '[data-op-assign="11"]');
    await submit(h, '[data-op-form]', { resource_id: '21', starts_at: '2026-12-09T08:00', ends_at: '2026-12-09T18:00' });
    const saved = h.calls.find(call => call.route === 'services/11/assign');
    assert(saved);
    assert.equal(saved.options.method, 'POST');
    assert.deepEqual(copy(saved.options.body), { resource_id: 21, starts_at: '2026-12-09 08:00:00', ends_at: '2026-12-09 18:00:00' });
    assert(!h.d.querySelector('[data-test-modal]'));
    console.log('PASS resource assignment submits typed IDs and local timestamps through existing API');
  } finally { h.close(); }

  h = harness();
  try {
    await h.render();
    await open(h, 'tasks');
    await click(h, '[data-op-task-done="51"]');
    const saved = h.calls.find(call => call.route === 'tasks/51/complete');
    assert(saved);
    assert.equal(saved.options.method, 'POST');
    assert.equal(h.calls.filter(call => call.route === 'tasks/51/complete').length, 1);
    await home(h);
    await open(h, 'incidents');
    await click(h, '[data-op-resolve="31"]');
    await submit(h, '[data-op-form]', { root_cause: 'Flight changed', resolution: 'Pickup rescheduled', lessons_learned: 'Check flight again', financial_impact: '0', currency: 'VND' });
    const resolved = h.calls.find(call => call.route === 'issues/31/resolve');
    assert.equal(resolved?.options.method, 'POST');
    assert.equal(resolved.options.body.resolution, 'Pickup rescheduled');
    assert.equal(resolved.options.body.currency, 'VND');
    console.log('PASS tasks complete and incident resolution use existing mutation contracts');
  } finally { h.close(); }

  h = harness({ fail: route => route === 'services/11/assign' });
  try {
    await h.render();
    await open(h, 'today');
    await click(h, '[data-op-assign="11"]');
    await submit(h, '[data-op-form]', { resource_id: '21', starts_at: '2026-12-09T08:00', ends_at: '2026-12-09T18:00' });
    const form = h.d.querySelector('[data-op-form]');
    assert(form, 'Failed save must retain entered form');
    assert.equal(form.querySelector('[name="resource_id"]').value, '21');
    assert(form.querySelector('[data-op-form-error]').textContent.includes('Operations API unavailable'));
    assert.equal(form.querySelector('[type="submit"]').disabled, false);
    console.log('PASS failed assignment retains form and re-enables retry');
  } finally { h.close(); }

  const usdPayables = fixture();
  Object.assign(usdPayables['finance/ap'].items[0], { total_amount: 1200, balance: 1200, currency: 'USD' });
  h = harness({ responses: usdPayables });
  try {
    await h.render();
    await open(h, 'payments');
    await click(h, '[data-op-pay="61"]');
    const form = h.d.querySelector('[data-op-payment-form]');
    assert(form, 'Payable action must open the supplier payment form');
    assert(form.textContent.includes('1200 USD'), 'Payment form must show the original currency balance');
    assert.equal(form.querySelector('[name="currency"]'), null, 'Original payable currency must not be editable');
    // Even extra form data cannot change the payable currency sent to the ledger.
    const injected = h.d.createElement('input');
    injected.name = 'currency'; injected.value = 'VND'; form.append(injected);
    await submit(h, '[data-op-payment-form]', { amount: '325.50', payment_date: '2026-12-09', transaction_reference: 'BANK-USD-001' });
    const saved = h.calls.find(call => call.route === 'payables/61/payments');
    assert(saved, 'Supplier payment must post to the selected payable');
    assert.equal(saved.options.method, 'POST');
    const { idempotency_key, ...payment } = saved.options.body;
    assert.equal(typeof idempotency_key, 'string');
    assert(idempotency_key.trim(), 'Payment requires a nonempty idempotency key');
    assert.deepEqual(copy(payment), { amount: '325.50', payment_date: '2026-12-09', transaction_reference: 'BANK-USD-001', currency: 'USD' });
    assert.equal(h.d.querySelector('[data-op-payment-form]'), null, 'Successful payment must close its form');
    assert(h.calls.filter(call => call.route === 'finance/ap').length >= 2, 'Successful payment must reload supplier balances');
    console.log('PASS supplier payment records amount, date and reference with fixed original currency and an idempotency key');
  } finally { h.close(); }

  h = harness({ fail: route => route === 'payables/61/payments' });
  try {
    await h.render();
    await open(h, 'payments');
    await click(h, '[data-op-pay="61"]');
    const entered = { amount: '450000', payment_date: '2026-12-09', transaction_reference: 'BANK-VND-RETRY' };
    await submit(h, '[data-op-payment-form]', entered);
    const form = h.d.querySelector('[data-op-payment-form]');
    assert(form, 'Failed supplier payment must retain the dialog');
    for (const [name, value] of Object.entries(entered)) assert.equal(form.querySelector('[name="' + name + '"]').value, value, 'Failed payment lost ' + name);
    assert(form.querySelector('[role="alert"]').textContent.includes('Operations API unavailable'));
    assert.equal(form.querySelector('[type="submit"]').disabled, false, 'Failed payment must re-enable retry');
    await submit(h, '[data-op-payment-form]', entered);
    let attempts = h.calls.filter(call => call.route === 'payables/61/payments');
    assert.equal(attempts.length, 2);
    assert.deepEqual(copy(attempts[1].options.body), copy(attempts[0].options.body), 'Retrying unchanged payment must retain the exact payload and idempotency key');
    assert(attempts[0].options.body.idempotency_key, 'First attempt requires an idempotency key');
    await submit(h, '[data-op-payment-form]', { ...entered, amount: '500000' });
    attempts = h.calls.filter(call => call.route === 'payables/61/payments');
    assert.equal(attempts.length, 3);
    assert.equal(attempts[2].options.body.amount, '500000');
    assert.notEqual(attempts[2].options.body.idempotency_key, attempts[1].options.body.idempotency_key, 'Editing payment amount must create a new idempotency key');
    assert.equal(form.querySelector('[type="submit"]').disabled, false);
    console.log('PASS failed payment retains typed fields; unchanged retries reuse their key and changed payments receive a new key');
  } finally { h.close(); }

  h = harness({ can: permission => ['supplier_ap.view', 'supplier_payment.record'].includes(permission) });
  try {
    await h.render();
    await open(h, 'payments');
    assert.equal(h.d.querySelector('[data-op-open-booking]'), null, 'AP-only user must not receive a forbidden booking finance action');
    await click(h, '[data-op-pay="61"]');
    await submit(h, '[data-op-payment-form]', { amount: '250000', payment_date: '2026-12-09', transaction_reference: 'AP-ONLY-001' });
    assert(h.calls.some(call => call.route === 'payables/61/payments'));
    assert(h.calls.every(call => call.route === 'finance/ap' || call.route === 'payables/61/payments'), 'AP-only payment fetched unrelated booking, customer AR or operations data');
    assert.equal(h.navigation.length, 0, 'AP-only payment must remain in its permitted workspace');
    assert.equal(h.errors.length, 0);
    console.log('PASS AP-only users record supplier payments without booking, customer AR or operations requests');
  } finally { h.close(); }

  let releasePayment;
  const pendingPayment = new Promise(resolve => { releasePayment = resolve; });
  h = harness({ wait: route => route === 'payables/61/payments' ? pendingPayment : Promise.resolve() });
  try {
    await h.render();
    await open(h, 'payments');
    await click(h, '[data-op-pay="61"]');
    await submit(h, '[data-op-payment-form]', { amount: '250000', payment_date: '2026-12-09', transaction_reference: 'AP-LATE-001' });
    assert.equal(h.d.querySelector('[data-op-payment-form] [type="submit"]').disabled, true, 'In-flight supplier payment must disable duplicate submission');
    const workspace = h.d.querySelector('#workspace');
    workspace.dataset.view = 'booking';
    workspace.innerHTML = '<article data-booking-workspace>Booking VTA-OPS-001</article>';
    h.d.querySelector('[data-test-modal]').remove();
    const bookingDialog = h.d.createElement('section');
    bookingDialog.dataset.testModal = '';
    bookingDialog.dataset.bookingDialog = '';
    bookingDialog.textContent = 'Edit booking VTA-OPS-001';
    h.d.body.append(bookingDialog);
    const before = h.calls.length;
    releasePayment();
    await settle();
    assert(h.d.querySelector('[data-booking-workspace]'), 'Late supplier payment overwrote the new booking workspace');
    assert.equal(workspace.dataset.view, 'booking');
    assert.equal(h.d.querySelector('.ops-center'), null);
    assert.equal(h.calls.length, before, 'Late supplier payment issued a refresh for a workspace already left');
    assert.equal(h.d.querySelector('[data-booking-dialog]'), bookingDialog, 'Late supplier payment closed a newer unrelated booking dialog');
    console.log('PASS late supplier payment preserves another workspace and its dialog without refreshing it');
  } finally { releasePayment(); h.close(); }

  const hostile = fixture();
  const payload = '<img src=x onerror="window.opsXss=1"><script>window.opsXss=2</script>';
  hostile.bookings.items[0].lead_guest_name = payload;
  hostile['bookings/1'].booking.lead_guest_name = payload;
  hostile['bookings/1'].guests[0].full_name = payload;
  hostile['v3/operations'].movement[0].service_name = payload;
  hostile['v3/operations'].issues[0].title = payload;
  hostile['v3/resources'].items[0].name = payload;
  hostile['supplier-orders'].items[0].supplier_name = payload;
  hostile.tasks.items[0].title = payload;
  hostile['finance/ap'].items[0].supplier_name = payload;
  h = harness({ responses: hostile });
  try {
    await h.render();
    for (const key of tiles) {
      await open(h, key);
      const select = h.d.querySelector('[data-op-booking-select]');
      if (select?.querySelector('[value="1"]')) await selectBooking(h);
      assert.equal(h.d.querySelector('script, img[onerror], [onload]'), null, 'Untrusted content became executable DOM in ' + key);
      assert.equal(h.w.opsXss, undefined);
      await home(h);
    }
    console.log('PASS API text is escaped across operations modules');
  } finally { h.close(); }

  let unavailable = true;
  h = harness({ fail: route => unavailable && route.startsWith('v3/operations') });
  try {
    await h.render();
    await open(h, 'today');
    assert(h.d.body.textContent.includes('Operations API unavailable'));
    assert(h.d.querySelector('[data-op-retry]'), 'Failed module must offer retry');
    unavailable = false;
    await click(h, '[data-op-retry]');
    assert(h.d.body.textContent.includes('Airport pickup'));
    assert(!h.d.querySelector('[data-op-retry]'));
    console.log('PASS API failure remains visible and retry restores the module');
  } finally { h.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
