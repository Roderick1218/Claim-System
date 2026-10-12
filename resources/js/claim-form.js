// Live amount preview for the claim form. Display only — the backend recalculates everything on save.
// Uses integer cents (and thousandths for quantity), mirroring App\Services\ClaimCalculationService.

const body = document.getElementById('items-body');
const template = document.getElementById('item-row');

function toScaled(value, scale) {
    const v = String(value ?? '').trim();
    const m = v.match(/^(\d+)(?:\.(\d+))?$/);
    if (!m) return null;
    let frac = m[2] || '';
    let roundUp = false;
    if (frac.length > scale) {
        roundUp = Number(frac[scale]) >= 5;
        frac = frac.slice(0, scale);
    }
    return Number(m[1] + frac.padEnd(scale, '0')) + (roundUp ? 1 : 0);
}

const fmt = (cents) => (cents < 0 ? '-' : '') + (Math.abs(cents) / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function calcRow(row) {
    const get = (n) => row.querySelector(`[data-name="${n}"]`).value;
    const qty = toScaled(get('quantity'), 3) ?? 1000;
    const price = toScaled(get('unit_price'), 2);
    const discount = toScaled(get('discount_amount'), 2) ?? 0;
    const tax = toScaled(get('tax_amount'), 2) ?? 0;
    let gross = 0;
    if (price !== null) {
        gross = Math.floor((qty * price + 500) / 1000);
    } else {
        gross = toScaled(get('amount'), 2) ?? 0;
    }
    row.querySelector('[data-name="amount"]').disabled = price !== null;
    return { gross, discount, tax, total: gross - discount + tax };
}

function renumber() {
    [...body.querySelectorAll('.item-row')].forEach((row, i) => {
        row.querySelectorAll('[data-name]').forEach((el) => {
            el.name = `items[${i}][${el.dataset.name}]`;
        });
    });
}

function recalc() {
    const sum = { gross: 0, discount: 0, tax: 0, total: 0 };
    body.querySelectorAll('.item-row').forEach((row) => {
        const r = calcRow(row);
        const cell = row.querySelector('.line-total');
        cell.textContent = fmt(r.total);
        cell.classList.toggle('text-rose-600', r.total < 0);
        Object.keys(sum).forEach((k) => (sum[k] += r[k]));
    });
    document.getElementById('sum-subtotal').textContent = fmt(sum.gross);
    document.getElementById('sum-discount').textContent = fmt(sum.discount);
    document.getElementById('sum-tax').textContent = fmt(sum.tax);
    document.getElementById('sum-total').textContent = fmt(sum.total);
    const sticky = document.getElementById('sum-total-sticky');
    if (sticky) sticky.textContent = fmt(sum.total);

    const receipt = toScaled(document.getElementById('receipt_total_amount').value, 2);
    const box = document.getElementById('diff-box');
    if (receipt !== null && receipt !== sum.total) {
        box.textContent = `⚠ Items total (${fmt(sum.total)}) differs from the receipt total (${fmt(receipt)}) by ${fmt(receipt - sum.total)}. Please check the items — nothing is changed automatically.`;
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

function addRow(data = {}) {
    const row = template.content.firstElementChild.cloneNode(true);
    row.querySelectorAll('[data-name]').forEach((el) => {
        const v = data[el.dataset.name];
        if (v !== undefined && v !== null) el.value = v;
        if (data._ocr && v !== undefined && v !== null && v !== '') el.classList.add('ocr-filled');
    });
    body.appendChild(row);
    renumber();
    recalc();
    return row;
}

body.addEventListener('input', recalc);
body.addEventListener('click', (e) => {
    if (e.target.closest('.remove-item')) {
        e.target.closest('.item-row').remove();
        renumber();
        recalc();
    }
});
document.getElementById('add-item').addEventListener('click', () => addRow().querySelector('[data-name="item_name"]').focus());
document.getElementById('receipt_total_amount').addEventListener('input', recalc);

// Disabled inputs are not submitted; re-enable before submit so the server sees every field.
document.getElementById('claim-form').addEventListener('submit', () => {
    body.querySelectorAll('[data-name="amount"]').forEach((el) => (el.disabled = false));
});

// OCR hint buttons copy a value that literally appears in the OCR text into the form.
document.querySelectorAll('[data-fill]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.fill);
        if (!target) return;
        target.value = btn.dataset.value;
        target.dispatchEvent(new Event('input'));
        target.classList.add('ring-2', 'ring-brand-300');
        setTimeout(() => target.classList.remove('ring-2', 'ring-brand-300'), 1200);
    });
});

// Once the user edits an auto-filled field, it counts as checked: drop the yellow highlight.
document.getElementById('claim-form').addEventListener('input', (e) => e.target.classList?.remove('ocr-filled'));
document.getElementById('claim-form').addEventListener('change', (e) => e.target.classList?.remove('ocr-filled'));

(window.claimItems || []).forEach((item) => addRow(item));
if (!body.children.length) addRow();
