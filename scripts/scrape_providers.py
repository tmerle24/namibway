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

# Domains to skip when extracting provider websites from detail pages
_SKIP_DOMAINS = {
    "visitnamibia.com.na", "facebook.com", "instagram.com",
    "twitter.com", "x.com", "youtube.com", "linkedin.com",
    "tiktok.com", "pinterest.com", "whatsapp.com",
}

# Category slugs from the /business-directory-search-by-category/ page.
# These map to the URL pattern /business-directory/?s=&cat=N or similar.
# Run with --source visitnamibia first, then inspect the category page if
# the selectors below need updating.
# Category URL: /search-result/?in_cat=N
# in_cat=47 = Accommodation (confirmed from NTB site).
# Other IDs are discovered at runtime from /business-directory-search-by-category/.
# We map NTB category names → our internal types; unrecognised categories → "other".
VISITNAMIBIA_CATEGORY_TYPE_MAP: dict[str, str] = {
    # Accommodation
    "accommodation": "accommodation",
    "hotels": "accommodation",
    "lodges": "accommodation",
    "guesthouses": "accommodation",
    "guest houses": "accommodation",
    "bed and breakfast": "accommodation",
    "self catering": "accommodation",
    "self-catering": "accommodation",
    "backpackers": "accommodation",
    "camping": "accommodation",
    "campsites": "accommodation",
    "tented camps": "accommodation",
    "rest camps": "accommodation",
    "resorts": "accommodation",
    # Activities / Tours
    "tours": "activity",
    "safaris": "activity",
    "hunting": "activity",
    "activities": "activity",
    "adventure": "activity",
    "excursions": "activity",
    "transfers": "activity",
    "shuttles": "activity",
    "travel agents": "activity",
    "tour operators": "activity",
    # Restaurants / Food
    "restaurants": "restaurant",
    "food and beverage": "restaurant",
    "catering": "restaurant",
    # Car rental
    "car rental": "car_rental",
    "car hire": "car_rental",
    "vehicle rental": "car_rental",
    "4x4 rental": "car_rental",
}

# Ordered keyword fallback for names when category label is unrecognised.
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


def _parse_search_result_cards(soup: "BeautifulSoup", forced_type: "str | None" = None) -> list:
    """Parse listing cards from a Directorist (visitnamibia.com.na) search-result page."""
    # Try selectors from most-specific to least-specific
    selectors = [
        # Directorist listing cards (confirmed live markup, 2026-07)
        "article.directorist-listing-card",
        "article[class*='directorist-listing']",
        # Legacy GeoDirectory post type articles — kept in case NTB reverts/migrates again
        "article.type-gd_place",
        "article.gd_place",
        "article[class*='gd_place']",
        "article[class*='type-gd_place']",
        ".geodir-category-listing",
        ".gd-listing-item",
        ".gd-listings-item",
        ".geodir-post",
        # Generic WordPress listing
        ".listing-item",
        ".business-item",
        ".directory-item",
        # Broader fallback
        "article.post",
        "article.type-post",
        "article[class*='listing']",
        # Last resort
        "article",
    ]
    cards = []
    matched_selector = None
    for sel in selectors:
        candidates = soup.select(sel)
        # Filter out articles without a title link (avoids picking up page wrappers)
        candidates = [c for c in candidates if c.select_one("a[href]")]
        if candidates:
            cards = candidates
            matched_selector = sel
            break

    if not cards:
        # Debug dump so we can identify the right selector from the log
        print("  [debug] No listing cards found. First 8 article/div elements on page:")
        for el in soup.select("article, .post, div[class*='listing'], div[class*='gd-']")[:8]:
            classes = " ".join(el.get("class", []))
            print(f"    <{el.name} class='{classes}'>")
        return []

    if matched_selector not in ("article.directorist-listing-card", "article.type-gd_place", "article.gd_place"):
        print(f"  [debug] Cards matched via fallback selector: {matched_selector!r}")

    records = []
    for card in cards:
        try:
            name_el = card.select_one(
                ".directorist-listing-title a, "
                "h2.entry-title a, h3.entry-title a, "
                ".geodir-post-title a, .gd-post-title a, "
                "h2 a, h3 a, h4 a"
            )
            if not name_el:
                continue
            name = name_el.get_text(strip=True)
            if not name or len(name) < 2:
                continue

            detail_url = urljoin(VISITNAMIBIA_BASE, name_el.get("href", ""))

            # Type: use forced_type (from category) or fall back to name heuristic
            if forced_type:
                listing_type = forced_type
            else:
                cat_el = card.select_one(
                    ".directorist-listing-category, "
                    ".geodir-category, .gd-category, .listing-category"
                )
                raw = cat_el.get_text(strip=True) if cat_el else ""
                listing_type = resolve_ntb_type(f"{raw} {name}")

            region_el = card.select_one(
                ".directorist-listing-card-location, "
                ".geodir-post-meta-field-address, .gd-location, "
                ".listing-location, [class*='location'], [class*='address']"
            )
            region = region_el.get_text(strip=True) if region_el else None

            desc_el = card.select_one(
                ".directorist-listing-card-content, "
                ".geodir-post-excerpt, .entry-summary, .listing-description, p"
            )
            description = desc_el.get_text(strip=True) if desc_el else None
            if description and len(description) > 500:
                description = description[:500]

            # Directorist cards render phone/email inline as <li class="directorist-listing-card-phone|email">
            # — grabbing them here avoids depending on the (slower, one-request-per-listing) detail-page fetch.
            phone_el = card.select_one(".directorist-listing-card-phone a[href^='tel:']")
            phone = phone_el["href"][4:].strip() if phone_el else None

            email_el = card.select_one(".directorist-listing-card-email a[href^='mailto:']")
            if email_el:
                contact_email = email_el["href"][7:].strip()
            else:
                _card_email = extract_email(card.get_text())
                contact_email = (
                    _card_email
                    if _card_email and "visitnamibia.com.na" not in _card_email.lower()
                    else None
                )

            records.append({
                "name": name,
                "type": listing_type,
                "region": region,
                "description": description,
                "source_url": detail_url,
                "website": None,
                "email": contact_email,
                "phone": phone,
                "latitude": None,
                "longitude": None,
            })
        except Exception as e:
            print(f"  [warn] card parse error: {e}")
    return records


def scrape_visitnamibia_detail(record: dict) -> dict:
    """Fetch a detail page and extract website, email, phone and coordinates."""
    url = record.get("source_url", "")
    if not url or VISITNAMIBIA_BASE not in url:
        return record

    resp = get(url)
    if not resp:
        return record

    soup = BeautifulSoup(resp.text, "lxml")
    content = (
        soup.select_one(
            # Directorist single-listing wrapper (confirmed live markup, 2026-07)
            ".directorist-listing-details, "
            # Legacy GeoDirectory — kept in case NTB migrates plugins again
            "article.gd_place, article.type-gd_place, "
            ".geodir-single-main, .geodir-post-body, "
            ".geodir-listing-content, .entry-content, main"
        )
        or soup
    )

    # Website — dedicated Directorist/GeoDirectory field only; generic link
    # scraping always picks up NTB's own social/nav junk scattered on the page.
    if not record.get("website"):
        for sel in (".directorist-single-info-website a", "[class*='single-info-website'] a",
                    ".geodir-field-website a", ".gd-field-website a",
                    "[class*='field-website'] a", "[class*='field_website'] a"):
            el = content.select_one(sel)
            if el:
                href = el.get("href", "")
                domain = urlparse(href).netloc.lower().removeprefix("www.")
                if not any(domain == s or domain.endswith("." + s) for s in _SKIP_DOMAINS):
                    record["website"] = href
                    break

    # Email
    if not record.get("email"):
        for sel in (".directorist-single-info-email a", "[class*='single-info-email'] a",
                    ".geodir-field-email a", ".gd-field-email a",
                    "[class*='field-email'] a", "[href^='mailto:']"):
            el = content.select_one(sel)
            if el:
                href = el.get("href", "")
                email = href[7:] if href.startswith("mailto:") else el.get_text(strip=True)
                if email and "visitnamibia.com.na" not in email.lower():
                    record["email"] = email
                    break

    # Phone
    if not record.get("phone"):
        for sel in (".directorist-single-info-phone a", "[class*='single-info-phone'] a",
                    ".geodir-field-phone a", "[class*='field-phone'] a",
                    "[href^='tel:']"):
            el = content.select_one(sel)
            if el:
                href = el.get("href", "")
                record["phone"] = href[4:] if href.startswith("tel:") else el.get_text(strip=True)
                break

    # Coordinates — GeoDirectory data attributes or JSON-LD
    if not record.get("latitude"):
        map_el = soup.select_one("[data-lat]")
        if map_el:
            record["latitude"] = map_el.get("data-lat")
            record["longitude"] = map_el.get("data-lng")

    if not record.get("latitude"):
        for script in soup.select("script[type='application/ld+json']"):
            try:
                data = json.loads(script.string or "")
                geo = data.get("geo", {})
                if geo.get("latitude"):
                    record["latitude"] = geo["latitude"]
                    record["longitude"] = geo.get("longitude")
                    break
            except (json.JSONDecodeError, AttributeError):
                pass

    time.sleep(0.3)
    return record


# Known NTB category IDs confirmed from the site.
# Format: (display_name, in_cat_id, internal_type)
# in_cat=47 confirmed by user. Others discovered by probing 1–120.
_NTB_KNOWN_CATEGORIES: list[tuple[str, int, str]] = [
    ("Accommodation", 47, "accommodation"),
]

# Probe this range of category IDs to auto-discover more categories
_NTB_PROBE_CAT_RANGE = range(1, 121)


def _ntb_discover_categories() -> list[tuple[str, str]]:
    """
    Try to discover category IDs by probing the category index page.
    Falls back to _NTB_KNOWN_CATEGORIES if discovery yields nothing.
    """
    index_url = f"{VISITNAMIBIA_BASE}/business-directory-search-by-category/"
    print(f"  Discovering categories from: {index_url}")
    resp = get(index_url)

    cats: list[tuple[str, str]] = []
    seen: set[str] = set()

    if resp:
        soup = BeautifulSoup(resp.text, "lxml")

        # Strategy 1: look for any link with in_cat= or cat= anywhere in href
        for a in soup.select("a[href]"):
            href = a.get("href", "")
            if not href:
                continue
            if "in_cat=" in href or ("cat=" in href and "search-result" in href):
                full = urljoin(VISITNAMIBIA_BASE, href)
                if full not in seen:
                    seen.add(full)
                    name = a.get_text(strip=True)
                    if name:
                        cats.append((name, full))

        # Strategy 2: look for links to GeoDirectory category archive pages
        if not cats:
            for a in soup.select("a[href]"):
                href = a.get("href", "")
                full = urljoin(VISITNAMIBIA_BASE, href)
                parsed = urlparse(full)
                if parsed.netloc and "visitnamibia" not in parsed.netloc:
                    continue
                path = parsed.path
                # GeoDirectory category archive: /gd-category/slug/ or /listing-category/slug/
                if any(seg in path for seg in ["/gd-category/", "/listing-category/", "/category/"]):
                    if full not in seen:
                        seen.add(full)
                        name = a.get_text(strip=True)
                        if name:
                            cats.append((name, full))

        if cats:
            print(f"  Found {len(cats)} categories from index page")
            return cats

        # Debug: print all links on the category page so we can adapt selectors
        print("  [debug] No category links found. All links on category page:")
        for a in soup.select("a[href]")[:40]:
            href = a.get("href", "")
            label = a.get_text(strip=True)
            if href and label:
                print(f"    {label!r:35s} → {href}")

    # Strategy 3: probe known category IDs + range to discover more
    print(f"  Probing category IDs {_NTB_PROBE_CAT_RANGE.start}–{_NTB_PROBE_CAT_RANGE.stop - 1}…")
    search_base = f"{VISITNAMIBIA_BASE}/search-result/?directory_type=listing&q=&in_cat="

    # Start with known IDs
    for name, cat_id, _ in _NTB_KNOWN_CATEGORIES:
        url = f"{search_base}{cat_id}"
        cats.append((name, url))

    # Probe range to find unknown categories
    known_ids = {c[1] for c in _NTB_KNOWN_CATEGORIES}
    for cat_id in _NTB_PROBE_CAT_RANGE:
        if cat_id in known_ids:
            continue
        url = f"{search_base}{cat_id}"
        r = get(url)
        if not r:
            continue
        soup = BeautifulSoup(r.text, "lxml")
        # Check page title or breadcrumb for the category name
        cat_name = None
        for sel in [".geodir-category-title", ".gd-category-name",
                    "h1.page-title", ".archive-title", "h1", "h2.entry-title"]:
            el = soup.select_one(sel)
            if el:
                t = el.get_text(strip=True)
                if t and len(t) < 80 and t not in ("Search Results", "Listings", ""):
                    cat_name = t
                    break
        # Check if there are any listing cards on this page
        has_listings = bool(soup.select(
            "article.type-gd_place, article.gd_place, .gd-listing-item, "
            ".geodir-category-listing, article[class*='gd_place']"
        ))
        if has_listings and cat_id not in known_ids:
            name_str = cat_name or f"Category {cat_id}"
            print(f"    Found: in_cat={cat_id} → {name_str!r}")
            cats.append((name_str, url))
            known_ids.add(cat_id)
        time.sleep(0.5)

    if not cats:
        # Absolute fallback: use hardcoded known categories only
        print("  [warn] Probe found nothing — using hardcoded known categories only")
        for name, cat_id, _ in _NTB_KNOWN_CATEGORIES:
            cats.append((name, f"{search_base}{cat_id}"))

    print(f"  Total categories to scrape: {len(cats)}")
    return cats


def _ntb_paginate_category(base_url: str, forced_type: str) -> list:
    """Paginate through all pages of a single NTB category search URL."""
    records: list = []
    page = 1
    consecutive_empty = 0
    while True:
        paged_url = base_url if page == 1 else f"{base_url}&paged={page}"
        resp = get(paged_url)
        if not resp:
            break
        soup = BeautifulSoup(resp.text, "lxml")
        page_records = _parse_search_result_cards(soup, forced_type=forced_type)
        if not page_records:
            consecutive_empty += 1
            if consecutive_empty >= 2:
                break
        else:
            consecutive_empty = 0
            records.extend(page_records)
            print(f"    page {page}: {len(page_records)} records (running total: {len(records)})")
        page += 1
        time.sleep(1.5)
    return records


def scrape_visitnamibia(
    fetch_details: bool = False,
    detail_workers: int = 8,
    detail_batch_size: int = 0,
    detail_batch_offset: int = 0,
) -> list:
    print("\n=== visitnamibia.com.na (NTB Official Directory) ===")
    all_records: list = []

    # 1. Discover all NTB categories from the category index page
    categories = _ntb_discover_categories()

    if not categories:
        # Fallback: scrape the main directory without category filter
        print("  [warn] No categories discovered — falling back to main directory listing")
        fallback_url = (
            f"{VISITNAMIBIA_BASE}/search-result/?directory_type=listing&q="
        )
        page_records = _ntb_paginate_category(fallback_url, forced_type=None)
        all_records.extend(page_records)
    else:
        # 2. Scrape each category, mapping name → internal type
        for cat_name, cat_url in categories:
            # Normalise category name for lookup
            normalised = cat_name.lower().strip()
            # Try exact match first, then substring
            forced_type = VISITNAMIBIA_CATEGORY_TYPE_MAP.get(normalised)
            if not forced_type:
                for key, val in VISITNAMIBIA_CATEGORY_TYPE_MAP.items():
                    if key in normalised or normalised in key:
                        forced_type = val
                        break
            if not forced_type:
                forced_type = resolve_ntb_type(cat_name)

            print(f"\n  Category: {cat_name!r} → type={forced_type}")
            cat_records = _ntb_paginate_category(cat_url, forced_type=forced_type)
            print(f"  Category total: {len(cat_records)}")
            all_records.extend(cat_records)
            time.sleep(2)

    all_records = dedupe(all_records)
    print(f"\n  Grand total (deduped): {len(all_records)}")

    # Count by type
    from collections import Counter
    type_counts = Counter(r.get("type") for r in all_records)
    for t, n in sorted(type_counts.items()):
        print(f"    {t}: {n}")

    if fetch_details:
        start = detail_batch_offset
        end = (detail_batch_offset + detail_batch_size) if detail_batch_size else len(all_records)
        batch = all_records[start:end]
        print(
            f"\n  Fetching detail pages [{start}–{min(end, len(all_records))}]"
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
                if done % 200 == 0 or done == len(batch):
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
                "email": None,
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
                all_records[i]["email"] = extract_email(resp.text)
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
            "email": None,
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
                    "email": None,
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

RECORD_FIELDS = [
    "name", "type", "region", "description",
    "source_url", "website", "email", "phone",
    "latitude", "longitude",
]


def save(records: list) -> None:
    json_path = OUTPUT_DIR / "scraped_providers.json"
    csv_path = OUTPUT_DIR / "scraped_providers.csv"

    # Ensure all records have all fields (fill missing with None)
    normalised = [{f: r.get(f) for f in RECORD_FIELDS} for r in records]

    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(normalised, f, ensure_ascii=False, indent=2)
    print(f"\nSaved JSON → {json_path} ({len(normalised)} records)")

    if normalised:
        with open(csv_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=RECORD_FIELDS, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(normalised)
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
