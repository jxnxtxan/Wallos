function formatMoney(value, code) {
  const amount = Number(value || 0).toFixed(2);
  return code ? `${amount} ${code}` : amount;
}

function cycleLabel(cycleValue) {
  const cycle = Number(cycleValue);
  if (cycle === 1) return translate("Daily");
  if (cycle === 2) return translate("Weekly");
  if (cycle === 3) return translate("Monthly");
  if (cycle === 4) return translate("Yearly");
  return cycleValue;
}

function toggleIncomeFields() {
  const type = document.querySelector("#income-type").value;
  const entryFields = document.querySelector(".income-entry-fields");
  const recurringFields = document.querySelector(".income-recurring-fields");
  if (type === "recurring") {
    entryFields.classList.add("hide");
    recurringFields.classList.remove("hide");
  } else {
    entryFields.classList.remove("hide");
    recurringFields.classList.add("hide");
  }
}

function resetIncomeForm() {
  document.querySelector("#income-form").reset();
  document.querySelector("#income-id").value = "";
  document.querySelector("#income-form-title").textContent = translate("add_income");
  toggleIncomeFields();
}

function serializeIncomeForm() {
  const form = document.querySelector("#income-form");
  return new FormData(form);
}

function openIncomeForEdit(item, type) {
  document.querySelector("#income-id").value = item.id;
  document.querySelector("#income-type").value = type;
  document.querySelector("#income-household").value = item.household_id;
  document.querySelector("#income-amount").value = item.amount;
  document.querySelector("#income-currency").value = item.currency_id;
  document.querySelector("#income-note").value = item.note || "";

  if (type === "recurring") {
    document.querySelector("#income-frequency").value = item.frequency;
    document.querySelector("#income-cycle").value = item.cycle;
    document.querySelector("#income-start-date").value = item.start_date;
    document.querySelector("#income-end-date").value = item.end_date || "";
    document.querySelector("#income-recurring-subscription").value = item.subscription_id || "";
    document.querySelector("#income-active").checked = Number(item.active) === 1;
  } else {
    document.querySelector("#income-date").value = item.income_date;
    document.querySelector("#income-subscription").value = item.subscription_id || "";
  }

  document.querySelector("#income-form-title").textContent = translate("edit_income");
  toggleIncomeFields();
}

function renderIncomeRows(entries, recurring) {
  const list = document.querySelector("#income-list");
  const rows = [];
  const metaChips = (parts) => parts
    .filter(Boolean)
    .map((part) => `<span class="income-meta-chip">${part}</span>`)
    .join("");

  entries.forEach((entry) => {
    const entryMeta = metaChips([entry.income_date, entry.subscription_name]);
    rows.push(`
      <div class="income-row">
        <div class="income-row-header">
          <strong class="income-row-name">${entry.household_name}</strong>
          <span class="income-row-amount">${formatMoney(entry.amount, entry.currency_code)}</span>
        </div>
        <div class="income-row-meta">${entryMeta}</div>
        <div class="income-actions">
          <button type="button" class="button secondary-button thin" onclick='openIncomeForEdit(${JSON.stringify(entry)}, "entry")'>${translate("edit_subscription")}</button>
          <button type="button" class="button warning-button thin" onclick='deleteIncome(${entry.id}, "entry")'>${translate("delete")}</button>
        </div>
      </div>
    `);
  });

  recurring.forEach((item) => {
    const recurringMeta = metaChips([
      `${translate("recurring_income")}: ${item.frequency} ${cycleLabel(item.cycle)}`,
      item.start_date && item.end_date ? `${item.start_date} - ${item.end_date}` : item.start_date,
      item.subscription_name
    ]);
    rows.push(`
      <div class="income-row recurring">
        <div class="income-row-header">
          <strong class="income-row-name">${item.household_name}</strong>
          <span class="income-row-amount">${formatMoney(item.amount, item.currency_code)}</span>
        </div>
        <div class="income-row-meta">${recurringMeta}</div>
        <div class="income-actions">
          <button type="button" class="button secondary-button thin" onclick='openIncomeForEdit(${JSON.stringify(item)}, "recurring")'>${translate("edit_subscription")}</button>
          <button type="button" class="button warning-button thin" onclick='deleteIncome(${item.id}, "recurring")'>${translate("delete")}</button>
        </div>
      </div>
    `);
  });

  list.innerHTML = rows.length > 0 ? rows.join("") : `<div class="no-matching-subscriptions">${translate("empty_page")}</div>`;
}

function loadIncomeList() {
  const type = document.querySelector("#income-filter-type").value;
  const householdId = document.querySelector("#income-filter-member").value;
  const startDate = document.querySelector("#income-filter-start").value;
  const endDate = document.querySelector("#income-filter-end").value;

  const params = new URLSearchParams();
  if (type) params.set("type", type);
  if (householdId) params.set("household_id", householdId);
  if (startDate) params.set("start_date", startDate);
  if (endDate) params.set("end_date", endDate);

  fetch(`endpoints/income/list.php?${params.toString()}`)
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) {
        showErrorMessage(data.message || translate("error"));
        return;
      }
      renderIncomeRows(data.entries || [], data.recurring || []);
    })
    .catch(() => showErrorMessage(translate("error")));
}

function deleteIncome(id, type) {
  if (!confirm(translate("confirm_delete_subscription"))) {
    return;
  }
  fetch("endpoints/income/delete.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken
    },
    body: JSON.stringify({ id, type })
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message || translate("success"));
        loadIncomeList();
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch(() => showErrorMessage(translate("error")));
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelector("#income-type").addEventListener("change", toggleIncomeFields);
  document.querySelector("#income-cancel-button").addEventListener("click", resetIncomeForm);
  document.querySelector("#income-filter-type").addEventListener("change", loadIncomeList);
  document.querySelector("#income-filter-member").addEventListener("change", loadIncomeList);
  document.querySelector("#income-filter-start").addEventListener("change", loadIncomeList);
  document.querySelector("#income-filter-end").addEventListener("change", loadIncomeList);

  document.querySelector("#income-form").addEventListener("submit", (e) => {
    e.preventDefault();
    const formData = serializeIncomeForm();
    fetch("endpoints/income/add.php", {
      method: "POST",
      headers: { "X-CSRF-Token": window.csrfToken },
      body: formData
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showSuccessMessage(data.message || translate("success"));
          resetIncomeForm();
          loadIncomeList();
        } else {
          showErrorMessage(data.message || translate("error"));
        }
      })
      .catch(() => showErrorMessage(translate("error")));
  });

  toggleIncomeFields();
  loadIncomeList();
});
