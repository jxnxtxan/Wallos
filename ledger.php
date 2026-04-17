<?php
require_once 'includes/header.php';
?>

<section class="contain">
  <h2><?= translate('person_ledger', $i18n) ?></h2>

  <div class="box">
    <div class="form-group-inline">
      <select id="ledger-scope">
        <option value="month"><?= translate('current_month', $i18n) ?></option>
        <option value="year"><?= translate('current_year', $i18n) ?></option>
        <option value="range"><?= translate('custom_range', $i18n) ?></option>
        <option value="all"><?= translate('total_period', $i18n) ?></option>
      </select>
      <input type="date" id="ledger-start" class="ledger-range hide">
      <input type="date" id="ledger-end" class="ledger-range hide">
      <button type="button" class="button thin" id="ledger-refresh"><?= translate('update', $i18n) ?></button>
    </div>
  </div>

  <div class="ledger-summary box" id="ledger-summary"></div>
  <div id="ledger-members"></div>
</section>

<script src="scripts/ledger.js?<?= $version ?>"></script>
<?php
require_once 'includes/footer.php';
?>
