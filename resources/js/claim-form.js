// Claim 表单的金额实时预览，只是显示用，保存时后端会全部重算。
// 金额用整数 cents，数量用千分位整数，算法跟 App\Services\ClaimCalculationService 保持一致。

const body = document.getElementById('items-body');
const template = document.getElementById('item-row');

// 把 "12.345" 这类字符串转成放大 10^scale 的整数，多出的小数位四舍五入；格式不对返回 null
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
    // 有单价就用 数量 x 单价，Amount 栏锁住；没单价才用手填的 Amount
    let gross = 0;
    if (price !== null) {
        gross = Math.floor((qty * price + 500) / 1000);
    } else {
        gross = toScaled(get('amount'), 2) ?? 0;
    }
    row.querySelector('[data-name="amount"]').disabled = price !== null;
    return { gross, discount, tax, total: gross - discount + tax };
}

// 增删行后重排 name，保持 items[0]、items[1]… 连续
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

    // 明细合计跟收据总额对不上就提示，不会自动改任何数字
    const receipt = toScaled(document.getElementById('receipt_total_amount').value, 2);
    const box = document.getElementById('diff-box');
    if (receipt !== null && receipt !== sum.total) {
        box.textContent = `⚠ Items total (${fmt(sum.total)}) differs from the receipt total (${fmt(receipt)}) by ${fmt(receipt - sum.total)}. Please check the items — nothing is changed automatically.`;
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

// 从 template 复制一行；data._ocr 为 true 时，有值的栏位加黄色 ocr-filled 标记
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

// disabled 的 input 不会被提交，submit 前先解锁，后端才收得到所有栏位
document.getElementById('claim-form').addEventListener('submit', () => {
    body.querySelectorAll('[data-name="amount"]').forEach((el) => (el.disabled = false));
});

// OCR 提示按钮：把 OCR 文字里原样出现的值填进对应栏位
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

// 自动填好的栏位只要用户改过就算已确认，去掉黄色标记
document.getElementById('claim-form').addEventListener('input', (e) => e.target.classList?.remove('ocr-filled'));
document.getElementById('claim-form').addEventListener('change', (e) => e.target.classList?.remove('ocr-filled'));

// 载入已有明细（页面通过 window.claimItems 传进来），一行都没有就给一行空的
(window.claimItems || []).forEach((item) => addRow(item));
if (!body.children.length) addRow();
