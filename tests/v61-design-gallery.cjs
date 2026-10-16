'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const file=fs.readFileSync('templates/vta-v6/gallery.html','utf8');
const docs=fs.readFileSync('templates/vta-v6/README.md','utf8');
for (const name of ['detailed','brochure','quick','b2b']) {
 assert(file.includes("'"+name+"'"),'Missing template mode '+name);
 assert(docs.includes(name),'Undocumented mode '+name);
}
assert(file.includes("aria-pressed"),'Template picker must expose current selection');
assert(file.includes("textContent"),'Use text-safe DOM rendering for tour content');
assert(file.includes("window.print()"),'Print preview missing');
assert(file.includes("document.createElement")||file.includes("createElement("),'Semantic DOM builder missing');
assert(!/Day \[Number\]|\[Destination\]/.test(file),'No fictional daily placeholders');
assert(!/fetch\s*\(|localStorage|indexedDB|XMLHttpRequest/.test(file),'Visual template must be read-only and offline');
assert(!/<script[^>]+src\s*=/.test(file),'No remote dependencies');
const inline=file.match(/<script>([\s\S]*?)<\/script>/);
assert(inline,'Missing controller');
new vm.Script(inline[1],{filename:'vta-v61-templates-gallery-inline.js'});
console.log('PASS 4 template modes, DOM safety contracts, offline render, print and JS syntax');
