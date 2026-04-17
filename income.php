<?php
require_once 'includes/header.php';

$members = [];
$stmt = $db->prepare("SELECT id, name FROM household WHERE user_id = :userId ORDER BY name ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $members[] = $row;
}

$subscriptions = [];
$stmt = $db->prepare("SELECT id, name FROM subscriptions WHERE user_id = :userId ORDER BY name ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptions[] = $row;
}

$currencies = [];
$stmt = $db->prepare("SELECT id, name, code, symbol FROM currencies WHERE user_id = :userId ORDER BY name ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $currencies[] = $row;
}
?>

<section class="contain">
  <h2><?= translate('income_management', $i18n) ?></h2>
  <div class="income-layout">
    <section class="box">
      <h3 id="income-form-title"><?= translate('add_income', $i18n) ?></h3>
      <form id="income-form">
        <input type="hidden" id="income-id" name="id">
        <div class="form-group-inline income-grid">
          <div class="income-field">
            <label for="income-type"><?= translate('income_type', $i18n) ?></label>
            <select id="income-type" name="type">
              <option value="entry"><?= translate('one_time_income', $i18n) ?></option>
              <option value="recurring"><?= translate('recurring_income', $i18n) ?></option>
            </select>
          </div>
          <div class="income-field">
            <label for="income-household"><?= translate('member', $i18n) ?></label>
            <select id="income-household" name="household_id" required>
              <?php foreach ($members as $member): ?>
                <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group-inline income-grid">
          <div class="income-field">
            <label for="income-amount"><?= translate('amount', $i18n) ?></label>
            <input type="number" step="0.01" min="0" id="income-amount" name="amount" placeholder="<?= translate('amount', $i18n) ?>" required>
          </div>
          <div class="income-field">
            <label for="income-currency"><?= translate('currency', $i18n) ?></label>
            <select id="income-currency" name="currency_id" required>
              <?php foreach ($currencies as $currency): ?>
                <option value="<?= $currency['id'] ?>" <?= intval($currency['id']) === intval($main_currency) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($currency['name']) ?> (<?= htmlspecialchars($currency['code']) ?><?= $currency['symbol'] ? ' - ' . htmlspecialchars($currency['symbol']) : '' ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group-inline income-entry-fields income-grid">
          <div class="income-field">
            <label for="income-date"><?= translate('income_date', $i18n) ?></label>
            <input type="date" id="income-date" name="income_date">
          </div>
          <div class="income-field">
            <label for="income-subscription"><?= translate('linked_subscription', $i18n) ?></label>
            <select id="income-subscription" name="subscription_id">
              <option value=""><?= translate('none', $i18n) ?></option>
              <?php foreach ($subscriptions as $subscription): ?>
                <option value="<?= $subscription['id'] ?>"><?= htmlspecialchars($subscription['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="income-recurring-fields hide">
          <div class="form-group-inline income-grid">
            <div class="income-field">
              <label for="income-frequency"><?= translate('recurring_frequency', $i18n) ?></label>
              <input type="number" min="1" id="income-frequency" name="frequency" placeholder="<?= translate('frequency', $i18n) ?>">
            </div>
            <div class="income-field">
              <label for="income-cycle"><?= translate('cycle', $i18n) ?></label>
              <select id="income-cycle" name="cycle">
                <option value="1"><?= translate('Daily', $i18n) ?></option>
                <option value="2"><?= translate('Weekly', $i18n) ?></option>
                <option value="3"><?= translate('Monthly', $i18n) ?></option>
                <option value="4"><?= translate('Yearly', $i18n) ?></option>
              </select>
            </div>
          </div>
          <div class="form-group-inline income-grid">
            <div class="income-field">
              <label for="income-start-date"><?= translate('start_date', $i18n) ?></label>
              <input type="date" id="income-start-date" name="start_date">
            </div>
            <div class="income-field">
              <label for="income-end-date"><?= translate('end_date', $i18n) ?></label>
              <input type="date" id="income-end-date" name="end_date">
            </div>
          </div>
          <div class="form-group-inline income-grid recurring-bottom-row">
            <div class="income-field">
              <label for="income-recurring-subscription"><?= translate('linked_subscription', $i18n) ?></label>
              <select id="income-recurring-subscription" name="subscription_id">
                <option value=""><?= translate('none', $i18n) ?></option>
                <?php foreach ($subscriptions as $subscription): ?>
                  <option value="<?= $subscription['id'] ?>"><?= htmlspecialchars($subscription['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="income-field income-active-toggle">
              <label for="income-active"><?= translate('state', $i18n) ?></label>
              <div class="form-group-inline">
                <input type="checkbox" id="income-active" name="active" checked>
                <label for="income-active"><?= translate('enabled', $i18n) ?></label>
              </div>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="income-note"><?= translate('notes', $i18n) ?></label>
          <input type="text" id="income-note" name="note" placeholder="<?= translate('notes', $i18n) ?>">
        </div>
        <div class="buttons">
          <input type="button" class="secondary-button thin" id="income-cancel-button" value="<?= translate('reset', $i18n) ?>">
          <input type="submit" class="thin" value="<?= translate('save', $i18n) ?>">
        </div>
      </form>
    </section>

    <section class="box">
      <h3><?= translate('income_entries', $i18n) ?></h3>
      <div class="form-group-inline">
        <select id="income-filter-type">
          <option value="all"><?= translate('all', $i18n) ?></option>
          <option value="entry"><?= translate('one_time_income', $i18n) ?></option>
          <option value="recurring"><?= translate('recurring_income', $i18n) ?></option>
        </select>
        <select id="income-filter-member">
          <option value=""><?= translate('all', $i18n) ?></option>
          <?php foreach ($members as $member): ?>
            <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group-inline">
        <input type="date" id="income-filter-start">
        <input type="date" id="income-filter-end">
      </div>
      <div id="income-list"></div>
    </section>
  </div>
</section>
<script src="scripts/income.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
