import csv
import sqlite3
import os
import time

csv_path = r"c:\xampp\htdocs\DiaWell_backend\meds_common.csv"
db_dir = r"c:\xampp\htdocs\DiaWell_backend\data"
db_path = os.path.join(db_dir, "meds.db")
db_temp_path = os.path.join(db_dir, "meds_new.db")

print(f"Starting import of {csv_path} into SQLite...")
t0 = time.time()

if os.path.exists(db_temp_path):
    os.remove(db_temp_path)

conn = sqlite3.connect(db_temp_path)
cur = conn.cursor()

# Set performance pragmas for ultra fast bulk loading
cur.execute("PRAGMA synchronous = OFF;")
cur.execute("PRAGMA journal_mode = MEMORY;")
cur.execute("PRAGMA cache_size = 50000;")

cur.execute("""
CREATE TABLE medicines (
    product_id INTEGER PRIMARY KEY,
    brand_name TEXT NOT NULL,
    manufacturer TEXT,
    price_inr REAL,
    is_discontinued INTEGER DEFAULT 0,
    dosage_form TEXT,
    pack_size REAL,
    pack_unit TEXT,
    num_active_ingredients INTEGER,
    primary_ingredient TEXT,
    primary_strength TEXT,
    active_ingredients TEXT,
    therapeutic_class TEXT,
    packaging_raw TEXT,
    manufacturer_raw TEXT
);
""")

cur.execute("""
CREATE VIRTUAL TABLE medicines_fast USING fts5(
    product_id UNINDEXED,
    brand_name,
    primary_ingredient,
    primary_strength,
    dosage_form,
    therapeutic_class,
    manufacturer,
    active_ingredients,
    packaging_raw UNINDEXED,
    price_inr UNINDEXED,
    prefix='2 3 4'
);
""")

cur.execute("CREATE INDEX idx_meds_brand ON medicines(brand_name);")
cur.execute("CREATE INDEX idx_meds_ingredient ON medicines(primary_ingredient);")
cur.execute("CREATE INDEX idx_meds_class ON medicines(therapeutic_class);")

med_rows = []
fts_rows = []
count = 0

with open(csv_path, mode='r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for r in reader:
        try:
            pid = int(r['product_id'])
        except (ValueError, TypeError):
            continue

        brand = r.get('brand_name', '').strip()
        mfg = r.get('manufacturer', '').strip()
        try:
            price = float(r.get('price_inr', '0') or 0.0)
        except ValueError:
            price = 0.0

        is_disc = 1 if r.get('is_discontinued', '').strip().lower() in ('true', '1') else 0
        dosage = r.get('dosage_form', '').strip()
        try:
            pack_sz = float(r.get('pack_size', '0') or 0.0)
        except ValueError:
            pack_sz = 0.0
        pack_un = r.get('pack_unit', '').strip()
        try:
            num_act = int(r.get('num_active_ingredients', '0') or 0)
        except ValueError:
            num_act = 0

        p_ing = r.get('primary_ingredient', '').strip()
        p_str = r.get('primary_strength', '').strip()
        act_ing = r.get('active_ingredients', '').strip()
        th_class = r.get('therapeutic_class', '').strip()
        pkg_raw = r.get('packaging_raw', '').strip()
        mfg_raw = r.get('manufacturer_raw', '').strip()

        med_rows.append((
            pid, brand, mfg, price, is_disc, dosage, pack_sz, pack_un,
            num_act, p_ing, p_str, act_ing, th_class, pkg_raw, mfg_raw
        ))

        fts_rows.append((
            pid, brand, p_ing, p_str, dosage, th_class, mfg, act_ing, pkg_raw, price
        ))

        count += 1
        if len(med_rows) >= 10000:
            cur.executemany("""
                INSERT OR REPLACE INTO medicines (
                    product_id, brand_name, manufacturer, price_inr, is_discontinued,
                    dosage_form, pack_size, pack_unit, num_active_ingredients,
                    primary_ingredient, primary_strength, active_ingredients,
                    therapeutic_class, packaging_raw, manufacturer_raw
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, med_rows)
            cur.executemany("""
                INSERT INTO medicines_fast (
                    product_id, brand_name, primary_ingredient, primary_strength,
                    dosage_form, therapeutic_class, manufacturer, active_ingredients,
                    packaging_raw, price_inr
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, fts_rows)
            med_rows = []
            fts_rows = []

if med_rows:
    cur.executemany("""
        INSERT OR REPLACE INTO medicines (
            product_id, brand_name, manufacturer, price_inr, is_discontinued,
            dosage_form, pack_size, pack_unit, num_active_ingredients,
            primary_ingredient, primary_strength, active_ingredients,
            therapeutic_class, packaging_raw, manufacturer_raw
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, med_rows)
    cur.executemany("""
        INSERT INTO medicines_fast (
            product_id, brand_name, primary_ingredient, primary_strength,
            dosage_form, therapeutic_class, manufacturer, active_ingredients,
            packaging_raw, price_inr
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, fts_rows)

conn.commit()

# Optimize database
print("Optimizing and analyzing SQLite database...")
cur.execute("PRAGMA optimize;")
conn.commit()
conn.close()

t1 = time.time()
print(f"Loaded {count} rows in {round(t1 - t0, 2)} seconds into {db_temp_path}.")

# Safely replace meds.db
old_backup = os.path.join(db_dir, "meds_prev_backup.db")
if os.path.exists(old_backup):
    os.remove(old_backup)

if os.path.exists(db_path):
    os.rename(db_path, old_backup)

os.rename(db_temp_path, db_path)
print(f"Successfully created new {db_path} with meds_common.csv dataset!")
print(f"File size: {round(os.path.getsize(db_path) / (1024 * 1024), 2)} MB")

