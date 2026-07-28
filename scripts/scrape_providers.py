#!/usr/bin/env python3
"""
NamibWay — Provider List Scraper

Primary source:
  - visitnamibia.com.na  (Official Namibia Tourism Board directory)

Secondary sources:
  - safaribookings.com   (lodges/camps, accommodation focus)
  - tripadvisor.com      (restaurants & activities)
  - Google Places API    (broad coverage, optional — needs API key)

Output:
  scripts/scraped_providers.json
  scripts/scraped_providers.csv

Usage:
  pip install requests beautifulsoup4 lxml
  python scripts/scrape_providers.py
  python scripts/scrape_providers.py --source visitnamibia
  python scripts/scrape_providers.py --source google --api-key YOUR_KEY
"""

import json
import csv
import time
import re
import argparse
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from urllib.parse import urljoin, urlparse

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
        "building public tourism directory - namibway.com)"
    )
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def get(url: str, retries: int = 3, delay: float = 2.0) -> "requests.Response | None":
    for attempt in range(retries):
        try:
            resp = requests.get(url, headers=HEADERS, timeout=10)
            resp.raise_for_status()
            return resp
        except requests.RequestException as e:
            print(f"  [warn] {url} → {e} (attempt {attempt + 1}/{retries})")
            time.sleep(delay * (attempt + 1))
    return None


def slug_from(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")


def dedupe(records: list) -> list:
    seen: set = set()
    out = []
    for r in records:
        key = slug_from(r.get("name", ""))
        if key and key not in seen:
            seen.add(key)
            out.append(r)
    return out


def extract_email(text: str) -> "str | None":
    m = re.search(r"[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}", text)
    return m.group(0) if m else None


# ---------------------------------------------------------------------------
# visitnamibia.com.na — Official NTB directory (PRIMARY SOURCE)
# ---------------------------------------------------------------------------

VISITNAMIBIA_BASE = "https://visitnamibia.com.na"

# Category slugs from the /business-directory-search-by-category/ page.
# These map to the URL pattern /business-directory/?s=&cat=N or similar.
# Run with --source visitnamibia first, then inspect the category page if
# the selectors below need updating.
VISITNAMIBIA_LISTING_URLS = [
    "/all-listings/",
    "/business-directory/",
]

# Ordered priority list: first match wins.
# Car rental checked before "tour" so "Rental and Tours" → car_rental, not activity.
VISITNAMIBIA_TYPE_HINTS: list[tuple[list[str], str]] = [
    (
        ["car rental", "auto rental", "vehicle rental", "car hire", "self drive",
         "4x4 rental", "4wd rental"],
        "car_rental",
    ),
    (
        ["lodge", "hotel", "camp", "resort", "guesthouse", "guest house",
         "bed and breakfast", "b&b", "self catering", "self-catering",
         "apartment", "chalet", "tented camp", "rest camp", "accommodation",
         "backpacker", "hostel", "villa", "cottage"],
        "accommodation",
    ),
    (
        ["restaurant", "café", "cafe", "bistro", "eatery", "diner", "kitchen",
         "steakhouse", "spur", "pizza", "takeaway"],
        "restaurant",
    ),
    (
        ["tour", "safari", "hunting", "transfer", "shuttle", "excursion",
         "adventure", "activity", "travel", "expedition", "quad", "skydive",
         "sandboard", "hiking", "canopy", "kayak", "balloon"],
        "activity",
    ),
]


def resolve_ntb_type(text: str) -> str:
    """Resolve type from any text (category label or listing name)."""
    lower = text.lower()
    for keywords, t in VISITNAMIBIA_TYPE_HINTS:
        if any(kw in lower for kw in keywords):
            return t
    return "other"


def scrape_visitnamibia_page(url: str) -> list:
    """
    Scrape one page of the NTB directory.

    visitnamibia.com.na appears to be a WordPress site with a directory
    plugin (likely GeoDirectory or a custom post type). The selectors below
    cover the most common structures; update them if the site changes.
    """
    print(f"  Fetching: {url}")
    resp = get(url)
    if not resp:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    records = []

    # --- Try GeoDirectory card selectors first ---
    cards = soup.select(
        ".geodir-category-listing, .gd-listing-item, .geodir-post, "
        ".listing-item, .business-item, .directory-item, "
        "article.type-gd_place, article.type-listing"
    )

    # --- Fallback: WordPress generic article cards ---
    if not cards:
        cards = soup.select("article.post, article.type-post, .entry")

    # --- Last-resort: look for any card-like div with a heading + link ---
    if not cards:
        cards = soup.select(".card, .item, [class*='listing']")

    for card in cards:
        try:
            name_el = card.select_one(
                "h2.entry-title a, h3.entry-title a, "
                ".geodir-post-title a, .gd-post-title a, "
                ".listing-title a, .business-name a, "
                "h2 a, h3 a, h4 a"
            )
            if not name_el:
                continue

            name = name_el.get_text(strip=True)
            if not name or len(name) < 2:
                continue

            detail_href = name_el.get("href", "")
            detail_url = urljoin(VISITNAMIBIA_BASE, detail_href) if detail_href else url

            # Category / type — explicit GeoDirectory selectors only (no wildcards),
            # then always also check the name so the heuristic runs even when the
            # card has a category element with unhelpful text.
            cat_el = card.select_one(
                ".geodir-category, .gd-category, "
                ".listing-category, .business-category"
            )
            raw_type = cat_el.get_text(strip=True) if cat_el else ""
            listing_type = resolve_ntb_type(f"{raw_type} {name}")

            # Region / location
            region_el = card.select_one(
                ".geodir-post-meta-field-address, .gd-location, "
                ".listing-location, .business-location, "
                "[class*='location'], [class*='address'], [class*='region']"
            )
            region = region_el.get_text(strip=True) if region_el else None

            # Description snippet
            desc_el = card.select_one(
                ".geodir-post-excerpt, .entry-summary, "
                ".listing-description, p.description, p"
            )
            description = desc_el.get_text(strip=True) if desc_el else None
            if description and len(description) > 500:
                description = description[:500]

            # Website & email from card text (often not present until detail page)
            website_el = card.select_one("a[href^='http']:not([href*='visitnamibia'])")
            website = website_el["href"] if website_el else None
            contact_email = extract_email(card.get_text())

            records.append({
                "name": name,
                "type": listing_type,
                "region": region,
                "description": description,
                "source_url": detail_url,
                "website": website,
                "contact_email": contact_email,
                "latitude": None,
                "longitude": None,
            })

        except Exception as e:
            print(f"  [warn] card parse error: {e}")
            continue

    return records


def scrape_visitnamibia_detail(record: dict) -> dict:
    """Fetch the NTB detail page to extract website, email, and coordinates."""
    url = record.get("source_url", "")
    if not url or VISITNAMIBIA_BASE not in url:
        return record

    resp = get(url)
    if not resp:
        return record

    soup = BeautifulSoup(resp.text, "lxml")
    text = soup.get_text()

    if not record.get("website"):
        for a in soup.select("a[href^='http']"):
            href = a["href"]
            domain = urlparse(href).netloc
            if "visitnamibia" not in domain and "facebook" not in domain and "instagram" not in domain:
                record["website"] = href
                break

    if not record.get("contact_email"):
        record["contact_email"] = extract_email(text)

    # GeoDirectory embeds lat/lng in data attributes or a map script
    map_el = soup.select_one("[data-lat], [data-lng]")
    if map_el:
        record["latitude"] = map_el.get("data-lat")
        record["longitude"] = map_el.get("data-lng")

    # Fallback: JSON-LD schema
    for script in soup.select("script[type='application/ld+json']"):
        try:
            data = json.loads(script.string or "")
            geo = data.get("geo", {})
            if geo.get("latitude"):
                record["latitude"] = geo["latitude"]
                record["longitude"] = geo.get("longitude")
        except (json.JSONDecodeError, AttributeError):
            pass

    time.sleep(0.3)
    return record


def scrape_visitnamibia_categories() -> list:
    """
    Fetch the category index page and scrape each category separately
    to get better type classification.
    """
    url = f"{VISITNAMIBIA_BASE}/business-directory-search-by-category/"
    print(f"  Fetching category index: {url}")
    resp = get(url)
    if not resp:
        return []

    soup = BeautifulSoup(resp.text, "lxml")

    # Collect category links
    cat_links = []
    for a in soup.select("a[href*='business-directory'], a[href*='category'], a[href*='cat=']"):
        href = a["href"]
        if href and href not in cat_links and VISITNAMIBIA_BASE in href:
            cat_links.append(href)

    print(f"  Found {len(cat_links)} category links")
    all_records = []

    for cat_url in cat_links:
        page = 1
        while True:
            paged_url = cat_url if page == 1 else f"{cat_url.rstrip('/')}/page/{page}/"
            records = scrape_visitnamibia_page(paged_url)
            if not records:
                break
            all_records.extend(records)
            page += 1
            time.sleep(2)

    return all_records


def scrape_visitnamibia(
    fetch_details: bool = False,
    detail_workers: int = 4,
    detail_batch_size: int = 0,
    detail_batch_offset: int = 0,
) -> list:
    print("\n=== visitnamibia.com.na (NTB Official Directory) ===")
    all_records: list = []

    # 1. Paginate through /all-listings/ and /business-directory/
    for path in VISITNAMIBIA_LISTING_URLS:
        page = 1
        while True:
            paged_url = (
                f"{VISITNAMIBIA_BASE}{path}"
                if page == 1
                else f"{VISITNAMIBIA_BASE}{path}page/{page}/"
            )
            records = scrape_visitnamibia_page(paged_url)
            if not records:
                break
            print(f"  → {len(records)} listings (page {page}) from {path}")
            all_records.extend(records)
            page += 1
            time.sleep(2)

    all_records = dedupe(all_records)
    print(f"  Total (deduped): {len(all_records)}")

    if fetch_details:
        # Apply batch slicing if requested
        start = detail_batch_offset
        end = (detail_batch_offset + detail_batch_size) if detail_batch_size else len(all_records)
        batch = all_records[start:end]
        print(
            f"  Fetching detail pages [{start}–{min(end, len(all_records))}]"
            f" of {len(all_records)} ({detail_workers} workers)…"
        )

        def enrich(args: tuple) -> tuple[int, dict]:
            idx, record = args
            return idx, scrape_visitnamibia_detail(record)

        with ThreadPoolExecutor(max_workers=detail_workers) as executor:
            futures = {
                executor.submit(enrich, (start + i, rec)): start + i
                for i, rec in enumerate(batch)
            }
            done = 0
            for future in as_completed(futures):
                idx, enriched = future.result()
                all_records[idx] = enriched
                done += 1
                if done % 100 == 0 or done == len(batch):
                    print(f"  [{done}/{len(batch)}] detail pages enriched")

    return all_records


# ---------------------------------------------------------------------------
# Safari Bookings (secondary — accommodation focus)
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


def scrape_safaribookings_page(path: str) -> list:
    url = SAFARI_BOOKINGS_BASE + path
    print(f"  Fetching: {url}")
    resp = get(url)
    if not resp:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    records = []

    for card in soup.select(
        ".lodge-item, .property-card, "
        "[data-testid='lodge-card'], .search-result"
    ):
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

            desc_el = card.select_one(".description, .summary, p")
            description = desc_el.get_text(strip=True) if desc_el else None

            records.append({
                "name": name,
                "type": "accommodation",
                "region": region,
                "description": description,
                "source_url": detail_url or url,
                "website": None,
                "contact_email": None,
                "latitude": None,
                "longitude": None,
            })
        except Exception as e:
            print(f"  [warn] card parse error: {e}")
            continue

    return records


def scrape_safaribookings(fetch_details: bool = False) -> list:
    print("\n=== Safari Bookings ===")
    all_records: list = []

    for path in SAFARI_BOOKINGS_NAMIBIA_REGIONS:
        records = scrape_safaribookings_page(path)
        print(f"  → {len(records)} listings from {path}")
        all_records.extend(records)
        time.sleep(2)

    all_records = dedupe(all_records)
    print(f"  Total (deduped): {len(all_records)}")

    if fetch_details:
        print("  Fetching detail pages…")
        for i, record in enumerate(all_records):
            print(f"  [{i + 1}/{len(all_records)}] {record['name']}")
            resp = get(record["source_url"])
            if resp:
                for a in BeautifulSoup(resp.text, "lxml").select("a[href]"):
                    href = a["href"]
                    if href.startswith("http") and "safaribookings.com" not in href:
                        all_records[i]["website"] = href
                        break
                all_records[i]["contact_email"] = extract_email(resp.text)
            time.sleep(1)

    return all_records


# ---------------------------------------------------------------------------
# TripAdvisor (secondary — restaurants & activities)
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


def scrape_tripadvisor_page(url: str, listing_type: str) -> list:
    print(f"  Fetching: {url}")
    resp = get(url)
    if not resp:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    records = []

    for card in soup.select(
        "[data-automation='WebPresentation_SingleFlexCardSection'], "
        ".listing_title, .result-title, [class*='listing']"
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
            "description": None,
            "source_url": source_url,
            "website": None,
            "contact_email": None,
            "latitude": None,
            "longitude": None,
        })

    return records


def scrape_tripadvisor() -> list:
    print("\n=== TripAdvisor ===")
    all_records: list = []

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


def scrape_google_places(api_key: str) -> list:
    print("\n=== Google Places API ===")
    all_records: list = []

    for query, listing_type in GOOGLE_PLACES_QUERIES:
        url = f"{GOOGLE_PLACES_BASE}/place/textsearch/json"
        params: dict = {"query": query, "key": api_key, "region": "na"}

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
                    "description": None,
                    "source_url": f"https://maps.google.com/?q=place_id:{place['place_id']}",
                    "website": None,
                    "contact_email": None,
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

def save(records: list) -> None:
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
        choices=["visitnamibia", "safaribookings", "tripadvisor", "google", "all"],
        default="all",
        help="Which source to scrape (default: all)",
    )
    parser.add_argument(
        "--api-key",
        help="Google Places API key (required for --source google)",
    )
    parser.add_argument(
        "--fetch-details",
        action="store_true",
        help="Also fetch individual detail pages (slower but extracts websites/emails)",
    )
    parser.add_argument(
        "--detail-workers",
        type=int,
        default=4,
        help="Parallel workers for detail page fetching (default: 4)",
    )
    parser.add_argument(
        "--detail-batch-size",
        type=int,
        default=0,
        help="Max records to detail-fetch per run (0 = all, for splitting across runs)",
    )
    parser.add_argument(
        "--detail-batch-offset",
        type=int,
        default=0,
        help="Skip this many records before starting detail fetch (for splitting across runs)",
    )
    args = parser.parse_args()

    all_records: list = []

    if args.source in ("visitnamibia", "all"):
        all_records.extend(
            scrape_visitnamibia(
                fetch_details=args.fetch_details,
                detail_workers=args.detail_workers,
                detail_batch_size=args.detail_batch_size,
                detail_batch_offset=args.detail_batch_offset,
            )
        )

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
