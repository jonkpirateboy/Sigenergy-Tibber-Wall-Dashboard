const els = Object.fromEntries([
    'source', 'updated', 'incomingTotal', 'outgoingTotal',
    'incomingSolar', 'incomingGrid', 'incomingIdle', 'outgoingSolar',
    'outgoingBattery', 'outgoingGrid', 'outgoingExport', 'outgoingIdle',
    'batteryPercent', 'batteryLevel', 'batteryOnlyTime', 'solarBatteryTime',
    'batteryFullTime', 'current-energy', 'priceTodayAverage',
    'priceTomorrowAverage', 'priceTodayBars', 'priceTomorrowBars',
    'monthlyCost'
].map((id) => [id, document.getElementById(id)]));

const i18n = window.dashboardTranslations || {};
const params = new URLSearchParams(window.location.search);
const isPreview = params.has('preview');
const MIN_VISIBLE_POWER = 0.05;
const MIN_VISIBLE_COLUMN = 0.16;

function t(key) {
    return i18n[key] || key;
}

const missingEls = Object.entries(els)
    .filter(([, node]) => !node)
    .map(([id]) => id);

if (missingEls.length) {
    throw new Error(`Missing dashboard elements: ${missingEls.join(', ')}`);
}

function power(value) {
    return `${Number(value || 0).toLocaleString('sv-SE', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1
    })} kW`;
}

function price(value) {
    if (!Number.isFinite(Number(value))) return '--';

    return `${Number(value).toLocaleString(document.documentElement.lang || 'sv-SE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })} kr`;
}

function money(value, currency = 'SEK') {
    if (value === null || value === undefined || value === '') return '--';
    if (!Number.isFinite(Number(value))) return '--';

    return `${Number(value).toLocaleString(document.documentElement.lang || 'sv-SE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })} ${currency}`;
}

function setSegment(node, label, value, displayValue = value) {
    if (!node) return;

    node.classList.toggle('is-empty', value < MIN_VISIBLE_POWER);
    node.querySelector('span').textContent = label;
    node.querySelector('strong').textContent = power(displayValue);
}

function setSpacerSegment(node, value) {
    if (!node) return;

    node.classList.toggle('is-empty', value < MIN_VISIBLE_POWER);
    node.querySelector('span').textContent = '';
    node.querySelector('strong').textContent = '';
}

function setBar(bar, values) {
    if (!bar) return;

    const columns = values.map((value) => value >= MIN_VISIBLE_POWER ? `${Math.max(MIN_VISIBLE_COLUMN, value)}fr` : '0fr');
    bar.style.gridTemplateColumns = columns.join(' ');
}

function formatRuntime(hours) {
    let wholeHours = Math.floor(hours);
    let minutes = Math.round((hours - wholeHours) * 60);

    if (minutes === 60) {
        wholeHours += 1;
        minutes = 0;
    }

    if (wholeHours <= 0) return `${minutes} min`;
    return `${wholeHours} h ${minutes} min`;
}

function availableBatteryKwh(flow, capacityKwh, reservePercent) {
    const soc = Number(flow.batterySoc || 0);
    const reserve = Math.max(0, Math.min(100, Number(reservePercent ?? 10)));
    const usableSoc = Math.max(0, soc - reserve);

    return Math.max(0, Number(capacityKwh || 8) * (usableSoc / 100));
}

function batteryOnlyRuntime(flow, capacityKwh, reservePercent) {
    const load = Math.max(0, Number(flow.loadPower || 0));
    const batteryKwh = availableBatteryKwh(flow, capacityKwh, reservePercent);

    if (load <= 0.05) return t('runtime.noLoad');
    if (batteryKwh <= 0.05) return t('runtime.batteryEmpty');

    return formatRuntime(batteryKwh / load);
}

function solarBatteryRuntime(flow, capacityKwh, reservePercent) {
    const load = Math.max(0, Number(flow.loadPower || 0));
    const pv = Math.max(0, Number(flow.pvPower || 0));
    const batteryLoad = Math.max(0, load - pv);
    const batteryKwh = availableBatteryKwh(flow, capacityKwh, reservePercent);

    if (load <= 0.05) return t('runtime.noLoad');
    if (batteryLoad <= 0.05) return t('runtime.infinity');
    if (batteryKwh <= 0.05) return t('runtime.batteryEmpty');

    return formatRuntime(batteryKwh / batteryLoad);
}

function batteryFullRuntime(flow, capacityKwh) {
    const soc = Math.max(0, Math.min(100, Number(flow.batterySoc || 0)));
    const batteryCharge = Math.max(0, Number(flow.batteryPower || 0));
    const capacity = Math.max(0, Number(capacityKwh || 0));
    const remainingKwh = capacity * ((100 - soc) / 100);

    if (soc >= 99.5) return t('runtime.batteryFull');
    if (batteryCharge <= 0.05 || remainingKwh <= 0.05) return t('runtime.notCharging');

    return formatRuntime(remainingKwh / batteryCharge);
}

async function refresh() {
    try {
        const response = await fetch('?api=snapshot', { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        render(data);
    } catch (error) {
        els.source.textContent = t('source.offline');
        els.updated.textContent = `JS/API: ${error.message}`;
    }
}

async function refreshPrices() {
    try {
        const response = await fetch('?api=prices', { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        renderPrices(data);
    } catch (error) {
        els['current-energy'].textContent = '--';
        els.monthlyCost.textContent = t('monthlyCost.noData');
        els.priceTodayBars.dataset.empty = error.message;
        els.priceTomorrowBars.dataset.empty = error.message;
    }
}

function render(data) {
    const flow = data.flow || {};
    const pv = Math.max(0, Number(flow.pvPower || 0));
    const load = Math.max(0, Number(flow.loadPower || 0));
    const grid = Number(flow.gridPower || 0);
    const battery = Number(flow.batteryPower || 0);
    const gridImport = Math.max(0, -grid);
    const gridExport = Math.max(0, grid);
    const batteryDischarge = Math.max(0, -battery);
    const incomingTotal = pv + gridImport;
    const solarUsed = Math.min(load, pv);
    const remainingAfterSolar = Math.max(0, load - solarUsed);
    const batteryUsed = Math.min(remainingAfterSolar, batteryDischarge);
    const remainingAfterBattery = Math.max(0, remainingAfterSolar - batteryUsed);
    const gridUsed = Math.min(remainingAfterBattery, gridImport);
    const usageTotal = solarUsed + batteryUsed + gridUsed + gridExport;
    const usageScaleTotal = Math.max(incomingTotal, usageTotal);
    const unusedPower = Math.max(0, usageScaleTotal - usageTotal);
    const soc = Math.max(0, Math.min(100, Number(flow.batterySoc || 0)));

    const sourceLabels = {
        live: t('source.live'),
        offline: t('source.offline'),
        demo: t('source.demo')
    };
    els.source.textContent = sourceLabels[data.source] || t('source.demo');
    els.source.className = data.source === 'live' ? 'is-live' : 'is-demo';
    const updatedAt = new Date(data.updatedAt).toLocaleTimeString('sv-SE', {
        hour: '2-digit',
        minute: '2-digit'
    });

    els.updated.textContent = data.message ? `${updatedAt} - ${data.message}` : updatedAt;

    els.incomingTotal.textContent = power(incomingTotal);
    els.outgoingTotal.textContent = power(usageTotal);

    setBar(document.getElementById('incomingBar'), incomingTotal < MIN_VISIBLE_POWER ? [0, 0, 1] : [pv, gridImport, 0]);
    setBar(
        document.getElementById('outgoingBar'),
        usageTotal < MIN_VISIBLE_POWER ? [0, 0, 0, 0, 1] : [solarUsed, batteryUsed, gridUsed, gridExport, unusedPower]
    );
    setSegment(els.incomingSolar, t('incoming.solar'), pv);
    setSegment(els.incomingGrid, t('incoming.grid'), gridImport);
    setSegment(els.incomingIdle, t('incoming.none'), incomingTotal < MIN_VISIBLE_POWER ? 1 : 0, 0);
    setSegment(els.outgoingSolar, t('usage.solar'), solarUsed);
    setSegment(els.outgoingBattery, t('usage.battery'), batteryUsed);
    setSegment(els.outgoingGrid, t('usage.grid'), gridUsed);
    setSegment(els.outgoingExport, t('usage.export'), gridExport);
    setSpacerSegment(els.outgoingIdle, unusedPower);

    els.batteryPercent.textContent = `${Math.round(soc)}%`;
    els.batteryLevel.style.width = `${soc}%`;
    els.batteryLevel.classList.toggle('is-charging', battery > 0.05);
    els.batteryLevel.classList.toggle('is-discharging', battery < -0.05);
    els.batteryOnlyTime.textContent = batteryOnlyRuntime(flow, data.batteryCapacityKwh, data.batteryReservePercent);
    els.solarBatteryTime.textContent = solarBatteryRuntime(flow, data.batteryCapacityKwh, data.batteryReservePercent);
    els.batteryFullTime.textContent = batteryFullRuntime(flow, data.batteryCapacityKwh);

    document.body.dataset.source = data.source || 'demo';
}

function renderPrices(data) {
    const today = Array.isArray(data.today) ? data.today : [];
    const tomorrow = Array.isArray(data.tomorrow) ? data.tomorrow : [];

    els['current-energy'].textContent = data.current ? price(data.current.total) : '--';
    els.priceTodayAverage.textContent = averagePrice(today);
    els.priceTomorrowAverage.textContent = tomorrow.length ? averagePrice(tomorrow) : t('price.noTomorrow');

    renderPriceGraph(els.priceTodayBars, today, true, data.breakpoints || {});
    renderPriceGraph(els.priceTomorrowBars, tomorrow, false, data.breakpoints || {});
    renderMonthlyCost(data.monthlyCost);
}

function renderMonthlyCost(monthlyCost) {
    if (!monthlyCost) {
        els.monthlyCost.textContent = t('monthlyCost.noData');
        return;
    }

    const currency = monthlyCost.currency || 'SEK';
    const consumption = money(monthlyCost.consumptionCost, currency);
    const production = money(monthlyCost.productionProfit, currency);
    const total = money(monthlyCost.monthCost, currency);

    els.monthlyCost.replaceChildren();

    const totalNode = document.createElement('span');
    totalNode.className = 'monthly-cost-total';
    totalNode.textContent = `${t('monthlyCost.total')} ${total}`;

    const itemsNode = document.createElement('span');
    itemsNode.className = 'monthly-cost-items';
    itemsNode.textContent = `${t('monthlyCost.consumption')} ${consumption} - ${t('monthlyCost.production')} ${production}`;

    els.monthlyCost.append(totalNode, ' ', itemsNode);
}

function renderPreview() {
    const incomingValues = {
        solar: 3.4,
        grid: 2.6,
    };
    const usageValues = {
        solar: 1.5,
        battery: 0.8,
        grid: 1.2,
        export: 1.4,
        idle: 1.1,
    };
    const incomingTotal = incomingValues.solar + incomingValues.grid;
    const usageTotal = usageValues.solar + usageValues.battery + usageValues.grid + usageValues.export;
    const now = new Date();

    els.source.textContent = t('source.preview');
    els.source.className = 'is-live';
    els.updated.textContent = now.toLocaleTimeString('sv-SE', {
        hour: '2-digit',
        minute: '2-digit'
    });

    els.incomingTotal.textContent = power(incomingTotal);
    els.outgoingTotal.textContent = power(usageTotal);

    setBar(document.getElementById('incomingBar'), [incomingValues.solar, incomingValues.grid, 0]);
    setBar(document.getElementById('outgoingBar'), [
        usageValues.solar,
        usageValues.battery,
        usageValues.grid,
        usageValues.export,
        usageValues.idle
    ]);
    setSegment(els.incomingSolar, t('incoming.solar'), incomingValues.solar);
    setSegment(els.incomingGrid, t('incoming.grid'), incomingValues.grid);
    setSegment(els.incomingIdle, t('incoming.none'), 0);
    setSegment(els.outgoingSolar, t('usage.solar'), usageValues.solar);
    setSegment(els.outgoingBattery, t('usage.battery'), usageValues.battery);
    setSegment(els.outgoingGrid, t('usage.grid'), usageValues.grid);
    setSegment(els.outgoingExport, t('usage.export'), usageValues.export);
    setSpacerSegment(els.outgoingIdle, usageValues.idle);

    els.batteryPercent.textContent = '64%';
    els.batteryLevel.style.width = '64%';
    els.batteryLevel.classList.add('is-charging');
    els.batteryLevel.classList.remove('is-discharging');
    els.batteryOnlyTime.textContent = '4 h 20 min';
    els.solarBatteryTime.textContent = '7 h 45 min';
    els.batteryFullTime.textContent = '2 h 10 min';

    renderPrices(previewPrices(now));
    document.body.dataset.source = 'preview';
}

function previewPrices(now) {
    const today = previewPriceHours(now, 0);
    const tomorrow = previewPriceHours(now, 1);

    return {
        current: today.find((hour) => isCurrentPricePeriod(hour, now)) || today[0],
        today,
        tomorrow,
        breakpoints: {
            very_expensive: 3,
            expensive: 2,
            ok: 1,
            cheap: 0.5
        },
        monthlyCost: {
            currency: 'SEK',
            consumptionCost: 842.35,
            productionProfit: 214.7,
            monthCost: 627.65
        }
    };
}

function previewPriceHours(now, dayOffset) {
    const start = new Date(now);
    start.setDate(start.getDate() + dayOffset);
    start.setHours(0, 0, 0, 0);

    return Array.from({ length: 24 }, (_, hour) => {
        const startsAt = new Date(start);
        startsAt.setHours(hour);
        const endsAt = new Date(startsAt);
        endsAt.setHours(hour + 1);
        const total = 0.35 + Math.max(0, Math.sin((hour - 5) / 24 * Math.PI * 2)) * 0.95
            + Math.max(0, Math.sin((hour - 16) / 24 * Math.PI * 2)) * 1.15
            + dayOffset * 0.12;

        return {
            startsAt: startsAt.toISOString(),
            endsAt: endsAt.toISOString(),
            total: Number(total.toFixed(2))
        };
    });
}

function averagePrice(hours) {
    const values = hours.map((hour) => Number(hour.total)).filter(Number.isFinite);
    if (!values.length) return '--';

    return price(values.reduce((sum, value) => sum + value, 0) / values.length);
}

function renderPriceGraph(node, hours, highlightCurrent, breakpoints) {
    const values = hours.map((hour) => Number(hour.total));
    const validValues = values.filter(Number.isFinite);
    node.innerHTML = '';

    if (!validValues.length) {
        node.dataset.empty = t('price.noData');
        return;
    }

    delete node.dataset.empty;

    const width = 240;
    const height = 82;
    const paddingTop = 8;
    const paddingRight = 8;
    const paddingBottom = 8;
    const paddingLeft = 8;
    const min = Math.min(...validValues);
    const max = Math.max(...validValues);
    const spread = Math.max(0.01, max - min);
    const now = new Date();
    const currentIndex = highlightCurrent
        ? hours.findIndex((hour) => isCurrentPricePeriod(hour, now))
        : -1;
    const points = hours.map((hour, index) => {
        const value = Number(hour.total);
        const x = paddingLeft + (index / Math.max(1, hours.length - 1)) * (width - paddingLeft - paddingRight);
        const y = height - paddingBottom - ((value - min) / spread) * (height - paddingTop - paddingBottom);

        return { x, y, value, hour };
    }).filter((point) => Number.isFinite(point.value));

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('preserveAspectRatio', 'none');

    const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    line.setAttribute('points', points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' '));
    line.setAttribute('class', 'price-line');
    svg.appendChild(line);

    if (currentIndex >= 0 && points[currentIndex]) {
        const marker = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        marker.setAttribute('x1', points[currentIndex].x);
        marker.setAttribute('x2', points[currentIndex].x);
        marker.setAttribute('y1', paddingTop);
        marker.setAttribute('y2', height - paddingBottom);
        marker.setAttribute('class', 'price-now-marker');
        svg.appendChild(marker);
    }

    node.appendChild(svg);
    renderPriceYAxisLabels(node, min, max);

    points.forEach((point, index) => {
        const dot = document.createElement('span');
        const left = (point.x / width) * 100;
        const top = (point.y / height) * 100;
        dot.className = index === currentIndex ? 'price-dot is-current' : 'price-dot';
        dot.style.left = `${left}%`;
        dot.style.top = `${top}%`;
        dot.style.backgroundColor = priceColor(point.value, breakpoints);
        dot.title = `${new Date(point.hour.startsAt).getHours().toString().padStart(2, '0')}:00 ${price(point.value)}`;
        node.appendChild(dot);

        if (index === currentIndex) {
            const label = document.createElement('span');
            label.className = 'price-current-label';
            if (left < 18) label.classList.add('is-left');
            if (left > 82) label.classList.add('is-right');
            if (top < 24) label.classList.add('is-top');
            label.style.left = `${left}%`;
            label.style.top = `${top}%`;
            label.textContent = price(point.value);
            node.appendChild(label);
        }
    });

    renderPriceTimeLabels(node, points, width);
}

function renderPriceYAxisLabels(node, min, max) {
    [
        ['is-top', max],
        ['is-bottom', min]
    ].forEach(([position, value]) => {
        const label = document.createElement('span');
        label.className = `price-y-label ${position}`;
        label.textContent = price(value);
        node.appendChild(label);
    });
}

function renderPriceTimeLabels(node, points, width) {
    const labelHours = new Set([0, 6, 12, 18, 23]);
    const usedHours = new Set();

    points.forEach((point) => {
        const date = new Date(point.hour.startsAt);
        const hour = date.getHours();
        if (!labelHours.has(hour) || usedHours.has(hour)) return;

        const label = document.createElement('span');
        const left = (point.x / width) * 100;
        label.className = 'price-time-label';
        if (left < 8) label.classList.add('is-left');
        if (left > 92) label.classList.add('is-right');
        label.style.left = `${left}%`;
        label.textContent = `${hour.toString().padStart(2, '0')}:00`;
        node.appendChild(label);
        usedHours.add(hour);
    });
}

function isCurrentPricePeriod(hour, now) {
    const startsAt = new Date(hour.startsAt);
    const endsAt = hour.endsAt ? new Date(hour.endsAt) : new Date(startsAt.getTime() + 60 * 60 * 1000);

    return now >= startsAt && now < endsAt;
}

function priceColor(value, breakpoints) {
    const bp = {
        very_expensive: Number(breakpoints.very_expensive ?? 3),
        expensive: Number(breakpoints.expensive ?? 2),
        ok: Number(breakpoints.ok ?? 1),
        cheap: Number(breakpoints.cheap ?? 0.5)
    };

    if (value > bp.very_expensive) return '#ff6961';
    if (value > bp.expensive) return '#f09a37';
    if (value > bp.ok) return '#f5c84b';
    if (value > bp.cheap) return '#9fdb6c';
    return '#41c979';
}

if (isPreview) {
    renderPreview();
} else {
    refresh();
    refreshPrices();
    setInterval(refresh, 300000);
    setInterval(refreshPrices, 300000);
}
