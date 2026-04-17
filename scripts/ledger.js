function ledgerFormat(amount, code, symbol) {
  const a = Number(amount || 0).toFixed(2);
  if (symbol) {
    return `${a} ${symbol}`;
  }
  if (code) {
    return `${a} ${code}`;
  }
  return a;
}

function renderLedgerSummary(ledger) {
  const summary = document.querySelector("#ledger-summary");
  summary.innerHTML = `
    <div class="statistic">
      <span>${ledgerFormat(ledger.grand_subscriptions_total, ledger.main_currency_code, ledger.main_currency_symbol)}</span>
      <div class="title">${translate("expenses_total")}</div>
    </div>
    <div class="statistic">
      <span>${ledgerFormat(ledger.grand_income_total, ledger.main_currency_code, ledger.main_currency_symbol)}</span>
      <div class="title">${translate("income_total")}</div>
    </div>
    <div class="statistic">
      <span>${ledgerFormat(ledger.grand_net_difference, ledger.main_currency_code, ledger.main_currency_symbol)}</span>
      <div class="title">${translate("difference")}</div>
    </div>
  `;
}

function renderLedgerMembers(ledger) {
  const target = document.querySelector("#ledger-members");
  const cards = ledger.members.map((member) => {
    const breakdown = member.subscription_breakdown.length > 0
      ? `<ul class="ledger-breakdown-list">${member.subscription_breakdown.map((item) => `
          <li class="ledger-breakdown-item">
            <span class="ledger-breakdown-name">${item.subscription_name}</span>
            <strong class="ledger-breakdown-amount">${ledgerFormat(item.monthly_amount, ledger.main_currency_code, ledger.main_currency_symbol)}</strong>
          </li>
        `).join("")}</ul>`
      : `<div class="muted">${translate("no_subscriptions_yet")}</div>`;
    const diffClass = member.net_difference >= 0 ? "positive" : "negative";
    const diffText = member.net_difference >= 0 ? translate("receives") : translate("owes");
    return `
      <section class="box ledger-card">
        <header class="ledger-card-header"><h3>${member.name}</h3></header>
        <div class="ledger-breakdown">${breakdown}</div>
        <div class="ledger-totals">
          <div class="ledger-total-row">
            <span>${translate("expenses_total")}</span>
            <strong>${ledgerFormat(member.subscriptions_total, ledger.main_currency_code, ledger.main_currency_symbol)}</strong>
          </div>
          <div class="ledger-total-row">
            <span>${translate("income_total")}</span>
            <strong>${ledgerFormat(member.income_total, ledger.main_currency_code, ledger.main_currency_symbol)}</strong>
          </div>
          <div class="ledger-total-row ledger-total-diff ${diffClass}">
            <span>${translate("difference")}</span>
            <strong>${ledgerFormat(member.net_difference, ledger.main_currency_code, ledger.main_currency_symbol)}</strong>
            <span class="ledger-diff-label">${diffText}</span>
          </div>
        </div>
      </section>
    `;
  });
  target.innerHTML = cards.join("");
}

function loadLedger() {
  const scope = document.querySelector("#ledger-scope").value;
  const start = document.querySelector("#ledger-start").value;
  const end = document.querySelector("#ledger-end").value;

  const params = new URLSearchParams();
  params.set("scope", scope);
  if (scope === "range") {
    if (!start || !end) {
      showErrorMessage(translate("fill_mandatory_fields"));
      return;
    }
    params.set("start_date", start);
    params.set("end_date", end);
  }

  fetch(`endpoints/ledger/get.php?${params.toString()}`)
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) {
        showErrorMessage(data.message || translate("error"));
        return;
      }
      renderLedgerSummary(data.ledger);
      renderLedgerMembers(data.ledger);
    })
    .catch(() => showErrorMessage(translate("error")));
}

document.addEventListener("DOMContentLoaded", () => {
  const scope = document.querySelector("#ledger-scope");
  const rangeFields = document.querySelectorAll(".ledger-range");

  scope.addEventListener("change", () => {
    if (scope.value === "range") {
      rangeFields.forEach((el) => el.classList.remove("hide"));
    } else {
      rangeFields.forEach((el) => el.classList.add("hide"));
    }
    loadLedger();
  });

  document.querySelector("#ledger-refresh").addEventListener("click", loadLedger);
  document.querySelector("#ledger-start").addEventListener("change", loadLedger);
  document.querySelector("#ledger-end").addEventListener("change", loadLedger);
  loadLedger();
});
