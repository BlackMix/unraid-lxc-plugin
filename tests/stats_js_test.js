/*
 * Runtime test for the live stats script of LXC.page.
 * Runs the real inline JS with a browser-like non-writable `history` global,
 * which is what broke the first version of the sparkline code.
 *
 * Usage: node tests/stats_js_test.js
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pagePath = path.join(__dirname, '..', 'source', 'usr', 'local', 'emhttp',
  'plugins', 'lxc', 'LXC.page');
const source = fs.readFileSync(pagePath, 'utf8');

const marker = source.indexOf('var containerNames');
assert.ok(marker > 0, 'stats script not found');

const start = source.lastIndexOf('<script>', marker) + '<script>'.length;
const end = source.indexOf('</script>', marker);
let js = source.slice(start, end);

js = js.replace(/var containerNames = <\?=[\s\S]*?\?>;/, "var containerNames = ['ct1'];")
       .replace(/var setMemory = <\?=[\s\S]*?\?>;/, "var setMemory = {'ct1':'2GiB'};")
       .replace(/var cpuCount = <\?=[\s\S]*?\?>\s*\|\|\s*1;/, 'var cpuCount = 4;');

assert.ok(!/\bvar history\b/.test(js), 'the script must not shadow window.history');

const elements = Object.create(null);
function element(id) {
  if (!elements[id]) elements[id] = { id, innerHTML: '' };
  return elements[id];
}

let payload = [{ name: 'ct1', cpu_usage: '50', memoryUse: '512MiB', ips: '192.168.1.5', totalBytes: '1MiB' }];

const sandbox = {
  console, JSON, Math, Object, Number, Array, String, Date,
  document: { getElementById: element },
  window: { location: { hash: '' } },
  setInterval() {},
  $: {
    ajax(options) {
      options.success(payload);
    },
  },
};

// In a browser `history` is a read-only global. Reproduce that exactly.
Object.defineProperty(sandbox, 'history', {
  value: { pushState() {} },
  writable: false,
  configurable: false,
});

vm.createContext(sandbox);
vm.runInContext(js, sandbox);

function run() {
  sandbox.updateValues();
  return {
    cpu: element('cpu_usage-ct1').innerHTML,
    mem: element('memoryUse-ct1').innerHTML,
  };
}

// First sample renders even when it is zero.
let result = run();
assert.ok(result.cpu.includes('lxcv2-spark'), 'CPU sparkline was not rendered');
assert.ok(result.mem.includes('lxcv2-spark'), 'memory sparkline was not rendered');
assert.ok(result.cpu.includes('13%'), `unexpected CPU label: ${result.cpu}`);
assert.ok(result.mem.includes('512MiB / 2GiB'), `unexpected memory label: ${result.mem}`);

// Repeating the same value must not touch the DOM.
const beforeCpu = result.cpu;
const beforeMem = result.mem;
run();
assert.strictEqual(element('cpu_usage-ct1').innerHTML, beforeCpu, 'unchanged CPU value repainted');
assert.strictEqual(element('memoryUse-ct1').innerHTML, beforeMem, 'unchanged memory value repainted');

// A later zero must also be ignored.
payload = [{ name: 'ct1', cpu_usage: '0', memoryUse: '0', ips: '', totalBytes: '' }];
run();
assert.strictEqual(element('cpu_usage-ct1').innerHTML, beforeCpu, 'later CPU zero repainted');
assert.strictEqual(element('memoryUse-ct1').innerHTML, beforeMem, 'later memory zero repainted');

// A real change must repaint.
payload = [{ name: 'ct1', cpu_usage: '80', memoryUse: '1GiB', ips: '192.168.1.5', totalBytes: '1MiB' }];
result = run();
assert.notStrictEqual(element('cpu_usage-ct1').innerHTML, beforeCpu, 'CPU change not repainted');
assert.ok(element('cpu_usage-ct1').innerHTML.includes('20%'), 'CPU value did not update');
assert.ok(element('memoryUse-ct1').innerHTML.includes('1GiB / 2GiB'), 'memory value did not update');

console.log('ALL STATS JS TESTS PASSED');
