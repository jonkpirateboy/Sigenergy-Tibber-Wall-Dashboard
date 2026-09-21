const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element() {
    return {
        children: [], textContent: '',
        replaceChildren() { this.children = []; },
        append(...children) { this.children.push(...children); }
    };
}
const nodes = new Map();
const context = vm.createContext({
    window: { location: { search: '' }, dashboardTranslations: {
        'monthlyCost.consumption': 'Förbrukning', 'monthlyCost.production': 'Export',
        'monthlyCost.fee': 'Månadsavg.'
    } },
    document: {
        documentElement: { lang: 'sv' },
        getElementById(id) {
            if (!nodes.has(id)) nodes.set(id, element());
            return nodes.get(id);
        },
        createElement: element
    },
    URLSearchParams,
    fetch: () => new Promise(() => {}),
    setInterval() {}
});
vm.runInContext(fs.readFileSync(`${__dirname}/../www/js/dashboard.js`, 'utf8'), context);
assert.equal(context.monthlyCostCutoff('2026-09-17T11:00:00Z'), '17/9 13:00');
assert.equal(context.monthlyCostCutoff('2026-09-16T22:00:00Z'), '17/9 00:00');
assert.equal(context.monthlyCostCutoff('2026-12-31T23:00:00Z'), '1/1 00:00');
assert.equal(context.monthlyCostCutoff(null), '');
assert.equal(context.monthlyCostCutoff('invalid'), '');

const cost = {
    month: '2026-09', throughDate: '2026-09-17', throughAt: '2026-09-17T13:00:00+02:00',
    consumptionThroughAt: '2026-09-17T13:00:00+02:00', productionThroughAt: '2026-09-17T12:00:00+02:00',
    consumptionCost: 20, productionProfit: 30, monthCost: -10, currency: 'SEK'
};
context.renderMonthlyCost(cost);
const output = nodes.get('monthlyCost');
assert.match(output.children[0].textContent, /september 17\/9 13:00: [−-]10,00 SEK/i);
assert.equal(output.children[2].title, 'Förbrukning: 17/9 13:00 · Export: 17/9 12:00');
context.renderMonthlyCost({ ...cost, throughAt: undefined });
assert.match(output.children[0].textContent, /september 17\/9: [−-]10,00 SEK/i);
assert.doesNotMatch(output.children[0].textContent, /13:00/);
context.renderMonthlyCost({ ...cost, monthlyFee: 49, monthCost: 39 });
assert.match(output.children[0].textContent, /september 17\/9 13:00: 39,00 SEK/i);
assert.equal(output.children[2].textContent, 'Förbrukning 20,00 SEK - Export 30,00 SEK + Månadsavg. 49,00 SEK');
console.log('Monthly cost label checks passed');
