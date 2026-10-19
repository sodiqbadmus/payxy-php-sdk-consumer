// Frontend flow: plain JavaScript talking to the playground's JSON API.
// The browser never sees a key: the backend initializes and verifies with the SDK.
(function () {
  'use strict';

  function log(text) {
    var el = document.getElementById('js-log');
    if (el) { el.hidden = false; el.textContent += text + '\n'; }
  }

  async function startCheckout(event) {
    event.preventDefault();
    var form = event.currentTarget;
    form.querySelector('button').disabled = true;
    log('POST /api/checkout …');

    var res = await fetch('/api/checkout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount: form.amount.value, email: form.email.value })
    });
    var session = await res.json();
    if (!res.ok) { log('Error: ' + session.message); form.querySelector('button').disabled = false; return; }
    log('Session: ' + JSON.stringify(session, null, 2));

    // Official overlay needs an https page (Payxy's frame-ancestors policy); otherwise redirect.
    if (window.location.protocol === 'https:' && window.Payxy && window.Payxy.Payxy) {
      log('Opening the official Payxy overlay…');
      var done = function () { window.location.assign('/frontend/return'); };
      new window.Payxy.Payxy({ accessCode: session.accessCode, environment: session.environment, onSuccess: done, onError: done }).init();
    } else {
      log('Plain http: redirecting to ' + session.paymentUrl);
      setTimeout(function () { window.location.assign(session.paymentUrl); }, 800);
    }
  }

  async function showResult(box) {
    var res = await fetch('/api/payments/current');
    var data = await res.json();
    if (!res.ok) { box.innerHTML = '<p class="alert"></p>'; box.firstChild.textContent = data.error || data.message; return; }

    var tone = data.outcome === 'successful' ? 'ok' : data.outcome === 'pending' ? 'warn' : 'bad';
    var p = data.payxy || {};
    var rows = [
      ['Outcome', '<span class="badge ' + tone + '">' + esc(data.outcome) + '</span>'],
      ['Transaction ID', esc(data.transactionId)],
      ['Our order amount', esc(data.orderAmount)],
      ['responseCode', esc(p.responseCode || '04 (no record)')],
      ['Payxy status', esc(p.status || 'no payment attempted')],
      ['isPaid', esc(String(!!p.isPaid))],
      ['Amount (data.amount)', esc(p.amount || '—')],
      ['Customer paid', esc(p.customerPaid || '—')],
      ['Gateway response', esc(p.gatewayResponse || '—')],
      ['Payment reference', esc(p.paymentReference || '—')],
      ['Card', esc(p.card || '—')]
    ];
    box.innerHTML = '<table>' + rows.map(function (r) { return '<tr><th>' + r[0] + '</th><td>' + r[1] + '</td></tr>'; }).join('') + '</table>'
      + '<p class="actions"><a href="/frontend/return">Re-check status</a> · <a href="/frontend">New order</a> · <a href="/">All flows</a></p>'
      + '<details><summary>Raw JSON from GET /api/payments/current</summary><pre class="log"></pre></details>';
    box.querySelector('pre').textContent = JSON.stringify(data, null, 2);
  }

  function esc(v) {
    return String(v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('js-checkout');
    if (form) { form.addEventListener('submit', startCheckout); }
    var box = document.getElementById('js-result');
    if (box) { showResult(box); }
  });
})();
