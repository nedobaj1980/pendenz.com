const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('assets/js/finance-tables.js', 'utf8');
const context = { window: {}, document: { readyState: 'loading', addEventListener() {} }, localStorage: { getItem() { return null; }, setItem() {} }, console };
vm.runInNewContext(source, context);
const api = context.window.PendenzFinanceTables;

if (!api) throw new Error('Finance table API fehlt');
const rows = [{ cells: ['B', '10'], raw: ['b', '10'] }, { cells: ['A', '2'], raw: ['a', '2'] }];
const sorted = api.sortRows(rows, 0, 'asc');
if (sorted[0].raw[0] !== 'a') throw new Error('Textsortierung fehlgeschlagen');
const numeric = api.sortRows(rows, 1, 'asc');
if (numeric[0].raw[1] !== '2') throw new Error('Numerische Sortierung fehlgeschlagen');
const filtered = api.filterRows(rows, 'a');
if (filtered.length !== 1 || filtered[0].raw[0] !== 'a') throw new Error('Filter fehlgeschlagen');
console.log('OK');
