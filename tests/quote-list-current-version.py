"""Execute CoreOS's actual quote-list SQL against a deliberately divergent fixture."""
import re
import sqlite3
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / 'api/lib/CoreOS.php').read_text()
query = re.search(r'\$sql="(SELECT q\.\*[^"\n]+FROM quotes q[^"\n]+)"', source)
assert query, 'Quote list SQL not found; update the fixture if the query moves'
db = sqlite3.connect(':memory:')
db.row_factory = sqlite3.Row
db.executescript('''
CREATE TABLE trips (id, trip_ref, title, lead_contact_name, market, start_date, end_date, total_guests, paying_pax, foc);
CREATE TABLE quotes (id, company_id, trip_id, current_version_no, quote_ref, updated_at);
CREATE TABLE quote_versions (quote_id, version_no, tour_name, start_date, end_date, total_guests, paying_pax, foc, selling_per_pax, total_selling, margin_pct, version_status);
INSERT INTO trips VALUES (1,'TR-1','Old trip title','Contact','VN','2026-01-01','2026-01-08',8,8,0);
INSERT INTO trips VALUES (2,'TR-2','Other tenant','Other','VN','2026-01-01','2026-01-08',99,99,0);
INSERT INTO quotes VALUES (1,10,1,2,'QT-1','2026-10-14'),(2,20,2,1,'QT-2','2026-10-15'),(3,10,1,1,'QT-3','2026-10-13');
INSERT INTO quote_versions VALUES (1,1,'Previous quote','2026-02-01','2026-02-08',8,8,0,100,800,10,'SUPERSEDED');
INSERT INTO quote_versions VALUES (1,2,'Current quote','2026-03-01','2026-03-03',7,6,1,200,1200,20,'DRAFT');
INSERT INTO quote_versions VALUES (2,1,'Private quote','2026-04-01','2026-04-08',99,99,0,1,99,10,'SENT');
INSERT INTO quote_versions VALUES (3,1,'Same trip, different quote','2026-05-01','2026-05-04',4,4,0,150,600,15,'SENT');
''')
rows = [dict(row) for row in db.execute(query[1], (10,))]
assert [row['quote_ref'] for row in rows] == ['QT-1', 'QT-3'], rows
current = rows[0]
assert (current['total_guests'], current['paying_pax'], current['foc']) == (7, 6, 1), current
assert current['title'] == current['tour_name'] == 'Current quote', current
assert (current['start_date'], current['end_date']) == ('2026-03-01', '2026-03-03'), current
assert (current['selling_per_pax'], current['total_selling'], current['version_status']) == (200, 1200, 'DRAFT'), current
assert (current['trip_ref'], current['lead_contact_name'], current['market']) == ('TR-1', 'Contact', 'VN'), current
assert rows[1]['total_guests'] == 4, rows[1]
assert len(list(db.execute(query[1], (999,)))) == 0
print('PASS quote list: current version PAX/FOC, dates, title, pricing, shared trip and tenant scope')
