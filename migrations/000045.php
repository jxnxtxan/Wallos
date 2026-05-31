<?php

$tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='subscription_participants'");

if (!$tableCheck) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS subscription_participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            household_id INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            is_manual BOOLEAN NOT NULL DEFAULT 0,
            FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (household_id) REFERENCES household(id),
            UNIQUE (subscription_id, household_id)
        )
    ");
}

?>
