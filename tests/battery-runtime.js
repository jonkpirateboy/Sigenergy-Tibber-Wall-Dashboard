const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const context = vm.createContext({
    window: {
        location: { search: '' },
        dashboardTranslations: JSON.parse(fs.readFileSync(`${__dirname}/../www/lang/sv.json`, 'utf8'))
    },
    document: { getElementById: () => ({}) },
    URLSearchParams,
    fetch: () => new Promise(() => {}),
    setInterval() {}
});
vm.runInContext(fs.readFileSync(`${__dirname}/../www/js/dashboard.js`, 'utf8'), context);

// Live export snapshot: 22.491 kWh above reserve supplies 13.2 kW, not just the house's 0.49 kW.
const exporting = { loadPower: 0.49, gridPower: 12.71, pvPower: 0, batteryPower: -13.2, batterySoc: 88.3 };
assert.equal(context.batteryOnlyRuntime(exporting, 27, 5), '1 h 42 min');
assert.equal(context.solarBatteryRuntime(exporting, 27, 5), '1 h 42 min');
assert.equal(context.batteryFullRuntime(exporting, 27), 'Laddar inte');

// Ten usable kWh; PV covers the house but the battery still supplies part of the export.
const solarExport = { loadPower: 1, gridPower: 4, pvPower: 3, batteryPower: -2, batterySoc: 60 };
assert.equal(context.batteryOnlyRuntime(solarExport, 20, 10), '2 h 0 min');
assert.equal(context.solarBatteryRuntime(solarExport, 20, 10), '5 h 0 min');

// Export alone is still a load; surplus solar can cover both house and export.
assert.equal(context.batteryOnlyRuntime({ ...solarExport, loadPower: 0 }, 20, 10), '2 h 30 min');
assert.equal(context.solarBatteryRuntime({ ...solarExport, loadPower: 0 }, 20, 10), '10 h 0 min');
const chargingExport = { ...solarExport, pvPower: 7, batteryPower: 2 };
assert.equal(context.solarBatteryRuntime(chargingExport, 20, 10), '∞');
assert.equal(context.batteryFullRuntime(chargingExport, 20), '4 h 0 min');

// Grid import is neither export demand nor a source in these hypothetical runtimes.
const importing = { ...solarExport, loadPower: 2, pvPower: 1, gridPower: -3, batteryPower: 2 };
assert.equal(context.batteryOnlyRuntime(importing, 20, 10), '5 h 0 min');
assert.equal(context.solarBatteryRuntime(importing, 20, 10), '10 h 0 min');
for (const gridPower of [0, undefined]) {
    assert.equal(context.batteryOnlyRuntime({ ...importing, gridPower }, 20, 10), '5 h 0 min');
    assert.equal(context.solarBatteryRuntime({ ...importing, gridPower }, 20, 10), '10 h 0 min');
}
for (const estimate of [context.batteryOnlyRuntime, context.solarBatteryRuntime]) {
    assert.equal(estimate({ ...exporting, batterySoc: 5 }, 27, 5), 'Batteriet tomt');
    assert.equal(estimate({ loadPower: 0, gridPower: 0, batterySoc: 60 }, 20, 10), 'Ingen last');
}
assert.equal(context.batteryFullRuntime({ ...exporting, batterySoc: 100 }, 27), 'Fullt');
assert.equal(context.batteryFullRuntime({ ...exporting, batteryPower: 0 }, 27), 'Laddar inte');
console.log('Battery runtime checks passed');
