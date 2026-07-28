#!/usr/bin/env python3
"""
Discovery script for namibiadirectory.com — run this ONCE in GitHub Actions
to understand the site structure before building the full scraper.

Outputs:
  - Category links found on the homepage
  - Raw HTML of the first listing card on the first category page
  - All fields visible on a single listing detail page
  - Pagination pattern detection

Usage:
  python scripts/discover_namibiadirectory.py
"""

import json
import re
import sys
import time
from urllib.parse import urljoin, urlparse

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("pip install requests beautifulsoup4 lxml")
    sys.exit(1)

BASE = "https://namibiadirectory.com"
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; NamibWayBot/1.0; "
        "building public tourism directory - namibway.com)"
    )
}


def get(url: str) -> "BeautifulSoup | None":
    try:
        r = requests.get(url, headers=HEADERS, timeout=15)
        r.raise_for_status()
        return BeautifulSoup(r.text, "lxml"), r.text
    except requests.RequestException as e:
        print(f"  [error] {url} → {e}")
        return None, None


def section(title: str) -> None:
    print(f"\n{'=' * 60}")
    print(f"  {title}")
    print('=' * 60)


def main() -> None:
    # ------------------------------------------------------------------ #
    # 1. Homepage — find category/nav links
    # ------------------------------------------------------------------ #
    section("1. Homepage — categories & navigation links")
    soup, raw = get(BASE)
    if not soup:
        print("FAILED — cannot reach homepage")
        sys.exit(1)

    # Print page title
    title = soup.select_one("title")
    print(f"Page title: {title.get_text(strip=True) if title else '(none)'}")

    # Find all internal links that look like category/directory pages
    category_links: list[str] = []
    for a in soup.select("a[href]"):
        href = a.get("href", "")
        if not href or href.startswith("#") or href.startswith("mailto:"):
            continue
        full = urljoin(BASE, href)
        if urlparse(full).netloc not in ("namibiadirectory.com", "www.namibiadirectory.com"):
            continue
        path = urlparse(full).path.rstrip("/")
        # Heuristic: category/listing paths typically contain one of these segments
        if any(seg in path for seg in [
            "category", "listing", "directory", "accommodation",
            "restaurant", "tour", "activity", "car-rental", "business",
        ]):
            label = a.get_text(strip=True)
            if label:
                category_links.append(f"  {label!r:40s} → {full}")

    seen = set()
    for line in category_links:
        if line not in seen:
            seen.add(line)
            print(line)

    # Also dump ALL unique internal paths (for manual inspection)
    section("2. All unique internal paths on homepage")
    all_paths: set[str] = set()
    for a in soup.select("a[href]"):
        href = a.get("href", "")
        full = urljoin(BASE, href)
        if urlparse(full).netloc in ("namibiadirectory.com", "www.namibiadirectory.com"):
            all_paths.add(urlparse(full).path)
    for p in sorted(all_paths)[:80]:
        print(f"  {p}")

    # ------------------------------------------------------------------ #
    # 3. Try common listing-index URLs
    # ------------------------------------------------------------------ #
    section("3. Probing common listing-index URLs")
    candidates = [
        "/listings/",
        "/listing/",
        "/directory/",
        "/businesses/",
        "/category/accommodation/",
        "/listing-category/accommodation/",
        "/accommodation/",
        "/?post_type=listing",
        "/?post_type=listings",
    ]
    working: list[str] = []
    for path in candidates:
        url = BASE + path
        try:
            r = requests.get(url, headers=HEADERS, timeout=10)
            status = r.status_code
            if status == 200:
                cards = BeautifulSoup(r.text, "lxml").select("article, .listing, .card, .business")
                print(f"  ✓ {url} — HTTP 200, {len(cards)} article/listing/card elements")
                working.append(url)
            else:
                print(f"  ✗ {url} — HTTP {status}")
        except Exception as e:
            print(f"  ✗ {url} — {e}")
        time.sleep(0.5)

    # ------------------------------------------------------------------ #
    # 4. Inspect first working page in detail
    # ------------------------------------------------------------------ #
    if working:
        section(f"4. First card HTML from {working[0]}")
        soup2, _ = get(working[0])
        if soup2:
            # Print first card's raw HTML (truncated)
            cards = soup2.select("article, .listing-item, .card, .business, li.listing")
            if cards:
                print(f"  Found {len(cards)} cards. First card HTML:\n")
                print(cards[0].prettify()[:3000])
            else:
                # Dump body text to help identify the structure
                print("  No cards found with standard selectors. Body text snippet:\n")
                body = soup2.select_one("main, #main, #content, body")
                print((body.get_text(separator="\n", strip=True) if body else "")[:2000])

            # Pagination detection
            section("4b. Pagination links")
            for sel in [".pagination a", ".nav-links a", ".page-numbers a",
                        "a[href*='page']", "a[href*='paged']"]:
                els = soup2.select(sel)
                if els:
                    print(f"  Selector {sel!r}: {[e.get('href') for e in els[:5]]}")

    # ------------------------------------------------------------------ #
    # 5. Try fetching a single listing detail page
    # ------------------------------------------------------------------ #
    section("5. Single listing detail page")
    # Find the first internal link that looks like a listing detail
    detail_url = None
    if working and soup2:
        for a in soup2.select("a[href]"):
            href = a.get("href", "")
            full = urljoin(BASE, href)
            parsed = urlparse(full)
            if parsed.netloc in ("namibiadirectory.com", "www.namibiadirectory.com"):
                path = parsed.path.rstrip("/")
                # Detail pages usually have 2+ path segments
                if path.count("/") >= 2 and "page" not in path:
                    detail_url = full
                    break

    if detail_url:
        print(f"  Fetching: {detail_url}")
        soup3, _ = get(detail_url)
        if soup3:
            # Print all text in main content area
            content = soup3.select_one("main, article, #content, .listing-detail, .single-listing")
            if content:
                print("\n  Fields visible on detail page:\n")
                # Look for labelled fields
                for el in content.select("[class*='field'], [class*='detail'], dt, dd, .phone, .email, .website, .address"):
                    txt = el.get_text(strip=True)
                    if txt and len(txt) < 200:
                        print(f"    [{el.name}.{' '.join(el.get('class', []))}] {txt}")
                # Also find links
                print("\n  Links on detail page:")
                for a in content.select("a[href]"):
                    href = a.get("href", "")
                    label = a.get_text(strip=True)
                    if href.startswith(("mailto:", "tel:", "http")):
                        print(f"    {href}  ({label!r})")
            else:
                print("  (no main content element found)")
                print(soup3.get_text(separator="\n", strip=True)[:2000])
    else:
        print("  Could not identify a detail page URL — check section 4 output")

    section("DONE — review output above to identify selectors for full scraper")


if __name__ == "__main__":
    main()
