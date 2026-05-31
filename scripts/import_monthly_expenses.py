#!/usr/bin/env python3
import datetime
import html
import re
import sqlite3
from pathlib import Path


BASE_DIR = Path(__file__).resolve().parent.parent
DB_PATH = BASE_DIR / "db" / "wallos.db"
EXPENSES_DIR = BASE_DIR / "Monatliche Ausgaben"

USER_ID = 1
MAIN_CURRENCY_ID = 1
TODAY = datetime.date.today().isoformat()
DEFAULT_PAYMENT_METHOD_ID = 1
DEFAULT_CATEGORY_ID = 1
DEFAULT_PAYER_ID = 1

FILES = {
    "Allgemein": EXPENSES_DIR / "Allgemein.html",
    "Joni": EXPENSES_DIR / "Joni.html",
    "Einnahmen": EXPENSES_DIR / "Einnahmen.html",
    "Pro_Person": EXPENSES_DIR / "Pro_Person.html",
    "Personen_Vergleich": EXPENSES_DIR / "Personen_Vergleich.html",
}


def normalize_text(value: str) -> str:
    return re.sub(r"\s+", " ", value.strip())


def parse_amount(value: str) -> float:
    cleaned = (
        value.replace("\xa0", "")
        .replace("€", "")
        .replace("EUR", "")
        .replace(" ", "")
        .replace(".", "")
        .replace(",", ".")
    )
    cleaned = re.sub(r"[^0-9.\-]", "", cleaned)
    if not cleaned:
        return 0.0
    return round(float(cleaned), 2)


def parse_people(people_cell: str) -> list[str]:
    result: list[str] = []
    for part in people_cell.split(","):
        person = normalize_text(part)
        if person and person != "#":
            result.append(person)
    seen = set()
    unique = []
    for person in result:
        key = person.lower()
        if key not in seen:
            unique.append(person)
            seen.add(key)
    return unique


def read_table_rows(path: Path) -> list[list[str]]:
    raw = path.read_text(encoding="utf-8", errors="ignore")
    rows: list[list[str]] = []
    tr_matches = re.findall(r"<tr[^>]*>(.*?)</tr>", raw, flags=re.IGNORECASE | re.DOTALL)
    for tr_html in tr_matches:
        td_matches = re.findall(r"<td[^>]*>(.*?)</td>", tr_html, flags=re.IGNORECASE | re.DOTALL)
        cells = []
        for td_html in td_matches:
            text = re.sub(r"<[^>]+>", " ", td_html)
            text = normalize_text(html.unescape(text))
            cells.append(text)
        if cells:
            rows.append(cells)
    return rows


def get_or_create_household_id(conn: sqlite3.Connection, name: str) -> int:
    cur = conn.execute(
        "SELECT id FROM household WHERE user_id = ? AND lower(name) = lower(?) LIMIT 1",
        (USER_ID, name),
    )
    row = cur.fetchone()
    if row:
        return int(row[0])

    cur = conn.execute(
        "INSERT INTO household (name, user_id) VALUES (?, ?)",
        (name, USER_ID),
    )
    return int(cur.lastrowid)


def upsert_subscription(
    conn: sqlite3.Connection,
    name: str,
    price: float,
    cycle: int,
    frequency: int,
    notes: str,
    payer_id: int,
) -> int:
    cur = conn.execute(
        "SELECT id FROM subscriptions WHERE user_id = ? AND lower(name) = lower(?) LIMIT 1",
        (USER_ID, name),
    )
    row = cur.fetchone()
    if row:
        sub_id = int(row[0])
        conn.execute(
            """
            UPDATE subscriptions
               SET price = ?,
                   currency_id = ?,
                   cycle = ?,
                   frequency = ?,
                   notes = ?,
                   start_date = ?,
                   next_payment = ?,
                   payment_method_id = ?,
                   payer_user_id = ?,
                   category_id = ?,
                   inactive = 0
             WHERE id = ? AND user_id = ?
            """,
            (
                price,
                MAIN_CURRENCY_ID,
                cycle,
                frequency,
                notes,
                TODAY,
                TODAY,
                DEFAULT_PAYMENT_METHOD_ID,
                payer_id,
                DEFAULT_CATEGORY_ID,
                sub_id,
                USER_ID,
            ),
        )
        return sub_id

    cur = conn.execute(
        """
        INSERT INTO subscriptions
            (name, logo, price, currency_id, next_payment, cycle, frequency, notes,
             payment_method_id, payer_user_id, category_id, notify, inactive, url,
             notify_days_before, user_id, cancellation_date, replacement_subscription_id,
             auto_renew, start_date)
        VALUES
            (?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, '', 3, ?, NULL, NULL, 1, ?)
        """,
        (
            name,
            price,
            MAIN_CURRENCY_ID,
            TODAY,
            cycle,
            frequency,
            notes,
            DEFAULT_PAYMENT_METHOD_ID,
            payer_id,
            DEFAULT_CATEGORY_ID,
            USER_ID,
            TODAY,
        ),
    )
    return int(cur.lastrowid)


def set_participants(conn: sqlite3.Connection, sub_id: int, household_ids: list[int], total_price: float) -> None:
    if not household_ids:
        return

    conn.execute("DELETE FROM subscription_participants WHERE subscription_id = ?", (sub_id,))

    total_cents = round(total_price * 100)
    count = len(household_ids)
    base = total_cents // count
    remainder = total_cents % count

    for idx, household_id in enumerate(household_ids):
        cents = base + (1 if idx < remainder else 0)
        amount = round(cents / 100, 2)
        conn.execute(
            "INSERT INTO subscription_participants (subscription_id, household_id, amount, is_manual) VALUES (?, ?, ?, 0)",
            (sub_id, household_id, amount),
        )


def map_months(months: int):
    if months == 1:
        return (3, 1)   # monthly
    if months == 3:
        return (3, 3)   # every 3 months
    if months == 6:
        return (3, 6)   # every 6 months
    if months == 12:
        return (4, 1)   # yearly
    return None


def main() -> None:
    for label, p in FILES.items():
        if not p.exists():
            raise FileNotFoundError(f"Missing {label}: {p}")

    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("BEGIN")

    before_households = conn.execute(
        "SELECT COUNT(*) FROM household WHERE user_id = ?",
        (USER_ID,),
    ).fetchone()[0]

    stats = {
        "subscriptions_upserted": 0,
        "income_recurring_upserted": 0,
        "income_entries_inserted": 0,
    }

    sub_id_by_name: dict[str, int] = {}

    # Allgemein import
    for cells in read_table_rows(FILES["Allgemein"]):
        name = normalize_text(cells[0] if len(cells) > 0 else "")
        if not name or name.lower() == "produkt":
            continue

        people = parse_people(cells[1] if len(cells) > 1 else "")
        monthly = parse_amount(cells[2] if len(cells) > 2 else "")
        yearly = parse_amount(cells[3] if len(cells) > 3 else "")
        note = normalize_text(cells[5] if len(cells) > 5 else "")

        if monthly <= 0 and yearly <= 0:
            continue

        if monthly > 0:
            price, cycle, frequency = monthly, 3, 1
        else:
            price, cycle, frequency = yearly, 4, 1

        household_ids = [get_or_create_household_id(conn, p) for p in people]
        if not household_ids:
            continue

        payer_id = household_ids[0]
        sub_id = upsert_subscription(conn, name, price, cycle, frequency, note, payer_id)
        set_participants(conn, sub_id, household_ids, price)
        sub_id_by_name[name.lower()] = sub_id
        stats["subscriptions_upserted"] += 1

    # Joni import
    for cells in read_table_rows(FILES["Joni"]):
        name = normalize_text(cells[0] if len(cells) > 0 else "")
        if not name or name.lower() == "name":
            continue
        if "kosten aus allgemein" in name.lower():
            continue

        person = normalize_text(cells[1] if len(cells) > 1 else "")
        if person != "Joni":
            continue

        monthly = parse_amount(cells[2] if len(cells) > 2 else "")
        yearly = parse_amount(cells[3] if len(cells) > 3 else "")
        note = normalize_text(cells[4] if len(cells) > 4 else "")
        if monthly <= 0 and yearly <= 0:
            continue

        if monthly > 0:
            price, cycle, frequency = monthly, 3, 1
        else:
            price, cycle, frequency = yearly, 4, 1

        joni_id = get_or_create_household_id(conn, "Joni")
        sub_id = upsert_subscription(conn, name, price, cycle, frequency, note, joni_id)
        set_participants(conn, sub_id, [joni_id], price)
        sub_id_by_name[name.lower()] = sub_id
        stats["subscriptions_upserted"] += 1

    # Einnahmen import
    for cells in read_table_rows(FILES["Einnahmen"]):
        person = normalize_text(cells[0] if len(cells) > 0 else "")
        if not person or person.lower() == "name":
            continue

        amount = parse_amount(cells[1] if len(cells) > 1 else "")
        product = normalize_text(cells[2] if len(cells) > 2 else "")
        months = int(parse_amount(cells[3] if len(cells) > 3 else "0"))
        status = normalize_text(cells[4] if len(cells) > 4 else "")
        if amount <= 0:
            continue
        if status.lower() == "ignore":
            continue

        household_id = get_or_create_household_id(conn, person)
        sub_id = sub_id_by_name.get(product.lower())
        note = f"Import Einnahmen: {product}"

        mapped = map_months(months)
        if mapped:
            cycle, frequency = mapped
            existing = conn.execute(
                """
                SELECT id
                  FROM person_income_recurring
                 WHERE user_id = ?
                   AND household_id = ?
                   AND amount = ?
                   AND cycle = ?
                   AND frequency = ?
                   AND ifnull(subscription_id, 0) = ifnull(?, 0)
                 LIMIT 1
                """,
                (USER_ID, household_id, amount, cycle, frequency, sub_id),
            ).fetchone()

            if existing:
                conn.execute(
                    """
                    UPDATE person_income_recurring
                       SET note = ?, active = 1, start_date = ?
                     WHERE id = ? AND user_id = ?
                    """,
                    (note, TODAY, existing["id"], USER_ID),
                )
            else:
                conn.execute(
                    """
                    INSERT INTO person_income_recurring
                        (user_id, household_id, amount, currency_id, cycle, frequency, start_date, end_date, subscription_id, note, active)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, 1)
                    """,
                    (USER_ID, household_id, amount, MAIN_CURRENCY_ID, cycle, frequency, TODAY, sub_id, note),
                )
            stats["income_recurring_upserted"] += 1
        else:
            conn.execute(
                """
                INSERT INTO person_income_entries
                    (user_id, household_id, amount, currency_id, income_date, subscription_id, note)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?)
                """,
                (USER_ID, household_id, amount, MAIN_CURRENCY_ID, TODAY, sub_id, f"{note} ({months} Monate)"),
            )
            stats["income_entries_inserted"] += 1

    after_households = conn.execute(
        "SELECT COUNT(*) FROM household WHERE user_id = ?",
        (USER_ID,),
    ).fetchone()[0]

    conn.commit()
    conn.close()

    print("Import complete.")
    print(f"Household created: {max(0, after_households - before_households)}")
    print(f"Subscriptions upserted: {stats['subscriptions_upserted']}")
    print(f"Recurring incomes upserted: {stats['income_recurring_upserted']}")
    print(f"One-time incomes inserted: {stats['income_entries_inserted']}")


if __name__ == "__main__":
    main()

