/* Track scale page script. Server values come from the JSON block
 * #sts-data-track-scale (written by track_scale.php) because the page runs under a
 * strict Content-Security-Policy: no inline <script> code. */
const TS_DATA = (window.STS && STS.data('sts-data-track-scale')) || {};
const CONFIG = TS_DATA.config;
const TRACK_SCALE_UI = TS_DATA.ui;
const PRECISION = CONFIG.precision ?? 2;
const ACCESSIBLE_UI_KEY = 'track_scale_accessible_ui';
const SCALE_MODE_KEY = 'track_scale_mode';
let selectedTestCarMarks = TS_DATA.testCarMarks;

function applyAccessibleUi(enabled) {
    document.body.classList.toggle('track-scale-accessible', !!enabled);
    const toggle = document.getElementById('accessibleUiToggle');
    if (toggle && toggle.checked !== !!enabled) {
        toggle.checked = !!enabled;
    }
    try {
        localStorage.setItem(ACCESSIBLE_UI_KEY, enabled ? '1' : '0');
    } catch (e) {
        /* ignore quota / private mode */
    }
}

(function initAccessibleUiPreference() {
    let enabled = false;
    try {
        enabled = localStorage.getItem(ACCESSIBLE_UI_KEY) === '1';
    } catch (e) {
        enabled = false;
    }
    applyAccessibleUi(enabled);
    const toggle = document.getElementById('accessibleUiToggle');
    if (toggle) {
        toggle.addEventListener('change', () => applyAccessibleUi(toggle.checked));
    }
})();

let currentCar = null;
let currentProfile = null;
let currentReading = null;
let currentRouting = null;
let currentOpenOrders = [];
let selectedCarId = null;
let scaleInService = true;
let pendingNextCar = null;
let pendingPrevCar = null;
let manualReassignMode = false;
/** In-memory weigh/assign UI per car for this page load only. */
const weighSessionCache = new Map();
let restoringWeighSession = false;
const SENSOR_POSITIONS = ['left', 'center', 'right'];

function snapshotWeighSession(carId) {
    if (restoringWeighSession || !carId || !currentReading) return;
    const orderSection = document.getElementById('orderSection');
    const select = document.getElementById('orderSelect');
    const assignResult = document.getElementById('assignResult');
    const weighResult = document.getElementById('weighResult');
    const led = document.getElementById('weightLed');
    const ledLabel = document.getElementById('weightLedLabel');
    weighSessionCache.set(String(carId), {
        reading: currentReading,
        routing: currentRouting,
        nextCar: pendingNextCar,
        prevCar: pendingPrevCar,
        manualReassignMode: !!manualReassignMode,
        orderVisible: !!(orderSection && !orderSection.classList.contains('d-none')),
        selectedWaybill: select ? String(select.value || '') : '',
        assignResultHtml: assignResult ? assignResult.innerHTML : '',
        weighResultHtml: weighResult ? weighResult.innerHTML : '',
        ledState: led ? (led.dataset.state || 'off') : 'off',
        ledLabel: ledLabel ? ledLabel.textContent : '',
        showReassignBtn: !!(document.getElementById('reassignBtn')
            && !document.getElementById('reassignBtn').classList.contains('d-none')),
    });
}

function clearWeighSession(carId) {
    if (carId == null) return;
    weighSessionCache.delete(String(carId));
}

function applyReadingDisplays(reading) {
    if (!reading) return;
    document.getElementById('displayGross').textContent = fmt(reading.gross_tons);
    document.getElementById('displayNet').textContent = fmt(reading.net_tons);
    setNetReadingVisible(true);
    const breakdownEl = document.getElementById('sensorBreakdown');
    if (breakdownEl) {
        if (reading.sensor_readings && reading.sensor_readings.length) {
            breakdownEl.textContent = reading.sensor_readings
                .map(s => s.position.charAt(0).toUpperCase() + ': ' + fmt(s.display_tons))
                .join(' · ');
        } else {
            breakdownEl.textContent = '';
        }
    }
}

const SCALE_SETTLE_MS = 3000;
/** Calibration place-settle is shorter (smaller car / tighter UI feedback). */
const SCALE_SETTLE_CAL_MS = 2000;
/** Changes/sec at the start of settle (mild flicker — not too top-heavy). */
const SCALE_SETTLE_RATE_START = 5;
/** Changes/sec near the end (slow stabilizing ticks). */
const SCALE_SETTLE_RATE_END = 1;
/** Small up/down band around the final weight (tons). */
const SCALE_SETTLE_AMP_TONS = 0.35;
let scaleSettleGen = 0;
let scaleSettleTimer = null;
let calPlaceBusy = false;
let lastCalibrationSnapshot = null;

function abortScaleSettle() {
    scaleSettleGen += 1;
    if (scaleSettleTimer) {
        clearTimeout(scaleSettleTimer);
        scaleSettleTimer = null;
    }
    document.querySelectorAll('.scale-display.is-settling').forEach(el => {
        el.classList.remove('is-settling');
    });
}

/** Ease-in quad: 0 → 1 — gentler than cubic so early ticks aren’t too dense. */
function settleEaseInQuad(t) {
    const x = Math.min(1, Math.max(0, t));
    return x * x;
}

/**
 * Natural stabilize curve: dense ticks early, sparse at the end.
 * Integrates rate from rateStart → rateEnd along an ease-in over durationMs.
 */
function buildSettleStepDelays(options) {
    options = options || {};
    const durationMs = Math.max(200, Number(options.durationMs) || SCALE_SETTLE_MS);
    const rateStart = Math.max(1, Number(options.rateStart) || SCALE_SETTLE_RATE_START);
    const rateEnd = Math.max(0.5, Number(options.rateEnd) || SCALE_SETTLE_RATE_END);
    const durationSec = durationMs / 1000;
    const delays = [];
    let tSec = 0;
    // Cap steps so a bad curve can't spawn hundreds of updates.
    const maxSteps = 40;
    while (tSec < durationSec - 0.001 && delays.length < maxSteps) {
        const u = tSec / durationSec;
        const eased = settleEaseInQuad(u);
        const rate = rateStart + (rateEnd - rateStart) * eased;
        const dt = 1 / Math.max(0.5, rate);
        const remaining = durationSec - tSec;
        const step = Math.min(dt, remaining);
        delays.push(step * 1000);
        tSec += step;
    }
    return delays;
}

function paintWeighSettleDisplays(gross, net, sensors) {
    const grossEl = document.getElementById('displayGross');
    const netEl = document.getElementById('displayNet');
    if (grossEl) grossEl.textContent = fmt(gross);
    if (netEl) netEl.textContent = fmt(net);
    const breakdownEl = document.getElementById('sensorBreakdown');
    if (!breakdownEl) return;
    if (sensors && sensors.length) {
        breakdownEl.textContent = sensors
            .map(s => s.position.charAt(0).toUpperCase() + ': ' + fmt(s.display_tons))
            .join(' · ');
    } else {
        breakdownEl.textContent = '';
    }
}

function paintCalSettleDisplays(gross, sensors) {
    const avgEl = document.getElementById('calAverageDisplay');
    if (avgEl) avgEl.textContent = fmt(gross);
    (sensors || []).forEach(s => {
        const pos = s.position;
        const el = document.getElementById('sensorDisplay-' + pos);
        if (el) el.textContent = fmt(s.display_tons);
    });
    const central = document.getElementById('calCentralReading');
    if (central && sensors && sensors.length) {
        const active = sensors.find(s => {
            const card = document.getElementById('sensorCard-' + s.position);
            return card && card.classList.contains('car-at-position');
        }) || sensors[0];
        if (active) central.textContent = fmt(active.display_tons);
    }
}

/**
 * Start near the target weight, then discrete up/down steps, then lock.
 * Returns false if aborted (another settle / car change).
 */
function runScaleSettle(options) {
    options = options || {};
    abortScaleSettle();
    const gen = scaleSettleGen;
    const durationMs = Number(options.durationMs) > 0 ? Number(options.durationMs) : SCALE_SETTLE_MS;
    const rateStart = Number(options.rateStart) > 0 ? Number(options.rateStart) : SCALE_SETTLE_RATE_START;
    const rateEnd = Number(options.rateEnd) > 0 ? Number(options.rateEnd) : SCALE_SETTLE_RATE_END;
    const stepDelays = buildSettleStepDelays({ durationMs, rateStart, rateEnd });
    const bounceCount = stepDelays.length;
    const targetGross = Number(options.targetGross);
    const targetNet = Number(options.targetNet);
    const safeGross = Number.isFinite(targetGross) ? targetGross : 0;
    const safeNet = Number.isFinite(targetNet) ? targetNet : 0;
    const sensorTargets = Array.isArray(options.sensorTargets) ? options.sensorTargets : null;
    const amp0 = Number.isFinite(Number(options.amplitudeTons))
        ? Math.max(0.05, Number(options.amplitudeTons))
        : SCALE_SETTLE_AMP_TONS;
    const paint = typeof options.paint === 'function' ? options.paint : paintWeighSettleDisplays;
    const settleSelector = options.settleSelector || '#weighPanel .scale-top-row .scale-display';
    // Alternate above/below the target; exact target after the bounce series.
    const signs = [];
    let sign = Math.random() < 0.5 ? -1 : 1;
    for (let i = 0; i < bounceCount; i++) {
        signs.push(sign);
        sign *= -1;
    }

    if (options.showNet !== false && options.mode !== 'calibrate') {
        setNetReadingVisible(true);
    }
    document.querySelectorAll(settleSelector).forEach(el => {
        el.classList.add('is-settling');
    });

    return new Promise(resolve => {
        function finish(ok) {
            scaleSettleTimer = null;
            document.querySelectorAll(settleSelector).forEach(el => {
                el.classList.remove('is-settling');
            });
            resolve(ok);
        }

        function showStep(index) {
            if (gen !== scaleSettleGen) {
                finish(false);
                return;
            }
            if (index >= bounceCount) {
                paint(safeGross, safeNet, sensorTargets);
                finish(gen === scaleSettleGen);
                return;
            }
            const t = bounceCount <= 1 ? 1 : (index / (bounceCount - 1));
            // Amplitude eases out with the same curve so wobble dies as ticks slow.
            const damp = 1 - settleEaseInQuad(t);
            const offset = signs[index] * amp0 * Math.max(0.08, damp);
            const gross = Math.max(0, safeGross + offset);
            const net = Math.max(0, safeNet + offset * 0.9);
            let sensors = null;
            if (sensorTargets && sensorTargets.length) {
                sensors = sensorTargets.map((s, idx) => {
                    const target = Number(s.display_tons);
                    const safe = Number.isFinite(target) ? target : 0;
                    const sOff = signs[index] * amp0 * damp * (0.75 + (idx % 3) * 0.08);
                    return {
                        position: s.position,
                        display_tons: Math.max(0, safe + sOff),
                    };
                });
            }
            paint(gross, net, sensors);
            const delay = stepDelays[index] != null ? stepDelays[index] : 100;
            scaleSettleTimer = setTimeout(() => showStep(index + 1), delay);
        }

        showStep(0);
    });
}

async function runCalibrationPlaceSettle(calibration) {
    if (!calibration) return true;
    const avg = calibration.average && calibration.average.display_tons != null
        ? Number(calibration.average.display_tons)
        : Number(calibration.expected_tons) || 0;
    const sensors = (calibration.sensors || [])
        .filter(s => s && s.has_reading && s.display_tons != null)
        .map(s => ({ position: s.position, display_tons: Number(s.display_tons) }));
    setCalAverageLed('weighing', 'WEIGHING...');
    return runScaleSettle({
        mode: 'calibrate',
        targetGross: avg,
        targetNet: avg,
        sensorTargets: sensors,
        amplitudeTons: 0.35,
        durationMs: SCALE_SETTLE_CAL_MS,
        rateStart: SCALE_SETTLE_RATE_START,
        rateEnd: SCALE_SETTLE_RATE_END,
        settleSelector: '#calibratePanel .scale-display',
        paint: (gross, _net, stepSensors) => paintCalSettleDisplays(gross, stepSensors),
    });
}

async function restoreWeighSession(carId) {
    const snap = weighSessionCache.get(String(carId));
    if (!snap || !snap.reading || !currentCar) return false;

    restoringWeighSession = true;
    try {
        currentReading = snap.reading;
        currentRouting = snap.routing;
        manualReassignMode = !!snap.manualReassignMode;

        applyReadingDisplays(snap.reading);

        const resultEl = document.getElementById('weighResult');
        if (resultEl && snap.weighResultHtml) {
            resultEl.innerHTML = snap.weighResultHtml;
        }

        if (snap.reading.test_car_weigh || snap.reading.unloaded_weigh) {
            setWeightLed('off');
        } else if (snap.ledState) {
            setWeightLed(snap.ledState, snap.ledLabel || '');
        } else {
            const inTol = snap.reading.in_tolerance;
            setWeightLed(inTol ? 'ok' : 'fail', inTol ? 'PASS' : weighFailureLedLabel(snap.reading));
        }

        if (currentCar.weigh_source === 'in_train') {
            showTrainNavButtons(snap.prevCar, snap.nextCar, { keepVisible: true });
        } else {
            hidePrevCarButton();
            hideNextCarButton();
        }

        if (snap.showReassignBtn && shouldOfferReassignButton(currentCar, snap.reading)) {
            showReassignButton();
        } else {
            hideReassignButton();
        }

        if (snap.orderVisible) {
            await loadOrders(snap.routing || 'outbound', { forceReassign: snap.manualReassignMode });
            const select = document.getElementById('orderSelect');
            if (select && snap.selectedWaybill) {
                const hasOpt = [...select.options].some(o => o.value === snap.selectedWaybill);
                if (hasOpt) select.value = snap.selectedWaybill;
            }
            const assignResult = document.getElementById('assignResult');
            if (assignResult && snap.assignResultHtml) {
                assignResult.innerHTML = snap.assignResultHtml;
            }
            updateAssignBtnState();
        }
    } finally {
        restoringWeighSession = false;
    }

    snapshotWeighSession(carId);
    return true;
}

function hideReassignButton() {
    manualReassignMode = false;
    const btn = document.getElementById('reassignBtn');
    if (!btn) return;
    btn.classList.add('d-none');
    btn.disabled = true;
    const note = document.getElementById('reassignNote');
    if (note) note.classList.add('d-none');
}

function showReassignButton() {
    const btn = document.getElementById('reassignBtn');
    if (!btn) return;
    btn.classList.remove('d-none');
    btn.disabled = false;
}

function hideWeighActionButtons() {
    hidePrevCarButton();
    hideNextCarButton();
    hideReassignButton();
}

function hidePrevCarButton() {
    pendingPrevCar = null;
    const btn = document.getElementById('prevCarBtn');
    if (!btn) return;
    btn.classList.add('d-none');
    btn.disabled = true;
}

function showPrevCarButton(prevCar, options) {
    const btn = document.getElementById('prevCarBtn');
    if (!btn) return;
    const keepVisible = !!(options && options.keepVisible);
    if (!prevCar || !prevCar.id) {
        pendingPrevCar = null;
        if (keepVisible) {
            btn.classList.remove('d-none');
            btn.disabled = true;
            return;
        }
        hidePrevCarButton();
        return;
    }
    pendingPrevCar = prevCar;
    btn.classList.remove('d-none');
    btn.disabled = false;
}

function hideNextCarButton() {
    pendingNextCar = null;
    const btn = document.getElementById('nextCarBtn');
    if (!btn) return;
    btn.classList.add('d-none');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-skip-forward"></i>';
}

function showNextCarButton(nextCar, options) {
    const btn = document.getElementById('nextCarBtn');
    if (!btn) return;
    const keepVisible = !!(options && options.keepVisible);
    if (!nextCar || !nextCar.id) {
        pendingNextCar = null;
        if (keepVisible) {
            btn.classList.remove('d-none');
            btn.disabled = true;
            return;
        }
        hideNextCarButton();
        return;
    }
    pendingNextCar = nextCar;
    btn.classList.remove('d-none');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-skip-forward"></i>';
}

function showTrainNavButtons(prevCar, nextCar, options) {
    const keepVisible = !!(options && options.keepVisible);
    showPrevCarButton(prevCar, { keepVisible });
    showNextCarButton(nextCar, { keepVisible });
}

function applyTrainNavFromResponse(data) {
    if (!data || !data.car || data.car.weigh_source !== 'in_train') {
        hidePrevCarButton();
        hideNextCarButton();
        return;
    }
    showTrainNavButtons(data.prev_car, data.next_car, { keepVisible: true });
}

function getTrainFilterValue() {
    const select = document.getElementById('trainFilter');
    return select ? select.value : '';
}

function populateTrainFilter(trains, selectedValue) {
    const select = document.getElementById('trainFilter');
    if (!select) return;

    const current = selectedValue !== undefined ? selectedValue : select.value;
    select.innerHTML = '';
    [
        { value: '', label: 'All cars' },
        { value: 'scale', label: 'At scale only' },
    ].forEach(option => {
        const opt = document.createElement('option');
        opt.value = option.value;
        opt.textContent = option.label;
        select.appendChild(opt);
    });
    (trains || []).forEach(train => {
        const opt = document.createElement('option');
        opt.value = String(train.id);
        opt.textContent = 'Train ' + train.name;
        select.appendChild(opt);
    });
    if ([...select.options].some(opt => opt.value === current)) {
        select.value = current;
    }
}

function carListPositionLabel(car) {
    if (car.weigh_source === 'in_train' && car.position) {
        return `#${car.position}`;
    }
    if (car.weigh_source === 'at_scale' && car.position) {
        return `#${car.position}`;
    }
    return '';
}

function statusBadgeClass(status) {
    const s = (status || '').toLowerCase();
    if (s === 'loaded') return 'bg-success text-white';
    if (s === 'loading') return 'bg-info text-white';
    if (s === 'unloading') return 'bg-primary text-white';
    if (s === 'empty') return 'bg-warning text-dark';
    if (s === 'ordered') return 'bg-secondary text-white';
    return 'bg-light text-dark';
}

function displayStatus(status, displayStatusOverride) {
    if (displayStatusOverride) return displayStatusOverride;
    return status || '?';
}

function carNeedsAssignment(car) {
    if (!car || car.tare_only === true) return false;
    if (car.needs_assignment === true) return true;
    if (car.needs_assignment === false) return false;
    const s = (car.status || '').toLowerCase();
    if (s === 'unloading') return true;
    return !car.has_active_order;
}

function carHasFinalUnloadAssignment(car) {
    if (!car || !car.has_active_order || !car.active_unloading_location) return false;
    return car.active_unloading_location.toUpperCase() !== (TRACK_SCALE_UI.scaleLocation || '').toUpperCase();
}

/**
 * Already on a reload order going back to be reloaded (config reload_unloading_location_code,
 * or a shipment in config shipments.reload): the assign panel stays closed unless Reassign.
 */
function carAssignedToReload(car) {
    if (!car || !car.has_active_order || carNeedsAssignment(car)) return false;
    const unload = String(car.active_unloading_location || '').toUpperCase();
    const shipment = String(car.active_shipment_code || '').toUpperCase();
    const reloadUnload = String(CONFIG.reload_unloading_location_code || '').toUpperCase();
    const reloadShipments = ((CONFIG.shipments && CONFIG.shipments.reload) || []).map((code) => String(code).toUpperCase());
    if (reloadUnload !== '' && unload.indexOf(reloadUnload) >= 0) return true;
    if (shipment !== '' && reloadShipments.indexOf(shipment) >= 0) return true;
    return false;
}

function shouldOfferReassignButton(car, reading) {
    if (!car || !reading || reading.unloaded_weigh || reading.test_car_weigh) return false;
    if (car.allows_scale_reassign !== true) return false;
    if (!car.has_active_order || carNeedsAssignment(car)) return false;
    // Fail weigh but already routed back for reload — offer manual reassign only.
    if (!reading.in_tolerance) {
        return carAssignedToReload(car);
    }
    if (shouldShowAssignAfterWeigh(car, reading)) return false;
    return true;
}

function shouldShowAssignAfterWeigh(car, reading) {
    if (!car || !reading || reading.unloaded_weigh || reading.test_car_weigh) return false;
    if (!reading.in_tolerance) {
        // Keep assign panel closed when the car is already going back for reload.
        if (carAssignedToReload(car)) return false;
        return carNeedsAssignment(car) || car.allows_scale_reassign === true;
    }
    if (carHasFinalUnloadAssignment(car)) return false;
    return carNeedsAssignment(car);
}

function hideOrderSection() {
    manualReassignMode = false;
    currentRouting = null;
    currentOpenOrders = [];
    document.getElementById('orderSection').classList.add('d-none');
    document.getElementById('assignResult').textContent = '';

    const selectWrap = document.getElementById('orderSelectWrap');
    if (selectWrap) selectWrap.classList.add('d-none');
    const select = document.getElementById('orderSelect');
    if (select) select.innerHTML = '';

    const genWrap = document.getElementById('generateButtons');
    if (genWrap) genWrap.innerHTML = '';

    const assignBtn = document.getElementById('assignBtn');
    if (assignBtn) {
        assignBtn.disabled = true;
        assignBtn.classList.add('d-none');
        assignBtn.classList.remove('btn-danger');
        assignBtn.classList.add('btn-success');
        const label = document.getElementById('assignBtnLabel');
        if (label) label.textContent = 'Assign to Order';
        const icon = assignBtn.querySelector('i');
        if (icon) icon.className = 'bi bi-check2-circle';
    }

    const reassignNote = document.getElementById('reassignNote');
    if (reassignNote) {
        reassignNote.classList.add('d-none');
    }
    const inTrainNote = document.getElementById('inTrainAssignNote');
    if (inTrainNote) {
        inTrainNote.classList.add('d-none');
        inTrainNote.textContent = '';
    }
}

function getSelectedOpenOrder() {
    const select = document.getElementById('orderSelect');
    if (!select || !select.value) {
        return null;
    }
    return currentOpenOrders.find(order => order.waybill_number === select.value) || null;
}

function updateInTrainAssignNote() {
    const inTrainNote = document.getElementById('inTrainAssignNote');
    const select = document.getElementById('orderSelect');
    if (!inTrainNote || !currentCar || !select) {
        return;
    }

    const inTrainCar = currentCar.requires_train_reassign_confirm === true
        || currentCar.weigh_source === 'in_train';
    const order = getSelectedOpenOrder();
    if (!inTrainCar || !order) {
        inTrainNote.classList.add('d-none');
        inTrainNote.textContent = '';
        return;
    }

    const waybill = order.waybill_number;

    let leadIn;
    if (currentCar.requires_train_reassign_confirm) {
        leadIn = 'This car is loaded in train '
            + (currentCar.train_job ? `<strong>${currentCar.train_job}</strong> ` : '')
            + 'on inbound order <strong>' + (currentCar.active_waybill || '—') + '</strong> to the scale.';
    } else {
        leadIn = 'This car is in train '
            + (currentCar.train_job ? `<strong>${currentCar.train_job}</strong> ` : '')
            + 'at the scale.';
    }

    inTrainNote.classList.remove('d-none');
    inTrainNote.innerHTML =
        '<i class="bi bi-info-circle"></i> '
        + leadIn
        + ' Reassign to order <strong>' + waybill + '</strong>.';
}

function updateAssignBtnState() {
    const select = document.getElementById('orderSelect');
    const assignBtn = document.getElementById('assignBtn');
    if (!select || !assignBtn) return;
    assignBtn.disabled = !select.value;
    updateInTrainAssignNote();
}

function getNextCarIdInList(currentCarId) {
    const items = document.querySelectorAll('.car-list-item');
    let foundCurrent = false;
    for (const item of items) {
        if (!foundCurrent) {
            if (item.dataset.carId === String(currentCarId)) {
                foundCurrent = true;
            }
            continue;
        }
        return item.dataset.carId;
    }
    return null;
}

function formatInTrainWorkflowNote(data) {
    if (!data || !Array.isArray(data.in_train_workflow) || !data.in_train_workflow.length) {
        return '';
    }
    const labels = {
        set_out: 'Set out at scale',
        unloaded: 'Unloaded prior order',
        assigned: 'Assigned new order',
        returned_to_train: 'Returned to ' + (data.train_job || 'train'),
    };
    return data.in_train_workflow.map(step => labels[step] || step).join(' → ');
}

function setNetReadingVisible(visible) {
    const block = document.getElementById('netReadingBlock');
    if (block) block.classList.toggle('d-none', !visible);
}

function setWeightLed(state, label) {
    const led = document.getElementById('weightLed');
    const panel = document.getElementById('netDisplayPanel');
    const ledLabel = document.getElementById('weightLedLabel');
    if (!panel || !ledLabel) return;
    state = state || 'off';
    if (led) led.dataset.state = state;
    const labels = {
        ok: 'PASS',
        fail: 'BALANCE - FAIL',
        off: '',
        oos: 'OUT OF SERVICE',
        weighing: 'WEIGHING...',
    };
    const text = label != null && label !== '' ? label : (labels[state] || '');
    ledLabel.textContent = text;
    ledLabel.classList.toggle('d-none', !text);
    ledLabel.classList.toggle('oos-label', state === 'oos');
    ledLabel.classList.toggle('fail-label', state === 'fail');
    ledLabel.classList.toggle('ready-label', state === 'ok');
    ledLabel.classList.toggle('weighing-label', state === 'weighing');
    panel.classList.toggle('out-of-range', state === 'fail');
    panel.classList.toggle('out-of-service', state === 'oos');
    panel.classList.toggle('is-weighing', state === 'weighing');
}

function weighFailureLedLabel(reading) {
    const reason = String((reading && reading.failure_reason) || '').toLowerCase();
    if (reason === 'overloaded') return 'LIMIT - FAIL';
    if (reason === 'imbalanced' || reason === 'underweight') return 'BALANCE - FAIL';
    return 'BALANCE - FAIL';
}

let lastCalibrationMetaText = 'Last calibration unknown';

function outOfServiceBannerText() {
    const detail = (lastCalibrationMetaText || 'Last calibration unknown').trim();
    if (/^OUT OF SERVICE\b/i.test(detail)) {
        return detail;
    }
    return 'OUT OF SERVICE - ' + detail;
}

function applyScaleServiceState(scale) {
    scaleInService = !(scale && scale.out_of_service);
    const weighBtn = document.getElementById('weighBtn');
    const resultEl = document.getElementById('weighResult');

    if (!scaleInService) {
        if (weighBtn) weighBtn.disabled = true;
        setWeightLed('oos', 'OUT OF SERVICE');
        setCalAverageLed('oos', 'OUT OF SERVICE');
        setNetReadingVisible(false);
        // Same slot as before (next to Weigh Car) — short last-cal detail instead of
        // "initial calibration must be completed before weighing cars".
        if (resultEl) {
            resultEl.innerHTML = `<span class="text-danger">${outOfServiceBannerText()}</span>`;
        }
        syncCalibrateFooterMeta(true);
        return;
    }

    panelCleanupOutOfService();
    setNetReadingVisible(true);
    syncCalibrateFooterMeta(false);
    if (weighBtn && currentCar) {
        weighBtn.disabled = false;
    }
}

function syncCalibrateFooterMeta(forceOutOfService) {
    const calEl = document.getElementById('calCalibrationMeta');
    if (!calEl) return;
    const metaWrap = calEl.closest('.cal-actions-meta');
    const oos = forceOutOfService || !scaleInService;
    const detail = (lastCalibrationMetaText || 'Last calibration unknown').trim();
    const known = detail !== '' && !/unknown/i.test(detail);
    // In service after calibrate: show known date/time (not OOS). When OOS, prefix
    // OUT OF SERVICE and keep the last-cal detail (date when known).
    calEl.textContent = oos ? outOfServiceBannerText() : detail;
    calEl.className = 'small ' + (oos ? 'text-danger' : (known ? 'text-success' : 'text-danger'));
    calEl.hidden = false;
    if (metaWrap) metaWrap.hidden = false;
}

function panelCleanupOutOfService() {
    const panel = document.getElementById('netDisplayPanel');
    const ledLabel = document.getElementById('weightLedLabel');
    if (panel) panel.classList.remove('out-of-service');
    if (ledLabel) ledLabel.classList.remove('oos-label');
    const calPanel = document.getElementById('calAverageDisplayPanel');
    const calLabel = document.getElementById('calAverageLedLabel');
    if (calPanel) calPanel.classList.remove('out-of-service');
    if (calLabel) calLabel.classList.remove('oos-label');
}

function setCalAverageLed(state, labelText) {
    const panel = document.getElementById('calAverageDisplayPanel');
    const led = document.getElementById('calAverageLed');
    const label = document.getElementById('calAverageLedLabel');
    if (!panel || !label) return;
    state = state || 'off';
    if (led) led.dataset.state = state;
    const defaults = {
        ok: 'READY',
        oos: 'OUT OF SERVICE',
        weighing: 'WEIGHING...',
    };
    const text = labelText != null && labelText !== '' ? labelText : (defaults[state] || '');
    label.textContent = text;
    label.classList.toggle('d-none', !text);
    label.classList.toggle('oos-label', state === 'oos');
    label.classList.toggle('ready-label', state === 'ok');
    label.classList.toggle('fail-label', state === 'fail');
    label.classList.toggle('weighing-label', state === 'weighing');
    panel.classList.toggle('out-of-service', state === 'oos');
    panel.classList.toggle('is-ready', state === 'ok');
    panel.classList.toggle('out-of-range', state === 'fail');
    panel.classList.toggle('is-weighing', state === 'weighing');
}

function assignedOrderMessage(car) {
    if (!car || !car.has_active_order || carNeedsAssignment(car)) return '';
    let msg = `<span class="text-success"><i class="bi bi-check-circle"></i> Assigned to <strong>${car.active_waybill}</strong>`;
    if (car.active_shipment_code) {
        msg += ` · ${car.active_shipment_code}`;
    }
    if (car.active_unloading_location) {
        msg += ` → ${car.active_unloading_location}`;
    }
    return msg + '</span>';
}

async function loadCarsAtScale() {
    const listEl = document.getElementById('carsList');
    const emptyEl = document.getElementById('carsListEmpty');
    const errorEl = document.getElementById('carsListError');
    const filterValue = getTrainFilterValue();

    errorEl.classList.add('d-none');
    const data = await apiGet('cars_at_scale', filterValue ? { job_id: filterValue } : {});
    if (!data.success) {
        errorEl.textContent = data.error || 'Could not load cars at scale';
        errorEl.classList.remove('d-none');
        listEl.innerHTML = '';
        emptyEl.classList.add('d-none');
        return;
    }

    populateTrainFilter(data.trains, filterValue);

    if (data.scale_status) {
        applyScaleServiceState(data.scale_status);
    }

    listEl.innerHTML = '';
    if (!data.cars.length) {
        emptyEl.classList.remove('d-none');
        document.getElementById('carPanel').classList.add('d-none');
        document.getElementById('weighBtn').disabled = true;
        hideWeighActionButtons();
        selectedCarId = null;
        currentCar = null;
        return;
    }

    emptyEl.classList.add('d-none');
    data.cars.forEach(car => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action car-list-item'
            + (String(car.id) === String(selectedCarId) ? ' active' : '');
        item.dataset.carId = car.id;
        const positionLabel = carListPositionLabel(car);
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    ${positionLabel ? `<span class="car-list-position">${positionLabel}</span>` : ''}
                    <div>
                        <span class="car-list-marks">${car.reporting_marks}</span>
                        <span class="text-muted small ms-2">${car.car_code || ''}</span>
                        ${car.tare_only
                            ? '<span class="badge bg-dark ms-1">Scale car</span>'
                            : ''}
                        ${car.weigh_source === 'in_train' && car.train_job
                            ? `<span class="badge bg-info text-dark ms-1">Train ${car.train_job}</span>`
                            : ''}
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">${car.tare_only
                        ? fmt(car.tare_tons) + ' t tare'
                        : fmt(car.load_limit_tons) + ' t LD LMT'}</span>
                    <span class="status-badge ${statusBadgeClass(car.status)}">${displayStatus(car.status, car.display_status)}</span>
                    ${car.has_active_order
                        ? `<span class="badge bg-secondary ms-1">${car.active_waybill}</span>`
                        : ''}
                </div>
            </div>`;
        item.addEventListener('click', () => selectCar(car.id));
        listEl.appendChild(item);
    });

    if (selectedCarId && !data.cars.some(c => String(c.id) === String(selectedCarId))) {
        selectedCarId = null;
        currentCar = null;
        document.getElementById('carPanel').classList.add('d-none');
        document.getElementById('weighBtn').disabled = true;
        hideWeighActionButtons();
    }
}

async function selectCar(carId) {
    if (currentCar && currentCar.id && currentReading) {
        snapshotWeighSession(currentCar.id);
    }
    abortScaleSettle();
    selectedCarId = carId;
    hideWeighActionButtons();
    document.querySelectorAll('.car-list-item').forEach(el => {
        el.classList.toggle('active', el.dataset.carId === String(carId));
    });

    const data = await apiGet('get_car', { car_id: carId });
    if (!data.success) {
        document.getElementById('carsListError').textContent = data.error || 'Could not load car';
        document.getElementById('carsListError').classList.remove('d-none');
        return;
    }
    if (data.scale_status) {
        applyScaleServiceState(data.scale_status);
    }
    renderCar(data);
    if (scaleInService) {
        await restoreWeighSession(carId);
    }
    // Train nav stays available whenever a train car is highlighted (not only after weigh).
    applyTrainNavFromResponse(data);
}

function fmtTimestamp(ts) {
    if (ts === null || ts === undefined || ts === '') return '';
    const n = Number(ts);
    if (Number.isFinite(n)) {
        return new Date(n * 1000).toLocaleString();
    }
    const parsed = Date.parse(String(ts));
    if (!Number.isNaN(parsed)) {
        return new Date(parsed).toLocaleString();
    }
    return String(ts);
}

function calibrationMetaText(cal) {
    const info = cal && cal.last_calibration ? cal.last_calibration : null;
    const calibrated = !!(cal && (cal.calibrated_this_session || cal.calibration_locked));
    const savedAt = (info && info.saved_at) || (cal && cal.calibration_saved_at) || null;
    const sessionNumber = (info && info.session_number) ? info.session_number : null;
    const stamped = fmtTimestamp(savedAt);

    if (stamped) {
        const prefix = calibrated ? 'Calibrated this session' : 'Last calibrated';
        const sessionPart = sessionNumber ? 'Session ' + sessionNumber + ' — ' : '';
        return prefix + ': ' + sessionPart + stamped;
    }

    if (info && info.calibration_unknown) {
        return 'Last calibration unknown';
    }

    return calibrated
        ? 'Calibrated this session'
        : 'Last calibration unknown';
}

function updateCalibrationMeta(cal) {
    lastCalibrationMetaText = calibrationMetaText(cal);
    if (cal && cal.scale_status) {
        applyScaleServiceState(cal.scale_status);
    } else {
        syncCalibrateFooterMeta(false);
    }
}

function fmt(value) {
    const num = Number(value);
    if (Number.isNaN(num)) return '—';
    return num.toFixed(PRECISION);
}

function tonsLabel(value) {
    return fmt(value) + ' t';
}

function showError(message) {
    const el = document.getElementById('carsListError');
    el.textContent = message;
    el.classList.remove('d-none');
}

function hideError() {
    document.getElementById('carsListError').classList.add('d-none');
}

function setMode(mode, options) {
    options = options || {};
    mode = (mode === 'calibrate') ? 'calibrate' : 'weigh';
    const weighRadio = document.getElementById('modeWeigh');
    const calRadio = document.getElementById('modeCalibrate');
    if (weighRadio) weighRadio.checked = mode === 'weigh';
    if (calRadio) calRadio.checked = mode === 'calibrate';
    document.getElementById('weighPanel').classList.toggle('active', mode === 'weigh');
    document.getElementById('calibratePanel').classList.toggle('active', mode === 'calibrate');
    document.body.classList.toggle('mode-calibrate', mode === 'calibrate');
    if (!options.skipSave) {
        try {
            localStorage.setItem(SCALE_MODE_KEY, mode);
        } catch (e) {
            /* ignore quota / private mode */
        }
    }
    if (mode === 'calibrate') {
        refreshCalibrationState();
    } else {
        refreshCalibrationMeta();
    }
}

function restoreScaleMode() {
    let mode = 'weigh';
    try {
        const saved = localStorage.getItem(SCALE_MODE_KEY);
        if (saved === 'calibrate' || saved === 'weigh') {
            mode = saved;
        }
    } catch (e) {
        mode = 'weigh';
    }
    setMode(mode, { skipSave: true });
}

async function refreshCalibrationMeta() {
    const data = await apiGet('calibration_state');
    if (data.success) {
        updateCalibrationMeta(data.calibration);
    }
}

document.getElementById('modeWeigh').addEventListener('change', () => setMode('weigh'));
document.getElementById('modeCalibrate').addEventListener('change', () => setMode('calibrate'));
restoreScaleMode();

async function setSelectedTestCar(marks) {
    const sel = document.getElementById('testCarSelect');
    const next = String(marks || '').toUpperCase();
    if (!next || next === selectedTestCarMarks) return;
    const previous = selectedTestCarMarks;
    if (sel) sel.disabled = true;
    try {
        const data = await apiPost('set_test_car', { reporting_marks: next });
        if (!data.success) {
            throw new Error(data.error || 'Could not set test car');
        }
        selectedTestCarMarks = String(data.reporting_marks || next).toUpperCase();
        if (CONFIG.calibration) {
            CONFIG.calibration.test_car_reporting_marks = selectedTestCarMarks;
        }
        if (data.calibration) {
            renderCalibration(data.calibration);
        } else {
            await refreshCalibration();
        }
    } catch (err) {
        if (sel && previous) sel.value = previous;
        setCalActionError(err.message || 'Could not set test car');
    } finally {
        if (sel) sel.disabled = false;
    }
}

(function initTestCarSelect() {
    const sel = document.getElementById('testCarSelect');
    if (!sel) return;
    sel.addEventListener('change', () => setSelectedTestCar(sel.value));
})();

async function apiGet(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const res = await fetch('plugin.php?id=track_scale&page=ajax&' + qs.toString());
    return res.json();
}

async function apiPost(action, payload = {}) {
    const res = await fetch('plugin.php?id=track_scale&page=ajax', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...payload }),
    });
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error(text.trim().slice(0, 180) || ('Request failed (' + res.status + ')'));
    }
}

function renderCar(data) {
    currentCar = data.car;
    currentProfile = data.profile;
    currentReading = null;
    currentRouting = null;

    document.getElementById('carPanel').classList.remove('d-none');
    document.getElementById('carMarks').textContent = data.car.reporting_marks;
    document.getElementById('carMeta').textContent =
        [data.profile.car_type, data.profile.length_ft ? data.profile.length_ft + "'" : '', data.car.car_code]
            .filter(Boolean).join(' · ');

    document.getElementById('statTare').textContent = tonsLabel(data.profile.tare_tons);
    const tareOnly = data.profile.tare_only === true;
    document.getElementById('statLoadLimit').textContent = tareOnly ? '—' : tonsLabel(data.profile.load_limit_tons);
    document.getElementById('statCapy').textContent = tareOnly || data.profile.capy_tons == null
        ? '—' : tonsLabel(data.profile.capy_tons);
    document.getElementById('statTarget').textContent = tareOnly ? '—' : tonsLabel(data.profile.target_net_tons);
    document.getElementById('statStatus').textContent = displayStatus(data.car.status, data.car.display_status) || '—';

    const img = document.getElementById('carPhoto');
    const placeholder = document.getElementById('carPhotoPlaceholder');
    img.onload = () => {
        img.classList.remove('d-none');
        placeholder.classList.add('d-none');
    };
    img.onerror = () => {
        img.classList.add('d-none');
        placeholder.classList.remove('d-none');
        placeholder.textContent = 'No photo available';
    };
    img.src = data.car.image_url + '?' + Date.now();

    const locationLabel = data.car.weigh_source === 'in_train'
        ? ('In train · ' + (data.car.train_job || TRACK_SCALE_UI.routedTrainsLabel + ' job'))
        : (data.car.current_location || '—');
    document.getElementById('statLocation').textContent = locationLabel;
    document.getElementById('statLocation').className = 'stat-value text-success';
    document.getElementById('weighBtn').disabled = !scaleInService;
    const assignedMsg = assignedOrderMessage(data.car);
    const unloadingAssign = carNeedsAssignment(data.car)
        && (data.car.status || '').toLowerCase() === 'unloading';
    const inboundTrainAssign = data.car.requires_train_reassign_confirm === true;
    if (!scaleInService) {
        document.getElementById('weighResult').innerHTML =
            `<span class="text-danger">${outOfServiceBannerText()}</span>`;
    } else {
        document.getElementById('weighResult').innerHTML = tareOnly
            ? 'Scale test car — weigh to verify tare weight on the scale.'
            : (assignedMsg
                || (unloadingAssign
                    ? '<span class="text-muted">Unloading — weigh, then assign to a new coke order (prior order returns to unfilled on assign).</span>'
                    : (inboundTrainAssign
                        ? `<span class="text-muted">In train on <strong>${data.car.active_waybill || 'inbound order'}</strong> to the scale — weigh, then assign outbound or reload (confirm train workflow on assign).</span>`
                        : (data.car.weigh_source === 'in_train'
                            ? '<span class="text-muted">In train — weigh, then assign; set-out, unload, and return to '
                                + (data.car.train_job || 'the same train') + ' run automatically on assign.</span>'
                            : 'Ready to weigh.'))));
    }
    document.getElementById('displayGross').textContent = '0.00';
    document.getElementById('displayNet').textContent = '0.00';
    setNetReadingVisible(scaleInService);
    if (scaleInService) {
        setWeightLed('off');
    }
    hideWeighActionButtons();
    hideOrderSection();
    applyTrainNavFromResponse(data);
}

document.getElementById('trainFilter').addEventListener('change', () => loadCarsAtScale());
document.getElementById('refreshCarsBtn').addEventListener('click', () => loadCarsAtScale());
document.getElementById('prevCarBtn').addEventListener('click', () => {
    if (!pendingPrevCar || !pendingPrevCar.id) return;
    selectCar(pendingPrevCar.id);
});
document.getElementById('nextCarBtn').addEventListener('click', () => {
    if (!pendingNextCar || !pendingNextCar.id) return;
    selectCar(pendingNextCar.id);
});
document.getElementById('reassignBtn').addEventListener('click', () => {
    if (!currentCar || !currentReading || !shouldOfferReassignButton(currentCar, currentReading)) return;
    openManualReassign();
});

async function openManualReassign() {
    manualReassignMode = true;
    const routing = (currentReading && !currentReading.in_tolerance) ? 'reload' : 'outbound';
    await loadOrders(routing, { forceReassign: true });
    const section = document.getElementById('orderSection');
    if (section && !section.classList.contains('d-none')) {
        section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    if (currentCar) snapshotWeighSession(currentCar.id);
}

document.getElementById('weighBtn').addEventListener('click', async () => {
    const weighBtn = document.getElementById('weighBtn');
    if (!currentCar || !weighBtn || weighBtn.disabled) return;
    const weighedCarId = currentCar.id;
    weighBtn.disabled = true;
    const resultEl = document.getElementById('weighResult');
    if (resultEl) {
        resultEl.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> Weighing...</span>';
    }
    setWeightLed('weighing', 'WEIGHING...');
    hideWeighActionButtons();
    hideOrderSection();

    const data = await apiPost('weigh', { reporting_marks: currentCar.reporting_marks });
    if (!data.success) {
        abortScaleSettle();
        setWeightLed('off');
        if (resultEl) {
            resultEl.innerHTML =
                `<span class="text-danger">${data.error || 'Weigh failed'}</span>`;
        }
        weighBtn.disabled = !scaleInService;
        return;
    }

    const reading = data.reading;
    const settled = await runScaleSettle({
        targetGross: reading.gross_tons,
        targetNet: reading.net_tons,
        sensorTargets: reading.sensor_readings || null,
        showNet: true,
        amplitudeTons: 0.35,
        durationMs: SCALE_SETTLE_MS,
        rateStart: SCALE_SETTLE_RATE_START,
        rateEnd: SCALE_SETTLE_RATE_END,
    });
    if (!settled || !currentCar || currentCar.id !== weighedCarId) {
        weighBtn.disabled = !scaleInService;
        return;
    }

    currentReading = reading;
    currentRouting = reading.routing;
    applyReadingDisplays(reading);

    if (reading.test_car_weigh) {
        resultEl.innerHTML =
            '<span class="text-muted"><i class="bi bi-info-circle"></i> Scale test car — gross is tare weight only.</span>';
        setWeightLed('off');
        document.getElementById('orderSection').classList.add('d-none');
        hideWeighActionButtons();
        weighBtn.disabled = !scaleInService;
        snapshotWeighSession(currentCar.id);
        return;
    }
    if (reading.unloaded_weigh) {
        resultEl.innerHTML =
            '<span class="text-muted"><i class="bi bi-info-circle"></i> Empty car — gross is unloaded (tare) weight only.</span>';
        setWeightLed('off');
        document.getElementById('orderSection').classList.add('d-none');
        hideReassignButton();
        showTrainNavButtons(
            data.prev_car,
            data.next_car,
            { keepVisible: currentCar.weigh_source === 'in_train' }
        );
        weighBtn.disabled = !scaleInService;
        snapshotWeighSession(currentCar.id);
        return;
    }

    const inTol = reading.in_tolerance;
    setWeightLed(inTol ? 'ok' : 'fail', inTol ? 'PASS' : weighFailureLedLabel(reading));
    showTrainNavButtons(
        data.prev_car,
        data.next_car,
        { keepVisible: currentCar.weigh_source === 'in_train' }
    );
    if (!shouldShowAssignAfterWeigh(currentCar, reading)) {
        let baseMsg;
        if (!inTol) {
            if (String(reading.failure_reason || '').toLowerCase() === 'overloaded') {
                baseMsg =
                    `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Overloaded — net ${fmt(reading.net_tons)} t exceeds load limit ${fmt(reading.target_net_tons)} t.</div>`;
            } else {
                baseMsg =
                    `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Imbalanced — left/right differ by ${fmt(reading.delta_tons)} t.</div>`;
            }
            const assigned = assignedOrderMessage(currentCar);
            if (assigned) {
                baseMsg += `<div class="mt-1">${assigned}</div>`;
            }
        } else {
            baseMsg = assignedOrderMessage(currentCar)
                || '<span class="text-muted">Weigh complete — load balanced on assigned order.</span>';
        }
        const reassignHint = shouldOfferReassignButton(currentCar, reading)
            ? ' <span class="text-muted">Use <strong>Reassign Order</strong> to change destination.</span>'
            : '';
        resultEl.innerHTML = baseMsg + reassignHint;
        hideOrderSection();
        if (shouldOfferReassignButton(currentCar, reading)) {
            showReassignButton();
        } else {
            hideReassignButton();
        }
        weighBtn.disabled = !scaleInService;
        snapshotWeighSession(currentCar.id);
        return;
    }
    hideReassignButton();
    if (inTol) {
        resultEl.innerHTML =
            `<span class="routing-outbound"><i class="bi bi-check-circle"></i> Left/right sensors within ±${fmt(reading.tolerance_tons)} t — assign to outbound coke order.</span>`;
    } else if (String(reading.failure_reason || '').toLowerCase() === 'overloaded') {
        resultEl.innerHTML =
            `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Overloaded — net ${fmt(reading.net_tons)} t exceeds load limit ${fmt(reading.target_net_tons)} t.</div>`;
    } else {
        resultEl.innerHTML =
            `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Imbalanced — left/right differ by ${fmt(reading.delta_tons)} t.</div>`;
    }

    await loadOrders(currentRouting);
    weighBtn.disabled = !scaleInService;
    snapshotWeighSession(currentCar.id);
});

async function loadOrders(routing, options = {}) {
    const forceReassign = options.forceReassign === true || manualReassignMode;
    if (!currentCar || !currentReading) {
        hideOrderSection();
        return;
    }
    if (forceReassign) {
        if (!manualReassignMode && !shouldOfferReassignButton(currentCar, currentReading)) {
            hideOrderSection();
            return;
        }
    } else if (!shouldShowAssignAfterWeigh(currentCar, currentReading)) {
        hideOrderSection();
        return;
    }
    const data = await apiGet('open_orders', { car_id: currentCar.id, routing });
    if (!data.success) return;

    const section = document.getElementById('orderSection');
    section.classList.remove('d-none');

    const reassignNote = document.getElementById('reassignNote');
    const reassignPriorWaybill = document.getElementById('reassignPriorWaybill');
    if (reassignNote && reassignPriorWaybill) {
        if (manualReassignMode) {
            reassignPriorWaybill.textContent = currentCar.active_waybill || '—';
            reassignNote.classList.remove('d-none');
        } else {
            reassignNote.classList.add('d-none');
        }
    }

    const badge = document.getElementById('routingBadge');
    if (routing === 'reload') {
        badge.className = 'badge bg-danger';
        badge.textContent = 'Coke Reload';
    } else if (manualReassignMode) {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = 'Reroute Outbound';
    } else {
        badge.className = 'badge bg-success';
        badge.textContent = 'Outbound Coke';
    }
    currentRouting = routing;

    currentOpenOrders = data.orders || [];
    const hasOrders = currentOpenOrders.length > 0;
    const selectWrap = document.getElementById('orderSelectWrap');
    const select = document.getElementById('orderSelect');
    const assignBtn = document.getElementById('assignBtn');

    if (selectWrap) selectWrap.classList.toggle('d-none', !hasOrders);

    select.innerHTML = '';
    if (hasOrders) {
        currentOpenOrders.forEach(order => {
            const opt = document.createElement('option');
            opt.value = order.waybill_number;
            opt.textContent = `${order.waybill_number} · ${order.shipment_code} → ${order.unloading_location}`;
            if (order.special_instructions) {
                opt.textContent += ` (${order.special_instructions})`;
            }
            select.appendChild(opt);
        });
        select.value = currentOpenOrders[0].waybill_number;
    }

    const genWrap = document.getElementById('generateButtons');
    genWrap.innerHTML = '';
    const isReloadRouting = routing === 'reload';
    (data.shipment_codes || []).forEach(code => {
        const btn = document.createElement('button');
        btn.type = 'button';
        const isReload = isReloadRouting || String(code).toUpperCase().indexOf('RELOAD') >= 0;
        if (isReload) {
            // Always available so a new order can reopen the dropdown.
            btn.className = 'btn btn-outline-primary';
            btn.innerHTML = '<i class="bi bi-plus-circle"></i> Create work order';
        } else if (hasOrders) {
            // Outbound: hide generate once there is something to assign.
            return;
        } else {
            btn.className = 'btn btn-outline-primary btn-sm';
            btn.innerHTML = `<i class="bi bi-plus-circle"></i> Generate ${code}`;
        }
        btn.addEventListener('click', () => generateOrder(code, routing));
        genWrap.appendChild(btn);
    });

    if (assignBtn) {
        const label = document.getElementById('assignBtnLabel');
        if (isReloadRouting) {
            // Show beside Create only after an order exists to assign.
            assignBtn.classList.toggle('d-none', !hasOrders);
            assignBtn.classList.remove('btn-success');
            assignBtn.classList.add('btn-danger');
            if (label) label.textContent = 'Reassign Car';
            const icon = assignBtn.querySelector('i');
            if (icon) icon.className = 'bi bi-arrow-repeat';
        } else {
            assignBtn.classList.toggle('d-none', !hasOrders);
            assignBtn.classList.remove('btn-danger');
            assignBtn.classList.add('btn-success');
            if (label) label.textContent = 'Assign to Order';
            const icon = assignBtn.querySelector('i');
            if (icon) icon.className = 'bi bi-check2-circle';
        }
    }

    select.onchange = () => {
        updateAssignBtnState();
        if (currentCar) snapshotWeighSession(currentCar.id);
    };
    updateAssignBtnState();
    if (currentCar) snapshotWeighSession(currentCar.id);
}

async function generateOrder(shipmentCode, routing) {
    if (!currentCar) return;
    const data = await apiPost('generate_order', {
        shipment_code: shipmentCode,
        car_id: currentCar.id,
        routing,
    });
    if (!data.success) {
        alert(data.error || 'Generate failed');
        return;
    }
    await loadOrders(routing, { forceReassign: manualReassignMode });
    const select = document.getElementById('orderSelect');
    if (data.waybill_number) {
        select.value = data.waybill_number;
    }
    if ((data.orders_created || 1) > 1) {
        const resultEl = document.getElementById('assignResult');
        resultEl.innerHTML =
            `<span class="text-muted">Created ${data.orders_created} orders for ${data.shipment_code}. Select one to assign this car.</span>`;
    }
    updateAssignBtnState();
    snapshotWeighSession(currentCar.id);
}

document.getElementById('assignBtn').addEventListener('click', async () => {
    const waybill = document.getElementById('orderSelect').value;
    if (!waybill || !currentCar) return;

    const assignedCarId = currentCar.id;
    const nextCarId = pendingNextCar?.id || getNextCarIdInList(currentCar.id);
    const payload = {
        waybill_number: waybill,
        car_id: currentCar.id,
    };
    if (currentCar.requires_train_reassign_confirm) {
        payload.confirm_train_reassign = true;
    }

    const data = await apiPost('assign', payload);
    const resultEl = document.getElementById('assignResult');
    if (!data.success) {
        resultEl.innerHTML = `<span class="text-danger">${data.error || 'Assign failed'}</span>`;
        return;
    }
    clearWeighSession(assignedCarId);
    const priorOrderNote = (data.unfilled_prior_order || data.closed_prior_order)
        ? (data.preserved_load
            ? '<span class="text-muted">Prior order returned to unfilled · car stays loaded · </span>'
            : '<span class="text-muted">Prior order returned to unfilled · </span>')
        : (data.unloaded_first
            ? '<span class="text-muted">Prior order returned to unfilled · </span>'
            : '');
    const workflowNote = formatInTrainWorkflowNote(data);
    const workflowHtml = workflowNote
        ? `<div class="text-muted mt-1">${workflowNote}</div>`
        : '';
    resultEl.innerHTML =
        `<span class="text-success"><i class="bi bi-check-circle"></i> ${priorOrderNote}${data.message} (${data.car_reporting_marks})</span>`
        + workflowHtml;
    hideOrderSection();
    hideWeighActionButtons();
    currentReading = null;
    await loadCarsAtScale();
    if (nextCarId && document.querySelector(`.car-list-item[data-car-id="${nextCarId}"]`)) {
        await selectCar(nextCarId);
        return;
    }
    selectedCarId = null;
    currentCar = null;
    document.getElementById('carPanel').classList.add('d-none');
    document.getElementById('weighBtn').disabled = true;
    document.getElementById('weighResult').textContent = 'Select a car from the list, then weigh.';
    document.getElementById('displayNet').textContent = '0.00';
    setNetReadingVisible(scaleInService);
    setWeightLed(scaleInService ? 'off' : 'oos', scaleInService ? '' : 'OUT OF SERVICE');
});

function updateCalTrackCar(position) {
    const trackCar = document.getElementById('calTrackCar');
    if (!trackCar) return;
    SENSOR_POSITIONS.forEach(pos => {
        trackCar.classList.remove('position-' + pos);
    });
    const active = position && SENSOR_POSITIONS.includes(position) ? position : null;
    if (!active) {
        trackCar.classList.add('d-none');
        return;
    }
    trackCar.classList.remove('d-none');
    trackCar.classList.add('position-' + active);
}

function renderCalibration(cal) {
    if (!cal) return;
    lastCalibrationSnapshot = cal;

    updateCalibrationMeta(cal);

    const calibrationLocked = !!cal.calibration_locked;

    if (cal.test_car) {
        const tc = cal.test_car;
        if (tc.reporting_marks) {
            selectedTestCarMarks = String(tc.reporting_marks).toUpperCase();
            const sel = document.getElementById('testCarSelect');
            if (sel && sel.value !== selectedTestCarMarks) {
                const opt = Array.from(sel.options).find(o => o.value.toUpperCase() === selectedTestCarMarks);
                if (opt) sel.value = opt.value;
            }
        }
        document.getElementById('calTestCarMarks').textContent = tc.reporting_marks || '—';
        document.getElementById('calTestCarLbs').textContent = (tc.tare_lbs || 0).toLocaleString();
        document.getElementById('calTestCarTons').textContent = fmt(tc.tare_tons);
        const img = document.getElementById('calTestCarPhoto');
        const ph = document.getElementById('calTestCarPhotoPlaceholder');
        if (tc.image_url) {
            img.onload = () => { img.classList.remove('d-none'); ph.classList.add('d-none'); };
            img.onerror = () => { img.classList.add('d-none'); ph.classList.remove('d-none'); };
            if (!img.src || img.src.indexOf(tc.image_url) === -1) {
                img.src = tc.image_url + '?' + Date.now();
            }
        } else {
            img.classList.add('d-none');
            ph.classList.remove('d-none');
        }
    }

    const testCarAtScale = cal.test_car_at_scale !== false;
    const scaleLocation = cal.scale_location || TRACK_SCALE_UI.scaleLocation || '';

    if (testCarAtScale) {
        updateCalTrackCar(cal.scale_car_position || null);
    } else {
        updateCalTrackCar(null);
    }

    let activeSensor = null;
    (cal.sensors || []).forEach(sensor => {
        const pos = sensor.position;
        const card = document.getElementById('sensorCard-' + pos);
        const carHere = testCarAtScale && !!sensor.car_at_position;
        const sensorLocked = !!sensor.is_locked;
        card.classList.toggle('car-at-position', carHere);
        card.classList.toggle('adjustment-locked', !!sensor.adjustment_locked);
        // Green when saved, session-locked, or currently zeroed.
        card.classList.toggle('calibrated', calibrationLocked || sensorLocked || !!sensor.is_zero);

        const badge = document.getElementById('sensorCarHere-' + pos);
        if (badge) {
            badge.classList.toggle('d-none', !carHere);
        }

        const posBtn = card.querySelector('.cal-position-btn');
        posBtn.classList.remove('cal-weigh-phase', 'cal-lock-phase', 'cal-locked-phase');
        // After save: place only. Open cal: Place → Lock/Unlock (allowed even with error).
        let phase = 'place';
        if (!calibrationLocked) {
            if (sensorLocked) {
                phase = 'locked';
            } else if (carHere) {
                phase = 'lock';
            }
        }
        posBtn.classList.toggle('active', carHere && phase !== 'locked');
        posBtn.classList.toggle('cal-lock-phase', phase === 'lock');
        posBtn.classList.toggle('cal-locked-phase', phase === 'locked');
        posBtn.classList.toggle('btn-primary', phase === 'lock' || (phase === 'place' && carHere));
        posBtn.classList.toggle('btn-outline-primary', phase === 'place' && !carHere);
        posBtn.classList.toggle('btn-success', phase === 'locked');
        posBtn.dataset.phase = phase;
        posBtn.dataset.carHere = carHere ? '1' : '0';
        posBtn.disabled = !testCarAtScale || calPlaceBusy;
        if (phase === 'locked') {
            if (carHere) {
                posBtn.innerHTML = '<i class="bi bi-unlock"></i> Unlock Sensor';
                posBtn.setAttribute('aria-label', 'Unlock ' + pos + ' sensor');
            } else {
                posBtn.innerHTML = '<i class="bi bi-lock-fill"></i> Locked';
                posBtn.setAttribute('aria-label', 'Place car on locked ' + pos + ' sensor');
            }
        } else if (phase === 'lock') {
            posBtn.innerHTML = '<i class="bi bi-lock"></i> Lock Sensor';
            posBtn.setAttribute('aria-label', 'Lock ' + pos + ' sensor');
        } else {
            posBtn.innerHTML = '<i class="bi bi-truck"></i> Place Car Here';
            posBtn.setAttribute('aria-label', 'Place car on ' + pos + ' sensor');
        }

        const weighBtn = card.querySelector('.cal-weigh-btn');
        if (weighBtn) {
            weighBtn.disabled = !carHere || calibrationLocked || sensorLocked;
        }

        document.getElementById('sensorDisplay-' + pos).textContent =
            sensor.display_tons !== null && sensor.display_tons !== undefined
                ? fmt(sensor.display_tons)
                : '—';
        document.getElementById('sensorError-' + pos).textContent =
            sensor.has_reading ? fmt(sensor.error_tons) : '—';
        const adjInput = document.getElementById('sensorAdjustInput-' + pos);
        if (adjInput) {
            adjInput.value = fmt(sensor.adjustment_tons);
        }
        const adjLine = document.getElementById('sensorAdj-' + pos);
        if (adjLine) {
            adjLine.textContent =
                'adj ' + fmt(sensor.adjustment_tons) + ' (step ±' + fmt(sensor.adjust_step_tons || 0.1) + ' t)';
        }

        card.querySelectorAll('.cal-adj-btn').forEach(btn => {
            btn.disabled = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
        });
        const resetBtn = card.querySelector('.cal-adj-reset-btn');
        if (resetBtn) {
            resetBtn.disabled = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
        }

        if (carHere) {
            activeSensor = sensor;
        }
    });

    syncCalCentralControls(activeSensor, testCarAtScale, calibrationLocked);

    document.getElementById('calResetBtn').disabled = false;
    const saveBtn = document.getElementById('calSaveBtn');
    if (saveBtn) {
        saveBtn.disabled = calibrationLocked || !cal.all_calibrated;
    }

    if (cal.average) {
        document.getElementById('calAverageDisplay').textContent = fmt(cal.average.display_tons);
        document.getElementById('calAverageAdjustment').textContent =
            cal.average.adjustment_tons !== null && cal.average.adjustment_tons !== undefined
                ? fmt(cal.average.adjustment_tons)
                : '—';
        document.getElementById('calAverageMeta').textContent =
            `(${cal.average.sensor_count || 0} of 3 sensors in average)`;
    } else {
        document.getElementById('calAverageDisplay').textContent = '—';
        document.getElementById('calAverageAdjustment').textContent = '—';
        document.getElementById('calAverageMeta').textContent = '(zero and lock each sensor — average moves toward expected)';
    }

    syncCalReadyUi(cal, activeSensor);
}

function sensorMatchesExpected(sensor) {
    if (!sensor || !sensor.has_reading) return false;
    if (sensor.is_zero) return true;
    const err = Number(sensor.error_tons);
    return Number.isFinite(err) && Math.abs(err) < (Math.pow(10, -PRECISION) / 2);
}

function syncCalErrorStat(sensor) {
    const errorEl = document.getElementById('calCentralError');
    const errorBox = errorEl ? errorEl.closest('.cal-stat-error') : null;
    if (!errorEl) return;
    if (!errorBox) {
        errorEl.textContent = sensor && sensor.has_reading ? fmt(sensor.error_tons) : '—';
        return;
    }
    errorBox.classList.remove('is-zero', 'is-nonzero');
    if (!sensor || !sensor.has_reading
        || sensor.error_tons === null || sensor.error_tons === undefined) {
        errorEl.textContent = '—';
        return;
    }
    const err = Number(sensor.error_tons);
    errorEl.textContent = fmt(err);
    if (sensorMatchesExpected(sensor)) {
        errorBox.classList.add('is-zero');
    } else {
        errorBox.classList.add('is-nonzero');
    }
}

function syncCalReadyUi(cal, activeSensor) {
    const adjPanel = document.querySelector('#calibratePanel .cal-central-panel');
    const activeReady = sensorMatchesExpected(activeSensor);
    // Banner READY only when all 3 sensors align (save enabled). Keep the
    // average weight readout until then.
    const allReady = !!(cal && cal.all_calibrated);

    if (allReady) {
        setCalAverageLed('ok', 'READY');
    } else if (!scaleInService) {
        setCalAverageLed('oos', 'OUT OF SERVICE');
    } else {
        setCalAverageLed('off', '');
    }
    if (adjPanel) adjPanel.classList.toggle('is-ready', activeReady);
}

const ADJ_SLIDER_MIN = -2.5;
const ADJ_SLIDER_MAX = 2.5;

function clampAdjTons(valueTons) {
    const tons = Number(valueTons);
    const safe = Number.isFinite(tons) ? tons : 0;
    return Math.min(ADJ_SLIDER_MAX, Math.max(ADJ_SLIDER_MIN, safe));
}

function syncCalAdjDisplay(valueTons, options) {
    options = options || {};
    const safe = clampAdjTons(valueTons);
    const text = fmt(safe);
    const adjInput = document.getElementById('calCentralAdjInput');
    const adjValue = document.getElementById('calCentralAdjValue');
    const slider = document.getElementById('calCentralAdjSlider');
    const minEl = document.getElementById('calCentralAdjMin');
    const maxEl = document.getElementById('calCentralAdjMax');
    if (adjInput) adjInput.value = text;
    if (adjValue) adjValue.textContent = text;
    if (minEl) minEl.textContent = fmt(ADJ_SLIDER_MIN);
    if (maxEl) maxEl.textContent = fmt(ADJ_SLIDER_MAX);
    if (slider && !options.skipSlider) {
        slider.min = String(ADJ_SLIDER_MIN);
        slider.max = String(ADJ_SLIDER_MAX);
        const step = (CONFIG.calibration && CONFIG.calibration.fine_adjust_step_tons) || 0.01;
        slider.step = String(step);
        slider.value = String(safe);
    }
}

function syncCalCentralControls(sensor, testCarAtScale, calibrationLocked) {
    const sensorEl = document.getElementById('calCentralSensor');
    const readingEl = document.getElementById('calCentralReading');
    const expectedEl = document.getElementById('calCentralExpected');
    const adjInput = document.getElementById('calCentralAdjInput');
    const slider = document.getElementById('calCentralAdjSlider');
    const adjBtns = document.querySelectorAll('.cal-central-adj-btn');
    const resetAdjBtn = document.getElementById('calResetAdjBtn');
    if (!sensorEl || !adjInput) {
        return;
    }

    if (!sensor) {
        sensorEl.textContent = '—';
        syncCalErrorStat(null);
        if (readingEl) readingEl.textContent = '—';
        if (expectedEl) expectedEl.textContent = '—';
        syncCalAdjDisplay(0);
        adjBtns.forEach(btn => { btn.disabled = true; });
        if (slider) slider.disabled = true;
        if (resetAdjBtn) resetAdjBtn.disabled = true;
        return;
    }

    const locked = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
    sensorEl.textContent = sensor.position ? String(sensor.position).toUpperCase() : '—';
    syncCalErrorStat(sensor);
    if (readingEl) {
        // Live pad reading (includes position shift + noise); adjustment is separate.
        if (sensor.has_reading
            && sensor.display_tons !== null && sensor.display_tons !== undefined) {
            readingEl.textContent = fmt(Number(sensor.display_tons));
        } else {
            readingEl.textContent = '—';
        }
    }
    if (expectedEl) {
        expectedEl.textContent =
            sensor.expected_tons !== null && sensor.expected_tons !== undefined
                ? fmt(sensor.expected_tons)
                : '—';
    }
    if (!slider || document.activeElement !== slider) {
        syncCalAdjDisplay(sensor.adjustment_tons);
    } else {
        syncCalAdjDisplay(sensor.adjustment_tons, { skipSlider: true });
    }
    adjBtns.forEach(btn => { btn.disabled = locked; });
    if (slider) slider.disabled = locked;
    if (resetAdjBtn) resetAdjBtn.disabled = locked;
}

function getActiveCalSensorPosition() {
    const activeBtn = document.querySelector('.cal-position-btn.active');
    return activeBtn ? activeBtn.dataset.sensor : null;
}

function setCalActionError(message) {
    const el = document.getElementById('calCalibrationMeta');
    if (!el) return;
    el.innerHTML = `<span class="text-danger">${message}</span>`;
    el.hidden = false;
    const wrap = el.closest('.cal-actions-meta');
    if (wrap) wrap.hidden = false;
}

function setCalPlaceButtonsBusy(busy) {
    calPlaceBusy = !!busy;
    const atScale = lastCalibrationSnapshot
        ? (lastCalibrationSnapshot.test_car_at_scale !== false)
        : true;
    document.querySelectorAll('.cal-position-btn').forEach(btn => {
        btn.disabled = busy || !atScale;
    });
}

async function placeCalCar(position, options) {
    options = options || {};
    const keepLocked = options.keepLocked === true;
    const autoLock = options.autoLock === true;
    if (calPlaceBusy) return false;
    setCalPlaceButtonsBusy(true);
    try {
        const setData = await apiPost('calibrate_set_position', { position });
        if (!setData.success) {
            setCalActionError(setData.error || 'Could not place car');
            return false;
        }
        // Show placed state first, then settle meters at the new pad loads.
        renderCalibration(setData.calibration);
        const settled = await runCalibrationPlaceSettle(setData.calibration);
        if (!settled) return false;

        let calibration = setData.calibration;
        if (autoLock && !keepLocked) {
            const lockData = await apiPost('calibrate_read', { position });
            if (!lockData.success) {
                setCalActionError(lockData.error || 'Could not lock sensor');
                renderCalibration(setData.calibration);
                return false;
            }
            calibration = lockData.calibration;
        }
        renderCalibration(calibration);
        return true;
    } finally {
        calPlaceBusy = false;
        if (lastCalibrationSnapshot) {
            renderCalibration(lastCalibrationSnapshot);
        } else {
            setCalPlaceButtonsBusy(false);
        }
    }
}

async function lockCalSensor(position) {
    if (calPlaceBusy) return false;
    const lockData = await apiPost('calibrate_read', { position });
    if (!lockData.success) {
        setCalActionError(lockData.error || 'Could not lock sensor');
        return false;
    }
    renderCalibration(lockData.calibration);
    return true;
}

async function unlockCalSensor(position) {
    if (calPlaceBusy) return false;
    const unlockData = await apiPost('calibrate_unlock', { position });
    if (!unlockData.success) {
        setCalActionError(unlockData.error || 'Could not unlock sensor');
        return false;
    }
    renderCalibration(unlockData.calibration);
    return true;
}

/** @deprecated alias — Lock Sensor is calibrate_read */
async function weighCalPosition(position) {
    return lockCalSensor(position);
}

async function adjustSensor(sensor, direction, fineTune) {
    const payload = { sensor, direction };
    if (fineTune !== undefined && fineTune !== null) {
        payload.fine_tune = !!fineTune;
    }
    const data = await apiPost('calibrate_adjust', payload);
    if (!data.success) {
        setCalActionError(data.error || 'Adjust failed');
        return;
    }
    renderCalibration(data.calibration);
}

let calAdjSliderTimer = null;
let calAdjSliderInFlight = false;
let calAdjSliderPending = null;

async function setSensorAdjustmentTons(sensor, tons) {
    const payload = { sensor, adjustment_tons: clampAdjTons(tons) };
    const data = await apiPost('calibrate_adjust', payload);
    if (!data.success) {
        setCalActionError(data.error || 'Adjust failed');
        return false;
    }
    renderCalibration(data.calibration);
    return true;
}

function queueSensorAdjustmentFromSlider(tons) {
    const pos = getActiveCalSensorPosition();
    if (!pos) return;
    syncCalAdjDisplay(tons, { skipSlider: true });
    calAdjSliderPending = { sensor: pos, tons: Number(tons) };
    if (calAdjSliderTimer) clearTimeout(calAdjSliderTimer);
    calAdjSliderTimer = setTimeout(flushSensorAdjustmentFromSlider, 80);
}

async function flushSensorAdjustmentFromSlider() {
    calAdjSliderTimer = null;
    if (calAdjSliderInFlight || !calAdjSliderPending) return;
    const next = calAdjSliderPending;
    calAdjSliderPending = null;
    calAdjSliderInFlight = true;
    try {
        await setSensorAdjustmentTons(next.sensor, next.tons);
    } finally {
        calAdjSliderInFlight = false;
        if (calAdjSliderPending) {
            flushSensorAdjustmentFromSlider();
        }
    }
}

async function resetSensorAdjustment(sensor) {
    const data = await apiPost('calibrate_adjust_reset', { sensor });
    if (!data.success) {
        setCalActionError(data.error || 'Reset failed');
        return;
    }
    renderCalibration(data.calibration);
}

document.querySelectorAll('.cal-position-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled || calPlaceBusy) return;
        const position = btn.dataset.sensor;
        const phase = btn.dataset.phase;
        const carHere = btn.dataset.carHere === '1';
        if (phase === 'locked') {
            if (carHere) {
                unlockCalSensor(position);
            } else {
                // Relocate onto a locked pad — keep the lock after settle.
                placeCalCar(position, { keepLocked: true });
            }
        } else if (phase === 'lock') {
            // Allowed even when residual error is still present.
            lockCalSensor(position);
        } else {
            placeCalCar(position, { autoLock: false, keepLocked: false });
        }
    });
});

document.querySelectorAll('.cal-adj-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled) return;
        const fine = btn.dataset.fine === '1';
        adjustSensor(btn.dataset.sensor, btn.dataset.direction, fine);
    });
});

document.querySelectorAll('.cal-adj-reset-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled) return;
        resetSensorAdjustment(btn.dataset.sensor);
    });
});

(function wireCalCentralControls() {
    document.querySelectorAll('.cal-central-adj-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            const pos = getActiveCalSensorPosition();
            if (!pos) return;
            const step = Number(btn.dataset.step);
            if (Number.isFinite(step) && step > 0) {
                const adjInput = document.getElementById('calCentralAdjInput');
                const current = Number(adjInput ? adjInput.value : 0) || 0;
                const delta = btn.dataset.direction === 'down' ? -step : step;
                setSensorAdjustmentTons(pos, current + delta);
                return;
            }
            adjustSensor(pos, btn.dataset.direction, btn.dataset.fine === '1');
        });
    });
    const slider = document.getElementById('calCentralAdjSlider');
    if (slider) {
        slider.addEventListener('input', () => {
            if (slider.disabled) return;
            queueSensorAdjustmentFromSlider(slider.value);
        });
        slider.addEventListener('change', () => {
            if (slider.disabled) return;
            const pos = getActiveCalSensorPosition();
            if (!pos) return;
            if (calAdjSliderTimer) clearTimeout(calAdjSliderTimer);
            calAdjSliderPending = { sensor: pos, tons: clampAdjTons(slider.value) };
            flushSensorAdjustmentFromSlider();
        });
    }
    document.getElementById('calResetAdjBtn')?.addEventListener('click', () => {
        const btn = document.getElementById('calResetAdjBtn');
        if (!btn || btn.disabled) return;
        const pos = getActiveCalSensorPosition();
        if (!pos) return;
        resetSensorAdjustment(pos);
    });
})();

async function saveCalibration() {
    const saveBtn = document.getElementById('calSaveBtn');
    if (!saveBtn || saveBtn.disabled) return;
    saveBtn.disabled = true;
    const data = await apiPost('calibrate_save');
    if (!data.success) {
        setCalActionError(data.error || 'Could not save calibration');
        saveBtn.disabled = false;
        return;
    }
    renderCalibration(data.calibration);
    refreshCalibrationMeta();
}

document.getElementById('calSaveBtn').addEventListener('click', saveCalibration);

async function refreshCalibrationState() {
    const data = await apiGet('calibration_state');
    if (data.success) {
        renderCalibration(data.calibration);
    }
}

document.getElementById('calResetBtn').addEventListener('click', async () => {
    const resetBtn = document.getElementById('calResetBtn');
    if (resetBtn) resetBtn.disabled = true;
    const data = await apiPost('calibrate_reset');
    if (!data.success) {
        setCalActionError(data.error || 'Could not reset calibration');
        if (resetBtn) resetBtn.disabled = false;
        return;
    }
    if (data.calibration) {
        renderCalibration(data.calibration);
        refreshCalibrationMeta();
        return;
    }
    await refreshCalibrationState();
});

refreshCalibrationState();
loadCarsAtScale();
