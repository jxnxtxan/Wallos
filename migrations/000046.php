<?php

$entriesTableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='person_income_entries'");
if (!$entriesTableExists) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS person_income_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            household_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            currency_id INTEGER NOT NULL,
            income_date TEXT NOT NULL,
            subscription_id INTEGER,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_income_entries_user_id ON person_income_entries(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_income_entries_household_id ON person_income_entries(household_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_income_entries_income_date ON person_income_entries(income_date)");
}

$recurringTableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='person_income_recurring'");
if (!$recurringTableExists) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS person_income_recurring (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            household_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            currency_id INTEGER NOT NULL,
            cycle INTEGER NOT NULL,
            frequency INTEGER NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT,
            subscription_id INTEGER,
            note TEXT,
            active INTEGER NOT NULL DEFAULT 1
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_income_recurring_user_id ON person_income_recurring(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_income_recurring_household_id ON person_income_recurring(household_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_income_recurring_start_date ON person_income_recurring(start_date)");
}

?>
