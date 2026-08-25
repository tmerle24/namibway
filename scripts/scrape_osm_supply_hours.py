#!/usr/bin/env python3
"""
NamibWay — when the pumps and the shops are open, from OpenStreetMap.

`supply_points` says where a traveller can fill up and buy food; what it could
not say is *when*, and an opening time nobody has checked is worse than none
because the traveller drives on it. This fetches the one source that already
speaks the syntax the column stores — OSM's `opening_hours` — so the import is
a copy rather than a translation.

One request for the whole country, not one per town. Overpass is somebody
else's server and the interesting object count here is small: every filling
station, supermarket and general dealer OSM knows about in Namibia comes back
in a single query, and the matching against our own rows happens later, in the
importer, where the coordinates live. That also means this script needs no
database and can run on a CI runner.

  python scripts/scrape_osm_supply_hours.py                    # the whole country
  python scripts/scrape_osm_supply_hours.py --bbox windhoek    # a test run first

The second form is the one to run first, every time — see CLAUDE.md's scraper
discipline. It is bounded to one town, proves the query and the extraction, and
costs the endpoint almost nothing.

Everything the source publishes about an element is kept under `tags`, whether
or not the importer reads it today, so a later decision never means asking
Overpass again.

Licence: OpenStreetMap data is © OpenStreetMap contributors, ODbL. Each record
carries the element it came from (`osm`), which is what makes an entry in our
own table traceable back to its source.
"""

import argparse
import json
import sys
import time
from pathlib import Path

try:
    import requests
except ImportError:  # pragma: no cover - the workflow installs it
    print("Missing dependency. Run: pip install requests")
    sys.exit(1)

DEFAULT_ENDPOINT = "https://overpass-api.de/api/interpreter"
DEFAULT_OUT = Path("data/scraped/osm_supply_hours.json")

# A courteous, identifiable agent: Overpass blocks anonymous hammering, and
# being recognisable is what lets them ask us to slow down rather than ban us.
HEADERS = {
    "User-Agent": "NamibWayBot/1.0 (+https://namibway.com; supply-point opening hours)"
}

# What counts as somewhere to fill up or to stock up. `shop=general` is the
# rural general dealer, which in half the country *is* the supermarket; the
# importer decides what it is willing to use each kind for.
KINDS = {
    "fuel": '["amenity"="fuel"]',
    "supermarket": '["shop"="supermarket"]',
    "convenience": '["shop"="convenience"]',
    "general": '["shop"="general"]',
}

# Bounded runs, so proving the query costs one town rather than one country.
BBOXES = {
    "namibia": (-29.05, 11.50, -16.90, 25.30),
    "windhoek": (-22.75, 16.95, -22.40, 17.20),
    "coast": (-23.10, 14.30, -22.55, 14.65),
    "namib": (-24.70, 15.60, -23.70, 16.30),
}


def build_query(bbox: tuple, timeout: int) -> str:
    south, west, north, east = bbox
    parts = "\n  ".join(
        f'nwr{selector}({south},{west},{north},{east});' for selector in KINDS.values()
    )

    # `out center tags` gives a way or a relation a single point to stand at,
    # which is all the importer needs — it measures distance, it does not draw
    # the forecourt.
    return f"[out:json][timeout:{timeout}];\n(\n  {parts}\n);\nout center tags;"


def fetch(endpoint: str, query: str, retries: int = 3) -> dict:
    for attempt in range(1, retries + 1):
        response = requests.post(
            endpoint, data={"data": query}, headers=HEADERS, timeout=300
        )

        # 429 is "you are asking too often" and 504 is "that query took too
        # long"; both are answered by waiting rather than by trying harder.
        if response.status_code in (429, 504):
            wait = 30 * attempt
            print(f"  [warn] {response.status_code} from Overpass, waiting {wait}s")
            time.sleep(wait)
            continue

        response.raise_for_status()

        return response.json()

    raise SystemExit("Overpass kept refusing; try again later rather than harder.")


def kind_of(tags: dict) -> str:
    if tags.get("amenity") == "fuel":
        return "fuel"

    return {"supermarket": "supermarket", "convenience": "convenience"}.get(
        tags.get("shop", ""), "general"
    )


def record(element: dict) -> dict:
    tags = element.get("tags", {})
    center = element.get("center", {})

    return {
        "osm": f"{element['type']}/{element['id']}",
        "kind": kind_of(tags),
        "name": tags.get("name") or tags.get("brand") or tags.get("operator"),
        "brand": tags.get("brand") or tags.get("operator"),
        "lat": element.get("lat", center.get("lat")),
        "lng": element.get("lon", center.get("lon")),
        "opening_hours": tags.get("opening_hours"),
        # Kept whole on purpose: fuel:diesel, payment:*, the survey date. None
        # of it is read today and all of it is free to keep now.
        "tags": tags,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--bbox",
        default="namibia",
        choices=sorted(BBOXES),
        help="Area to query. Run a small one first — see the module docstring.",
    )
    parser.add_argument("--endpoint", default=DEFAULT_ENDPOINT)
    parser.add_argument("--timeout", type=int, default=300)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args()

    query = build_query(BBOXES[args.bbox], args.timeout)

    print(f"Querying {args.endpoint} for '{args.bbox}'…")
    print(query)

    payload = fetch(args.endpoint, query)
    elements = payload.get("elements", [])

    records = [record(element) for element in elements]
    records = [r for r in records if r["lat"] is not None and r["lng"] is not None]
    records.sort(key=lambda r: (r["kind"], r["name"] or "", r["osm"]))

    counts = {kind: 0 for kind in KINDS}
    for entry in records:
        counts[entry["kind"]] = counts.get(entry["kind"], 0) + 1

    with_hours = sum(1 for entry in records if entry["opening_hours"])

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(
        json.dumps(
            {
                "scraped_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "area": args.bbox,
                "endpoint": args.endpoint,
                "query": query,
                "attribution": "© OpenStreetMap contributors, ODbL",
                "counts": {**counts, "total": len(records), "with_hours": with_hours},
                "elements": records,
            },
            indent=2,
            ensure_ascii=False,
        )
        + "\n",
        encoding="utf-8",
    )

    print(f"\nWrote {len(records)} elements to {args.out}")
    for kind, count in counts.items():
        print(f"  {kind:12}: {count}")
    print(f"  {'with hours':12}: {with_hours}")

    # The number that decides whether this was worth running: an element
    # without opening_hours is a place we already knew about.
    if with_hours == 0:
        print("\nNothing here carries opening hours — the importer would write nothing.")


if __name__ == "__main__":
    main()
