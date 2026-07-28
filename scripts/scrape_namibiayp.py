#!/usr/bin/env python3
"""
NamibWay — namibiayp.com contact enrichment scraper

Extracts ONLY factual contact data (name, website, phone, email, address)
from namibiayp.com listings — no copyrighted text or images.

~2,300 listings across tourism-relevant categories.

Usage:
  pip install requests beautifulsoup4 lxml
  python scripts/scrape_namibiayp.py
  python scripts/scrape_namibiayp.py --limit 20           # test run (per category)
  python scripts/scrape_namibiayp.py --no-merge           # standalone output only
  python scripts/scrape_namibiayp.py --no-details         # skip detail pages

Output:
  scripts/namibiayp_contacts.json       — raw scraped contacts
  scripts/scraped_providers.json        — NTB base merged/enriched (unless --no-merge)
"""

import argparse
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from difflib import SequenceMatcher
from pathlib import Path
from urllib.parse import urljoin, urlparse, parse_qs

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("pip install requests beautifulsoup4 lxml")
    sys.exit(1)

OUTPUT_DIR = Path(__file__).parent
BASE = "https://www.namibiayp.com"
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; NamibWayBot/1.0; "
        "building public tourism directory - namibway.com)"
    )
}

# Categories with listing counts (from homepage HTML, as of 2026-07)
# Format: (path, internal_type, approx_count)
CATEGORIES: list[tuple[str, str, int]] = [
    # Accommodation
    ("/category/Apartments",                  "accommodation", 1302),
    ("/category/Hotels",                      "accommodation",  151),
    ("/category/Bed_and_Breakfast",           "accommodation",   18),
    ("/category/Specialist_accommodation",    "accommodation",   11),
    ("/category/Camping_and_Caravans",        "accommodation",    9),
    ("/category/Guest_houses",                "accommodation",    9),
    ("/category/Self_catering_accommodation", "accommodation",    6),
    ("/category/Lodges_Resorts",              "accommodation",    4),
    # Activities / Tours
    ("/category/Travel_agents",               "activity",       662),
    ("/category/Tour_operators",              "activity",        66),
    ("/category/Sightseeing",                 "activity",        14),
    ("/category/Excursions",                  "activity",        13),
    ("/category/Attractions",                 "activity",        13),
    ("/category/Places_to_visit",             "activity",         8),
    ("/category/Tourism",                     "activity",         3),
]

_SKIP_DOMAINS = {
    "namibiayp.com", "facebook.com", "instagram.com",
    "twitter.com", "x.com", "youtube.com", "linkedin.com",
    "tiktok.com", "pinterest.com", "whatsapp.com",
}


# ---------------------------------------------------------------------------
# HTTP
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
# Category page — listing cards
# ---------------------------------------------------------------------------

def _parse_category_cards(soup: BeautifulSoup, listing_type: str, page_url: str) -> list[dict]:
    """
    Parse listing cards from a namibiayp category page.

    Confirmed card HTML structure:
      <div class="company g_0" data-cmpid="3983">
        <div class="company_header">
          <h3><a href="/company/3983/Slug">Name</a></h3>
          <div class="address">P.O. Box …, <b>Windhoek</b>, Namibia</div>
        </div>
        <div class="cont">
          <div class="s"><i class="fa fa-phone"></i><span><b>+264…</b></span></div>
          <div class="s"><i class="fa fa-envelope"></i><span>E-mail</span></div>
          <div class="s"><i class="fa fa-globe"></i><span>Website</span></div>
        </div>
        <div class="mapmarker hidden" data-ltd="-22.57…" data-lng="17.08…"></div>
      </div>
    """
    cards = soup.select("div.company[data-cmpid]")
    if not cards:
        # Fallback: any div with company class
        cards = soup.select("div.company, div[class*='company'][id*='cmap']")

    if not cards:
        print(f"  [debug] No cards on {page_url}. Sample divs:")
        for el in soup.select("div[class], article")[:10]:
            print(f"    <div class='{' '.join(el.get('class', []))}'>")
        return []

    records: list[dict] = []
    for card in cards:
        try:
            # Name + detail URL
            name_el = card.select_one(".company_header h3 a, h3 a, h2 a")
            if not name_el:
                continue
            name = name_el.get_text(strip=True)
            if not name or len(name) < 2:
                continue
            detail_url = urljoin(BASE, name_el.get("href", ""))

            # Address — .address div; city is in <b> tag inside it
            address_el = card.select_one(".address")
            address = address_el.get_text(separator=" ", strip=True) if address_el else None
            region = None
            if address_el:
                b = address_el.select_one("b")
                region = b.get_text(strip=True) if b else None

            # Phone — .s div that contains fa-phone icon → span > b text
            phone = None
            for s_div in card.select(".s"):
                if s_div.select_one(".fa-phone"):
                    b = s_div.select_one("span b")
                    span = s_div.select_one("span")
                    raw = (b or span).get_text(strip=True) if (b or span) else ""
                    # Skip placeholder text
                    if raw and raw.lower() not in ("phone", "tel", "telephone"):
                        phone = re.sub(r"[^\d+\-\s()]", "", raw).strip() or None
                    break
            # Also check tel: links
            if not phone:
                tel_a = card.select_one("a[href^='tel:']")
                if tel_a:
                    phone = tel_a.get("href", "")[4:].strip() or None

            # Coordinates — .mapmarker hidden div with data-ltd / data-lng
            lat = lon = None
            marker = card.select_one(".mapmarker[data-ltd]")
            if marker:
                try:
                    lat = float(marker.get("data-ltd", ""))
                    lon = float(marker.get("data-lng", ""))
                except (ValueError, TypeError):
                    pass

            records.append({
                "name":       name,
                "type":       listing_type,
                "detail_url": detail_url if "/company/" in detail_url else None,
                "phone":      phone,
                "email":      None,
                "website":    None,
                "address":    address,
                "region":     region,
                "latitude":   lat,
                "longitude":  lon,
            })
        except Exception as e:
            print(f"  [warn] card parse error: {e}")

    return records


# ---------------------------------------------------------------------------
# Pagination
# ---------------------------------------------------------------------------

def scrape_category(path: str, listing_type: str, limit: int = 0) -> list[dict]:
    # Pagination pattern confirmed: /category/Hotels/2, /category/Hotels/3 …
    # The site returns the same N listings on every page past the real end — detect cycling.
    base_url = BASE + path
    all_records: list[dict] = []
    seen_urls: set[str] = set()
    page = 1
    consecutive_all_dupes = 0

    while True:
        page_url = base_url if page == 1 else f"{base_url}/{page}"
        print(f"  Fetching page {page}: {page_url}")
        resp = get(page_url)
        if not resp:
            break

        soup = BeautifulSoup(resp.text, "lxml")
        records = _parse_category_cards(soup, listing_type, page_url)

        if not records:
            break

        # Stop if every entry on this page is already known (site is cycling)
        new_records = [r for r in records if r.get("detail_url") not in seen_urls]
        if not new_records:
            consecutive_all_dupes += 1
            if consecutive_all_dupes >= 2:
                print(f"  → cycling detected — stopping")
                break
        else:
            consecutive_all_dupes = 0
            for r in new_records:
                seen_urls.add(r.get("detail_url", ""))
            all_records.extend(new_records)
            print(f"  → {len(new_records)} new listings (page {page}, total: {len(all_records)})")

        if limit and len(all_records) >= limit:
            all_records = all_records[:limit]
            print(f"  Limit {limit} reached — stopping")
            break

        page += 1
        time.sleep(1.2)

    return all_records


# ---------------------------------------------------------------------------
# Detail page enrichment
# ---------------------------------------------------------------------------

def _parse_yp_website(href: str) -> "str | None":
    """
    namibiayp.com wraps outbound links as /redir/{id}?u={url}
    Extract the real URL from the u= parameter.
    """
    if not href:
        return None
    parsed = urlparse(href)
    if "/redir/" in parsed.path:
        qs = parse_qs(parsed.query)
        raw = qs.get("u", [None])[0]
        if raw:
            if not raw.startswith("http"):
                raw = "https://" + raw
            return raw
    if href.startswith("http"):
        return href
    return None


def scrape_detail(record: dict) -> dict:
    """
    Fetch a namibiayp.com company detail page and extract:
    - phone, mobile phone (tel: links)
    - website (from /redir/ redirect or JSON-LD)
    - description (.text.desc)
    - address, city/region (JSON-LD or #company_address)
    - coordinates (JSON-LD geo or data-map-ltd/lng)
    - photos (div.photo_href[data-mfp-src])

    NOTE: Email is hidden behind login — not extractable.
    """
    url = record.get("detail_url")
    if not url or "/company/" not in url:
        return record

    resp = get(url)
    if not resp:
        return record

    soup = BeautifulSoup(resp.text, "lxml")

    # ------------------------------------------------------------------ #
    # 1. JSON-LD — richest single source (name, address, phone, url, geo)
    # ------------------------------------------------------------------ #
    ld = {}
    for script in soup.select("script[type='application/ld+json']"):
        try:
            data = json.loads(script.string or "")
            if data.get("@type") in ("LocalBusiness", "Organization", "LodgingBusiness"):
                ld = data
                break
        except (json.JSONDecodeError, AttributeError):
            pass

    # Phone from JSON-LD
    if not record.get("phone") and ld.get("telephone"):
        record["phone"] = ld["telephone"]

    # Website from JSON-LD url field
    if not record.get("website") and ld.get("url"):
        w = _parse_yp_website(ld["url"])
        domain = urlparse(w or "").netloc.lower().removeprefix("www.")
        if w and not any(domain == s or domain.endswith("." + s) for s in _SKIP_DOMAINS):
            record["website"] = w

    # Coordinates from JSON-LD geo
    if not record.get("latitude") and ld.get("geo"):
        try:
            record["latitude"]  = float(ld["geo"]["latitude"])
            record["longitude"] = float(ld["geo"]["longitude"])
        except (KeyError, ValueError, TypeError):
            pass

    # Address from JSON-LD
    if not record.get("address") and ld.get("address"):
        addr = ld["address"]
        parts = [addr.get("streetAddress"), addr.get("addressLocality"), addr.get("addressCountry")]
        record["address"] = ", ".join(p for p in parts if p)
    if not record.get("region") and ld.get("address", {}).get("addressLocality"):
        record["region"] = ld["address"]["addressLocality"]

    # ------------------------------------------------------------------ #
    # 2. HTML fallbacks for anything not in JSON-LD
    # ------------------------------------------------------------------ #
    content = soup.select_one("#company_item, #left, main") or soup

    # Phone (additional numbers not in JSON-LD — mobile, fax)
    if not record.get("phone"):
        for a in content.select("a[href^='tel:']"):
            raw = a.get("href", "")[4:].strip()
            if raw:
                record["phone"] = raw
                break

    # All phone numbers (primary + mobile) as list for later use
    phones = []
    for a in content.select("a[href^='tel:']"):
        raw = a.get("href", "")[4:].strip()
        if raw and raw not in phones:
            phones.append(raw)
    if phones:
        record["phone"] = phones[0]
        if len(phones) > 1:
            record["phone_mobile"] = phones[1]

    # Website from .weblinks redirect URL
    if not record.get("website"):
        weblink = content.select_one(".weblinks a, .text.weblinks a")
        if weblink:
            w = _parse_yp_website(weblink.get("href", ""))
            if not w:
                # Try text content as URL
                txt = weblink.get_text(strip=True)
                if txt and "." in txt and " " not in txt:
                    w = ("https://" + txt) if not txt.startswith("http") else txt
            if w:
                domain = urlparse(w).netloc.lower().removeprefix("www.")
                if not any(domain == s or domain.endswith("." + s) for s in _SKIP_DOMAINS):
                    record["website"] = w

    # Description
    if not record.get("description"):
        desc_el = content.select_one(".text.desc, #company_desc, .desc")
        if desc_el:
            # Remove <details> "Show more" wrapper text if present
            for details in desc_el.select("details"):
                details.unwrap()
            txt = desc_el.get_text(separator=" ", strip=True)
            if txt and len(txt) > 10:
                record["description"] = txt[:2000]

    # Address from #company_address
    if not record.get("address"):
        addr_el = content.select_one("#company_address")
        if addr_el:
            record["address"] = addr_el.get_text(separator=" ", strip=True)
    if not record.get("region"):
        addr_el = content.select_one("#company_address b")
        if addr_el:
            record["region"] = addr_el.get_text(strip=True)

    # Coordinates from map canvas data attributes
    if not record.get("latitude"):
        map_el = soup.select_one("[data-map-ltd]")
        if map_el:
            try:
                record["latitude"]  = float(map_el.get("data-map-ltd", ""))
                record["longitude"] = float(map_el.get("data-map-lng", ""))
            except (ValueError, TypeError):
                pass

    # Photos — collect up to 5 medium-size image URLs
    if not record.get("photos"):
        photos = []
        for div in content.select("div.photo_href[data-mfp-src]"):
            src = div.get("data-mfp-src", "")
            if src:
                photos.append(urljoin(BASE, src))
        if photos:
            record["photos"] = photos[:5]

    time.sleep(0.4)
    return record


# ---------------------------------------------------------------------------
# Merge into NTB base list
# ---------------------------------------------------------------------------

def _similarity(a: str, b: str) -> float:
    return SequenceMatcher(None, a.lower().strip(), b.lower().strip()).ratio()


def merge_into_ntb(contacts: list[dict], ntb_path: Path, threshold: float = 0.82) -> list[dict]:
    if not ntb_path.exists():
        print(f"  [warn] NTB base not found: {ntb_path} — skipping merge")
        return contacts

    with open(ntb_path, encoding="utf-8") as f:
        ntb = json.load(f)

    merged = 0
    for contact in contacts:
        cname = contact.get("name", "")
        best_score, best_idx = 0.0, -1
        for i, rec in enumerate(ntb):
            score = _similarity(cname, rec.get("name", ""))
            if score > best_score:
                best_score, best_idx = score, i

        if best_score >= threshold and best_idx >= 0:
            target = ntb[best_idx]
            for ntb_field, src_field in [
                ("website",       "website"),
                ("contact_email", "email"),
                ("phone",         "phone"),
                ("region",        "region"),
                ("latitude",      "latitude"),
                ("longitude",     "longitude"),
                ("description",   "description"),
                ("photos",        "photos"),
                ("address",       "address"),
            ]:
                if not target.get(ntb_field) and contact.get(src_field):
                    target[ntb_field] = contact[src_field]
            merged += 1

    print(f"  Merged {merged}/{len(contacts)} contacts into NTB base ({len(ntb)} records)")
    return ntb


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="Scrape contact data from namibiayp.com")
    parser.add_argument("--limit",      type=int, default=0,
                        help="Max listings per category (0=all, 20 for test)")
    parser.add_argument("--workers",    type=int, default=6,
                        help="Parallel workers for detail pages (default 6)")
    parser.add_argument("--no-details", action="store_true",
                        help="Skip detail page fetching")
    parser.add_argument("--no-merge",   action="store_true",
                        help="Skip merge into scraped_providers.json")
    args = parser.parse_args()

    all_contacts: list[dict] = []

    for path, listing_type, approx in CATEGORIES:
        print(f"\n=== {path} → {listing_type} (~{approx} listings) ===")
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
        to_enrich = [r for r in deduped if r.get("detail_url")]
        print(f"\nFetching {len(to_enrich)} detail pages ({args.workers} workers)…")
        idx_map = {id(r): i for i, r in enumerate(deduped)}
        with ThreadPoolExecutor(max_workers=args.workers) as executor:
            futures = {executor.submit(scrape_detail, r): r for r in to_enrich}
            done = 0
            for future in as_completed(futures):
                enriched = future.result()
                deduped[idx_map[id(futures[future])]] = enriched
                done += 1
                if done % 50 == 0 or done == len(to_enrich):
                    w = sum(1 for r in deduped if r.get("website"))
                    e = sum(1 for r in deduped if r.get("email"))
                    p = sum(1 for r in deduped if r.get("phone"))
                    print(f"  [{done}/{len(to_enrich)}] website={w} email={e} phone={p}")

    # Save raw contacts
    out = OUTPUT_DIR / "namibiayp_contacts.json"
    with open(out, "w", encoding="utf-8") as f:
        json.dump(deduped, f, ensure_ascii=False, indent=2)
    print(f"\nSaved → {out} ({len(deduped)} records)")

    w = sum(1 for r in deduped if r.get("website"))
    e = sum(1 for r in deduped if r.get("email"))
    p = sum(1 for r in deduped if r.get("phone"))
    a = sum(1 for r in deduped if r.get("address"))
    print(f"  website={w}  email={e}  phone={p}  address={a}")

    # Merge
    if not args.no_merge:
        ntb_path = OUTPUT_DIR / "scraped_providers.json"
        print(f"\nMerging into {ntb_path}…")
        merged = merge_into_ntb(deduped, ntb_path)
        with open(ntb_path, "w", encoding="utf-8") as f:
            json.dump(merged, f, ensure_ascii=False, indent=2)
        print(f"Saved merged NTB list → {ntb_path} ({len(merged)} records)")


if __name__ == "__main__":
    main()
