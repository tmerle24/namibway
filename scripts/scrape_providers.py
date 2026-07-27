#!/usr/bin/env python3
"""
NamibWay — Provider List Scraper
Scrapes public tourism directories to build an initial list of Namibia providers.

Sources:
  - Safari Bookings (safaribookings.com) — lodges/camps
  - TripAdvisor Namibia               — restaurants & activities
  - Google Places API                 — broad coverage (optional, needs API key)

Output: scripts/scraped_providers.json
        scripts/scraped_providers.csv

Usage:
  pip install requests beautifulsoup4 lxml
  python scripts/scrape_providers.py
  python scripts/scrape_providers.py --source google --api-key YOUR_KEY
"""

import json
import csv
import time
import re
import argparse
import sys
from pathlib import Path
from urllib.parse import urljoin

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("Missing dependencies. Run: pip install requests beautifulsoup4 lxml")
    sys.exit(1)

OUTPUT_DIR = Path(__file__).parent
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; NamibWayBot/1.0; "
        "building public tourism directory — namibway.com)"
    )
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def get(url: str, retries: int = 3, delay: float = 2.0) -> requests.Response | None:
    for attempt in range(retries):
        try:
            resp = requests.get(url, headers=HEADERS, timeout=20)
            resp.raise_for_status()
            return resp
        except requests.RequestException as e:
            print(f"  [warn] {url} → {e} (attempt {attempt + 1}/{retries})")
            time.sleep(delay * (attempt + 1))
    return None


def slug_from(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")


def dedupe(records: list[dict]) -> list[dict]:
    seen = set()
    out = []
    for r in records:
        key = slug_from(r.get("name", ""))
        if key and key not in seen:
            seen.add(key)
            out.append(r)
    return out


# ---------------------------------------------------------------------------
# Safari Bookings scraper
# ---------------------------------------------------------------------------

SAFARI_BOOKINGS_BASE = "https://www.safaribookings.com"

SAFARI_BOOKINGS_NAMIBIA_REGIONS = [
    "/lodges/namibia",
    "/lodges/namibia/etosha-national-park",
    "/lodges/namibia/sossusvlei",
    "/lodges/namibia/damaraland",
    "/lodges/namibia/skeleton-coast",
    "/lodges/namibia/caprivi-strip",
    "/lodges/namibia/fish-river-canyon",
    "/lodges/namibia/swakopmund",
    "/lodges/namibia/windhoek",
    "/lodges/namibia/namib-naukluft",
    "/lodges/namibia/kaokoveld",
]


def scrape_safaribookings_page(path: str) -> list[dict]:
    url = SAFARI_BOOKINGS_BASE + path
    print(f"  Fetching: {url}")
    resp = get(url)
    if not resp:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    records = []

    # Safari Bookings lodge card selectors — update if site structure changes
    # Each lodge is typically in a div with class containing "lodge-item" or similar
    for card in soup.select(".lodge-item, .property-card, [data-testid='lodge-card'], .search-result"):
        try:
            name_el = card.select_one("h2, h3, .lodge-name, .property-name")
            if not name_el:
                continue
            name = name_el.get_text(strip=True)
            if not name:
                continue

            link_el = card.select_one("a[href]")
            detail_url = urljoin(SAFARI_BOOKINGS_BASE, link_el["href"]) if link_el else None

            region_el = card.select_one(".location, .region, .area")
            region = region_el.get_text(strip=True) if region_el else None

            stars_el = card.select_one("[class*='star'], [class*='rating']")
            stars = stars_el.get_text(strip=True) if stars_el else None

            desc_el = card.select_one(".description, .summary, p")
            description = desc_el.get_text(strip=True) if desc_el else None

            records.append({
                "name": name,
                "type": "accommodation",
                "region": region,
                "stars": stars,
                "description": description,
                "source_url": detail_url or url,
                "website": None,
                "contact_email": None,
            })
        except Exception as e:
            print(f"  [warn] card parse error: {e}")
            continue

    return records


def scrape_lodge_detail(record: dict) -> dict:
    """Fetch the lodge's own website URL from its Safari Bookings detail page."""
    if not record.get("source_url"):
        return record

    resp = get(record["source_url"])
    if not resp:
        return record

    soup = BeautifulSoup(resp.text, "lxml")

    # Look for external website link
    for a in soup.select("a[href]"):
        href = a["href"]
        if (
            href.startswith("http")
            and "safaribookings.com" not in href
            and not record.get("website")
        ):
            record["website"] = href
            break

    # Look for contact email
    email_match = re.search(r"[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}", resp.text)
    if email_match and not record.get("contact_email"):
        record["contact_email"] = email_match.group(0)

    time.sleep(1)  # polite crawl delay
    return record


def scrape_safaribookings(fetch_details: bool = False) -> list[dict]:
    print("\n=== Safari Bookings ===")
    all_records: list[dict] = []

    for path in SAFARI_BOOKINGS_NAMIBIA_REGIONS:
        records = scrape_safaribookings_page(path)
        print(f"  → {len(records)} listings from {path}")
        all_records.extend(records)
        time.sleep(2)

    all_records = dedupe(all_records)
    print(f"  Total (deduped): {len(all_records)}")

    if fetch_details:
        print("  Fetching detail pages for website URLs...")
        for i, record in enumerate(all_records):
            print(f"  [{i+1}/{len(all_records)}] {record['name']}")
            all_records[i] = scrape_lodge_detail(record)

    return all_records


# ---------------------------------------------------------------------------
# TripAdvisor scraper (restaurants + activities)
# ---------------------------------------------------------------------------

TRIPADVISOR_NAMIBIA_URLS = {
    "restaurant": [
        "https://www.tripadvisor.com/Restaurants-g293821-Namibia.html",
        "https://www.tripadvisor.com/Restaurants-g293830-Windhoek_Khomas_Region.html",
        "https://www.tripadvisor.com/Restaurants-g293824-Swakopmund_Erongo_Region.html",
    ],
    "activity": [
        "https://www.tripadvisor.com/Attractions-g293821-Activities-Namibia.html",
    ],
}


def scrape_tripadvisor_page(url: str, listing_type: str) -> list[dict]:
    print(f"  Fetching: {url}")
    resp = get(url)
    if not resp:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    records = []

    # TripAdvisor card selectors — fragile, update as needed
    for card in soup.select(
        "[data-automation='WebPresentation_SingleFlexCardSection'], "
        ".listing_title, .result-title, "
        "[class*='listing']"
    ):
        name_el = card.select_one("a, h2, h3, .title")
        if not name_el:
            continue
        name = name_el.get_text(strip=True)
        if not name or len(name) < 3:
            continue

        link_el = card.select_one("a[href]")
        source_url = urljoin("https://www.tripadvisor.com", link_el["href"]) if link_el else url

        loc_el = card.select_one(".geo, .location-name, [class*='location']")
        region = loc_el.get_text(strip=True) if loc_el else None

        records.append({
            "name": name,
            "type": listing_type,
            "region": region,
            "stars": None,
            "description": None,
            "source_url": source_url,
            "website": None,
            "contact_email": None,
        })

    return records


def scrape_tripadvisor() -> list[dict]:
    print("\n=== TripAdvisor ===")
    all_records: list[dict] = []

    for listing_type, urls in TRIPADVISOR_NAMIBIA_URLS.items():
        for url in urls:
            records = scrape_tripadvisor_page(url, listing_type)
            print(f"  → {len(records)} {listing_type}s from {url}")
            all_records.extend(records)
            time.sleep(3)

    all_records = dedupe(all_records)
    print(f"  Total (deduped): {len(all_records)}")
    return all_records


# ---------------------------------------------------------------------------
# Google Places API (optional)
# ---------------------------------------------------------------------------

GOOGLE_PLACES_BASE = "https://maps.googleapis.com/maps/api"

GOOGLE_PLACES_QUERIES = [
    ("lodge namibia", "accommodation"),
    ("camp namibia", "accommodation"),
    ("guesthouse namibia", "accommodation"),
    ("hotel windhoek", "accommodation"),
    ("restaurant windhoek namibia", "restaurant"),
    ("restaurant swakopmund namibia", "restaurant"),
    ("car rental namibia", "car_rental"),
    ("safari tour namibia", "activity"),
    ("adventure activity namibia", "activity"),
]


def scrape_google_places(api_key: str) -> list[dict]:
    print("\n=== Google Places API ===")
    all_records: list[dict] = []

    for query, listing_type in GOOGLE_PLACES_QUERIES:
        url = f"{GOOGLE_PLACES_BASE}/place/textsearch/json"
        params = {"query": query, "key": api_key, "region": "na"}

        print(f"  Query: {query!r}")
        while True:
            resp = requests.get(url, params=params, timeout=20)
            data = resp.json()

            if data.get("status") not in ("OK", "ZERO_RESULTS"):
                print(f"  [warn] API status: {data.get('status')}")
                break

            for place in data.get("results", []):
                all_records.append({
                    "name": place.get("name"),
                    "type": listing_type,
                    "region": place.get("formatted_address"),
                    "stars": place.get("rating"),
                    "description": None,
                    "source_url": (
                        f"https://maps.google.com/?q=place_id:{place['place_id']}"
                    ),
                    "website": None,
                    "contact_email": None,
                    "google_place_id": place.get("place_id"),
                    "latitude": place.get("geometry", {}).get("location", {}).get("lat"),
                    "longitude": place.get("geometry", {}).get("location", {}).get("lng"),
                })

            next_page = data.get("next_page_token")
            if not next_page:
                break
            time.sleep(2)
            params = {"pagetoken": next_page, "key": api_key}

        time.sleep(1)

    all_records = dedupe(all_records)
    print(f"  Total (deduped): {len(all_records)}")
    return all_records


# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

def save(records: list[dict]) -> None:
    json_path = OUTPUT_DIR / "scraped_providers.json"
    csv_path = OUTPUT_DIR / "scraped_providers.csv"

    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(records, f, ensure_ascii=False, indent=2)
    print(f"\nSaved JSON → {json_path} ({len(records)} records)")

    if records:
        fields = list(records[0].keys())
        with open(csv_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(records)
        print(f"Saved CSV  → {csv_path}")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="NamibWay provider list scraper")
    parser.add_argument(
        "--source",
        choices=["safaribookings", "tripadvisor", "google", "all"],
        default="all",
    )
    parser.add_argument("--api-key", help="Google Places API key (required for --source google)")
    parser.add_argument(
        "--fetch-details",
        action="store_true",
        help="Also fetch Safari Bookings detail pages to extract partner websites",
    )
    args = parser.parse_args()

    all_records: list[dict] = []

    if args.source in ("safaribookings", "all"):
        all_records.extend(scrape_safaribookings(fetch_details=args.fetch_details))

    if args.source in ("tripadvisor", "all"):
        all_records.extend(scrape_tripadvisor())

    if args.source in ("google", "all"):
        if not args.api_key:
            print("[skip] Google Places requires --api-key")
        else:
            all_records.extend(scrape_google_places(args.api_key))

    all_records = dedupe(all_records)
    print(f"\nGrand total (deduped): {len(all_records)}")
    save(all_records)


if __name__ == "__main__":
    main()
