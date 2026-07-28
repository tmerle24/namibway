#!/usr/bin/env python3
"""
NamibWay — namibiadirectory.com contact enrichment scraper

Extracts ONLY factual contact data (website, phone, email, address) from
namibiadirectory.com listings — no copyrighted text or images.

The scraped contacts are matched against the NTB base list
(scripts/scraped_providers.json) by name similarity and merged in.

Usage:
  pip install requests beautifulsoup4 lxml
  python scripts/scrape_namibiadirectory.py
  python scripts/scrape_namibiadirectory.py --limit 50          # test run
  python scripts/scrape_namibiadirectory.py --no-merge          # standalone JSON

Output:
  scripts/namibiadirectory_contacts.json   — raw scraped contacts
  scripts/scraped_providers.json           — merged/enriched (unless --no-merge)
"""

import argparse
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from difflib import SequenceMatcher
from pathlib import Path
from urllib.parse import urljoin, urlparse

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("pip install requests beautifulsoup4 lxml")
    sys.exit(1)

OUTPUT_DIR = Path(__file__).parent
BASE = "https://namibiadirectory.com"
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; NamibWayBot/1.0; "
        "building public tourism directory - namibway.com)"
    )
}

# Known category URLs → internal type mapping
CATEGORIES: list[tuple[str, str]] = [
    ("/category-business/accommodation-hospitality", "accommodation"),
    ("/category-business/tour-travel",               "activity"),
    ("/category-business/activities",                "activity"),
    ("/category-business/transport-logistics",       "car_rental"),
]

_SKIP_DOMAINS = {
    "namibiadirectory.com", "facebook.com", "instagram.com",
    "twitter.com", "x.com", "youtube.com", "linkedin.com",
    "tiktok.com", "pinterest.com", "whatsapp.com", "tripadvisor.com",
    "booking.com", "airbnb.com",
}


# ---------------------------------------------------------------------------
# HTTP helper
# ---------------------------------------------------------------------------

def get(url: str, retries: int = 3, delay: float = 1.5) -> "requests.Response | None":
    for attempt in range(retries):
        try:
            r = requests.get(url, headers=HEADERS, timeout=12)
            r.raise_for_status()
            return r
        except requests.RequestException as e:
            print(f"  [warn] {url} → {e} (attempt {attempt + 1}/{retries})")
            time.sleep(delay * (attempt + 1))
    return None


def slug_from(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")


# ---------------------------------------------------------------------------
# Card parsing — listing index pages
# ---------------------------------------------------------------------------

# Ordered from most-specific to most-generic; first selector with results wins
_CARD_SELECTORS = [
    "article.type-business",
    "article.type-post",
    ".listing-item",
    ".business-listing",
    ".business-item",
    ".directory-listing",
    ".hentry",
    "article",
]

_NAME_SELECTORS = [
    "h2.entry-title a",
    "h3.entry-title a",
    "h1.entry-title a",
    ".listing-title a",
    ".business-name a",
    "h2 a", "h3 a", "h4 a",
    "h2", "h3",
]

_EXCERPT_SELECTORS = [
    ".entry-summary",
    ".listing-excerpt",
    ".business-description",
    ".excerpt",
]

# Fields sometimes shown inline on category pages
_PHONE_SELECTORS_INLINE = [
    "a[href^='tel:']",
    ".phone", "[class*='phone']",
]
_EMAIL_SELECTORS_INLINE = [
    "a[href^='mailto:']",
    ".email", "[class*='email']",
]
_WEBSITE_SELECTORS_INLINE = [
    ".website a", "[class*='website'] a",
    ".url a",
]


def _clean_phone(raw: str) -> str:
    return re.sub(r"[^\d+\-\s()]", "", raw).strip()


def _parse_cards(soup: BeautifulSoup, listing_type: str, page_url: str) -> list[dict]:
    cards: list = []
    for sel in _CARD_SELECTORS:
        cards = soup.select(sel)
        if cards:
            break

    if not cards:
        print(f"    [debug] No cards found on {page_url} — dumping available classes:")
        for el in soup.select("article, section, div[class]")[:10]:
            print(f"      <{el.name} class='{' '.join(el.get('class', []))}'>")
        return []

    records: list[dict] = []
    for card in cards:
        try:
            # Name + detail URL
            name_el = None
            for sel in _NAME_SELECTORS:
                name_el = card.select_one(sel)
                if name_el:
                    break
            if not name_el:
                continue
            name = name_el.get_text(strip=True)
            if not name or len(name) < 2:
                continue
            href = name_el.get("href", "") if name_el.name == "a" else ""
            if not href:
                link = card.select_one("a[href]")
                href = link.get("href", "") if link else ""
            detail_url = urljoin(BASE, href) if href else None

            # Inline phone
            phone = None
            for sel in _PHONE_SELECTORS_INLINE:
                el = card.select_one(sel)
                if el:
                    h = el.get("href", "")
                    phone = _clean_phone(h[4:] if h.startswith("tel:") else el.get_text(strip=True))
                    break

            # Inline email
            email = None
            for sel in _EMAIL_SELECTORS_INLINE:
                el = card.select_one(sel)
                if el:
                    h = el.get("href", "")
                    raw = h[7:] if h.startswith("mailto:") else el.get_text(strip=True)
                    if "@" in raw and "namibiadirectory.com" not in raw:
                        email = raw.strip()
                    break

            # Inline website
            website = None
            for sel in _WEBSITE_SELECTORS_INLINE:
                el = card.select_one(sel)
                if el:
                    h = el.get("href", "")
                    domain = urlparse(h).netloc.lower().removeprefix("www.")
                    if h and not any(domain == s or domain.endswith("." + s) for s in _SKIP_DOMAINS):
                        website = h
                    break

            records.append({
                "name":        name,
                "type":        listing_type,
                "detail_url":  detail_url,
                "phone":       phone,
                "email":       email,
                "website":     website,
                "address":     None,
                "region":      None,
            })
        except Exception as e:
            print(f"    [warn] card parse error: {e}")

    return records


# ---------------------------------------------------------------------------
# Detail page enrichment
# ---------------------------------------------------------------------------

_DETAIL_PHONE_SELECTORS = [
    "a[href^='tel:']",
    ".phone a", "[class*='phone'] a",
    ".tel a",  ".telephone a",
    ".contact-phone",
]
_DETAIL_EMAIL_SELECTORS = [
    "a[href^='mailto:']",
    ".email a", "[class*='email'] a",
]
_DETAIL_WEBSITE_SELECTORS = [
    ".website a", "[class*='website'] a",
    ".url a", "[class*='url'] a",
    ".homepage a",
]
_DETAIL_ADDRESS_SELECTORS = [
    ".address", "[class*='address']",
    ".location", "[class*='location']",
    "[itemprop='streetAddress']",
    "[itemprop='address']",
    "address",
]
_DETAIL_REGION_SELECTORS = [
    "[class*='region']", "[class*='city']",
    "[itemprop='addressRegion']", "[itemprop='addressLocality']",
]


def scrape_detail(record: dict) -> dict:
    url = record.get("detail_url")
    if not url or BASE not in url:
        return record

    resp = get(url)
    if not resp:
        return record

    soup = BeautifulSoup(resp.text, "lxml")
    content = (
        soup.select_one(
            "article.type-business, article.hentry, "
            ".listing-detail, .single-listing, .business-detail, "
            "main, #main, #content"
        ) or soup
    )

    # Phone
    if not record.get("phone"):
        for sel in _DETAIL_PHONE_SELECTORS:
            el = content.select_one(sel)
            if el:
                h = el.get("href", "")
                record["phone"] = _clean_phone(h[4:] if h.startswith("tel:") else el.get_text(strip=True))
                break

    # Email
    if not record.get("email"):
        for sel in _DETAIL_EMAIL_SELECTORS:
            el = content.select_one(sel)
            if el:
                h = el.get("href", "")
                raw = h[7:] if h.startswith("mailto:") else el.get_text(strip=True)
                if "@" in raw and "namibiadirectory.com" not in raw:
                    record["email"] = raw.strip()
                break

    # Website
    if not record.get("website"):
        for sel in _DETAIL_WEBSITE_SELECTORS:
            el = content.select_one(sel)
            if el:
                h = el.get("href", "")
                domain = urlparse(h).netloc.lower().removeprefix("www.")
                if h and not any(domain == s or domain.endswith("." + s) for s in _SKIP_DOMAINS):
                    record["website"] = h
                    break
        # Fallback: any external link in the content area that isn't a skip domain
        if not record.get("website"):
            for a in content.select("a[href^='http']"):
                h = a.get("href", "")
                domain = urlparse(h).netloc.lower().removeprefix("www.")
                if not any(domain == s or domain.endswith("." + s) for s in _SKIP_DOMAINS):
                    record["website"] = h
                    break

    # Address
    if not record.get("address"):
        for sel in _DETAIL_ADDRESS_SELECTORS:
            el = content.select_one(sel)
            if el:
                txt = el.get_text(separator=", ", strip=True)
                if txt and len(txt) > 3:
                    record["address"] = txt
                    break

    # Region
    if not record.get("region"):
        for sel in _DETAIL_REGION_SELECTORS:
            el = content.select_one(sel)
            if el:
                txt = el.get_text(strip=True)
                if txt and len(txt) > 2:
                    record["region"] = txt
                    break

    time.sleep(0.4)
    return record


# ---------------------------------------------------------------------------
# Category pagination
# ---------------------------------------------------------------------------

def _find_next_page(soup: BeautifulSoup, current_url: str) -> "str | None":
    for sel in [
        "a.next.page-numbers",
        ".pagination a.next",
        ".nav-links a.next",
        "a[rel='next']",
        ".next a",
    ]:
        el = soup.select_one(sel)
        if el:
            return urljoin(current_url, el.get("href", ""))

    # Pattern: /category-business/slug/page/2/
    for a in soup.select("a[href*='/page/']"):
        href = a.get("href", "")
        if href and current_url.split("/page/")[0] in href:
            return urljoin(current_url, href)

    return None


def scrape_category(path: str, listing_type: str, limit: int = 0) -> list[dict]:
    url = urljoin(BASE, path)
    all_records: list[dict] = []
    page = 1

    while True:
        print(f"  Fetching page {page}: {url}")
        resp = get(url)
        if not resp:
            break

        soup = BeautifulSoup(resp.text, "lxml")
        records = _parse_cards(soup, listing_type, url)
        if not records:
            print(f"  No records on page {page} — stopping")
            break

        print(f"  → {len(records)} listings (page {page})")
        all_records.extend(records)

        if limit and len(all_records) >= limit:
            all_records = all_records[:limit]
            print(f"  Limit {limit} reached — stopping")
            break

        next_url = _find_next_page(soup, url)
        if not next_url or next_url == url:
            break
        url = next_url
        page += 1
        time.sleep(1.5)

    return all_records


# ---------------------------------------------------------------------------
# Name-based merge with NTB base list
# ---------------------------------------------------------------------------

def _similarity(a: str, b: str) -> float:
    return SequenceMatcher(None, a.lower().strip(), b.lower().strip()).ratio()


def merge_into_ntb(contacts: list[dict], ntb_path: Path, threshold: float = 0.82) -> list[dict]:
    if not ntb_path.exists():
        print(f"  [warn] NTB base file not found: {ntb_path} — skipping merge")
        return contacts

    with open(ntb_path, encoding="utf-8") as f:
        ntb = json.load(f)

    merged = 0
    for contact in contacts:
        best_score = 0.0
        best_idx = -1
        cname = contact.get("name", "")
        for i, rec in enumerate(ntb):
            score = _similarity(cname, rec.get("name", ""))
            if score > best_score:
                best_score = score
                best_idx = i

        if best_score >= threshold and best_idx >= 0:
            target = ntb[best_idx]
            # Only fill in fields that are currently empty
            for field, src_field in [
                ("website",       "website"),
                ("contact_email", "email"),
                ("phone",         "phone"),
                ("region",        "region"),
            ]:
                if not target.get(field) and contact.get(src_field):
                    target[field] = contact[src_field]
            # Store address separately if NTB record doesn't have it
            if not target.get("address") and contact.get("address"):
                target["address"] = contact["address"]
            merged += 1

    print(f"  Merged {merged}/{len(contacts)} contacts into NTB base ({len(ntb)} records)")
    return ntb


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="Scrape contact data from namibiadirectory.com")
    parser.add_argument("--limit",    type=int, default=0,
                        help="Max listings per category (0 = all, use e.g. 50 for a test run)")
    parser.add_argument("--workers",  type=int, default=6,
                        help="Parallel workers for detail page fetching (default: 6)")
    parser.add_argument("--no-merge", action="store_true",
                        help="Skip merging into scraped_providers.json")
    parser.add_argument("--no-details", action="store_true",
                        help="Skip detail page fetching (faster, less data)")
    args = parser.parse_args()

    all_contacts: list[dict] = []

    for path, listing_type in CATEGORIES:
        print(f"\n=== {path} → type={listing_type} ===")
        records = scrape_category(path, listing_type, limit=args.limit)
        print(f"  Category total: {len(records)}")
        all_contacts.extend(records)
        time.sleep(2)

    # Dedupe by name
    seen: set[str] = set()
    deduped: list[dict] = []
    for r in all_contacts:
        key = slug_from(r.get("name", ""))
        if key and key not in seen:
            seen.add(key)
            deduped.append(r)
    print(f"\nTotal after dedup: {len(deduped)}")

    # Detail page enrichment
    if not args.no_details:
        print(f"\nFetching detail pages ({args.workers} workers)…")
        with ThreadPoolExecutor(max_workers=args.workers) as executor:
            futures = {executor.submit(scrape_detail, r): i for i, r in enumerate(deduped)}
            done = 0
            for future in as_completed(futures):
                idx = futures[future]
                deduped[idx] = future.result()
                done += 1
                if done % 50 == 0 or done == len(deduped):
                    with_web = sum(1 for r in deduped if r.get("website"))
                    with_email = sum(1 for r in deduped if r.get("email"))
                    with_phone = sum(1 for r in deduped if r.get("phone"))
                    print(f"  [{done}/{len(deduped)}] website={with_web} email={with_email} phone={with_phone}")

    # Save raw contacts
    contacts_path = OUTPUT_DIR / "namibiadirectory_contacts.json"
    with open(contacts_path, "w", encoding="utf-8") as f:
        json.dump(deduped, f, ensure_ascii=False, indent=2)
    print(f"\nSaved contacts → {contacts_path} ({len(deduped)} records)")

    # Stats
    with_web   = sum(1 for r in deduped if r.get("website"))
    with_email = sum(1 for r in deduped if r.get("email"))
    with_phone = sum(1 for r in deduped if r.get("phone"))
    print(f"  With website: {with_web}")
    print(f"  With email:   {with_email}")
    print(f"  With phone:   {with_phone}")

    # Merge into NTB base list
    if not args.no_merge:
        ntb_path = OUTPUT_DIR / "scraped_providers.json"
        print(f"\nMerging into {ntb_path}…")
        merged = merge_into_ntb(deduped, ntb_path)
        with open(ntb_path, "w", encoding="utf-8") as f:
            json.dump(merged, f, ensure_ascii=False, indent=2)
        print(f"Saved merged NTB list → {ntb_path} ({len(merged)} records)")


if __name__ == "__main__":
    main()
