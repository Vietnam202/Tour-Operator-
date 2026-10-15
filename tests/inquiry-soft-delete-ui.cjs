'use strict';
const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'app.js'), 'utf8');
const core = fs.readFileSync(path.join(root, 'api/lib/CoreOS.php'), 'utf8');
const lifecycle = fs.readFileSync(path.join(root, 'api/lib/InquiryLifecycle.php'), 'utf8');
const queue = fs.readFileSync(path.join(root, 'api/lib/WorkspaceCenter.php'), 'utf8');
const migration = fs.readFileSync(path.join(root, 'api/migrations/049_inquiry_soft_delete.sql'), 'utf8');

const fn = app.split('\n').find(line => line.startsWith('function salesInquiries(items)'));
assert.ok(fn, 'Sales & B2B inquiry rendering function must be present');

function render(archived, permissions, ref = 'INQ-2026-000001') {
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  })[ch]);
  const compile = new Function('state', 'can', 'esc', 'badge', 'n', 'fdate', 'fdt', 'items',
    fn + '\nreturn salesInquiries(items);');
  return compile({ inquiriesArchived: archived }, key => permissions.includes(key), esc,
    value => String(value), value => Number(value ?? 0), value => value ?? '—',
    value => value ?? '—',
    [{ id: 31, inquiry_ref: ref, title: 'Example', lead_contact_name: 'Guest',
       market: 'India', total_guests: 7, paying_pax: 6, foc: 1, status: 'QUOTED' }]);
}

test('app.js is syntactically valid', () => {
  assert.doesNotThrow(() => new Function(app));
});

test('managing Sales user sees × and Create Quote for active Inquiry', () => {
  const html = render(false, ['inquiry.manage', 'quote.create']);
  assert.match(html, /data-archive-inquiry="31"/);
  assert.match(html, /data-create-quote="31"/);
  assert.match(html, /aria-label="Xóa Lead INQ-2026-000001"/);
  assert.match(html, /data-toggle-archived/);
  assert.doesNotMatch(html, /data-restore-inquiry/);
});

test('read-only user cannot remove or restore an Inquiry', () => {
  const html = render(false, ['sales.view']);
  assert.doesNotMatch(html, /data-archive-inquiry/);
  assert.doesNotMatch(html, /data-restore-inquiry/);
  assert.doesNotMatch(html, /data-toggle-archived/);
});

test('trashed Inquiry is restorable, never quoteable from its row', () => {
  const html = render(true, ['inquiry.manage', 'quote.create']);
  assert.match(html, /data-restore-inquiry="31"/);
  assert.doesNotMatch(html, /data-create-quote/);
  assert.doesNotMatch(html, /data-archive-inquiry/);
});

test('user-provided Inquiry reference is HTML escaped', () => {
  const html = render(false, ['inquiry.manage'], '<img src=x onerror=alert(1)>');
  assert.doesNotMatch(html, /<img src=/);
  assert.match(html, /&lt;img src=x/);
});

test('API contract: tenant, permissions, safeguards, and no hard delete', () => {
  assert.match(core, /inquiries\/\(\\d\+\)\/\(archive\|restore\)/);
  assert.match(core, /'inquiry\.manage'/);
  assert.match(lifecycle, /FROM inquiries WHERE company_id=\? AND id=\? FOR UPDATE/);
  assert.match(core, /'LINKED_COMMERCIAL_RECORD'/);
  assert.match(core, /deleted_at IS NULL/);
  assert.doesNotMatch(core, /DELETE FROM inquiries/i);
  assert.match(queue, /i\.deleted_at IS NULL/);
  assert.match(migration, /ADD COLUMN deleted_at DATETIME NULL/);
  assert.match(migration, /ADD COLUMN deleted_by BIGINT UNSIGNED NULL/);
});
