'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const root = require('node:path').join(__dirname, '..');
const script = fs.readFileSync(root + '/vta-plus-assist.js', 'utf8');
const markup = fs.readFileSync(root + '/index.html', 'utf8');
const preview = fs.readFileSync(root + '/preview.html', 'utf8');
const editor = fs.readFileSync(root + '/document-editor.js', 'utf8');
const studio = fs.readFileSync(root + '/tour-proposal-studio.js', 'utf8');
const sales = fs.readFileSync(root + '/workspace-centers.js', 'utf8');
const sw = fs.readFileSync(root + '/service-worker.js', 'utf8');
const sandbox = {window: {}};
vm.runInNewContext(script, sandbox, {filename:'vta-plus-assist.js'});
const assist = sandbox.window.VTAPlusAssist;
assert.equal(typeof assist.open, 'function');
for (const mode of ['itinerary','sales','document']) {
  const prompt = assist.buildPrompt(mode);
  assert.ok(prompt.includes('Vietnam Travel Advisor'));
  assert.ok(prompt.includes('[') && prompt.length > 100);
  assert.ok(!/api key value|bearer [a-z0-9]+/i.test(prompt));
}
assert.equal(assist.buildPrompt('unknown'), assist.buildPrompt('itinerary'));
assert.match(assist.buildPrompt('sales'), /B2B/);
assert.match(script, /window\.open\(CHATGPT_URL/);
assert.doesNotMatch(script, /fetch\s*\(|localStorage|sessionStorage|api\.request\s*\(/);
assert.match(editor, /data-wd.*plus/);
assert.match(studio, /data-action="plus"/);
assert.match(sales, /ChatGPT Plus/);
for (const page of [markup, preview]) {
  assert.match(page, /vta-plus-assist\.js\?v=PLUS1/);
  assert.match(page, /vta-plus-assist\.css\?v=PLUS1/);
  assert.ok(page.indexOf('vta-plus-assist.js') < page.indexOf('document-editor.js'));
  assert.ok(page.indexOf('vta-plus-assist.js') < page.indexOf('tour-proposal-studio.js'));
}
assert.match(sw, /vta-plus-assist\.js\?v=PLUS1/);
assert.match(sw, /vta-plus-assist\.css\?v=PLUS1/);
console.log('PASS Plus companion: prompts, privacy, source wiring and PWA assets');
