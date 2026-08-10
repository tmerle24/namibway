#!/usr/bin/env python3
"""
NamibWay — namibweb.com scraper (accommodation, tours, and the rest)

Crawls namibweb.com from a set of seed pages (the accommodation summaries and
the homepage), follows every listing detail page it finds, and writes one JSON
record per property/operator.

namibweb.com is a hand-maintained static HTML site from the early 2000s: no
semantic markup, no JSON-LD, table-based layout, `<font>` tags, and no single
index that holds everything — accsummary.htm, accnorth.htm and the homepage
each cover a different slice. So the crawler does not hardcode a page list. It
classifies each fetched page by behaviour: a page carrying many listing-shaped
links is an index and its links get queued; anything else is a detail page and
gets parsed into a record. Seeds are just the entry points.

Extraction is likewise structure-agnostic — it works on text blocks and
link/image patterns rather than CSS selectors, and strips template chrome by
finding the blocks (and the site's own social accounts) that repeat across the
whole corpus (see build_chrome_index).

The hard part is not the extraction, it's knowing *what changed* on the next
run. Three layers do that (see merge_with_baseline + the `--changes` report):

  1. HTTP conditional GET — ETag / Last-Modified per detail page, so an
     unchanged page costs one 304 and is copied from the baseline verbatim.
  2. Semantic hashes — per-field sha256 over normalised content, so a page
     that merely re-rendered (ad rotation, visitor counter, "last updated"
     line) does not read as a content change.
  3. A diff report — added / changed (with per-field before→after) / missing,
     written next to the data file so a human reviews a diff, not 400 records.

Records carry first_seen_at / last_seen_at / changed_at / revision, and the
downstream importer (`php artisan listings:import-namibweb`) keys its
"is this new information?" decision on the same hashes.

Usage:
  pip install requests beautifulsoup4 lxml
  python scripts/scrape_namibweb.py                       # incremental, uses existing JSON as baseline
  python scripts/scrape_namibweb.py --limit 20            # test run
  python scripts/scrape_namibweb.py --full                # ignore ETags, re-fetch everything
  python scripts/scrape_namibweb.py --probe 5             # dump page structure, write nothing
  python scripts/scrape_namibweb.py --seed https://www.namibweb.com/tours.htm

Output:
  data/scraped/namibweb_listings.json   — one record per listing
  data/scraped/namibweb_changes.json    — what changed vs. the baseline
"""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import re
import sys
import time
from collections import Counter, deque
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin, urlparse
from urllib.robotparser import RobotFileParser

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:  # pragma: no cover - dependency hint only
    print("pip install requests beautifulsoup4 lxml")
    sys.exit(1)

BASE = "https://www.namibweb.com"
HOST = "namibweb.com"

# Entry points only — everything else is discovered by crawling. The type hint
# is what a page's listings default to when the text itself is ambiguous.
SEEDS: list[tuple[str, str | None]] = [
    (f"{BASE}/accsummary.htm", "accommodation"),
    (f"{BASE}/accnorth.htm", "accommodation"),
    (f"{BASE}/", None),
]

OUTPUT_DIR = Path(__file__).resolve().parent.parent / "data" / "scraped"
DATA_FILE = OUTPUT_DIR / "namibweb_listings.json"
CHANGES_FILE = OUTPUT_DIR / "namibweb_changes.json"
ARCHIVE_FILE = OUTPUT_DIR / "namibweb_pages.jsonl.gz"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; NamibWayBot/1.0; +https://namibway.com; "
        "building a public tourism directory)"
    ),
    "Accept": "text/html,application/xhtml+xml",
}

# A page carrying at least this many listing-shaped links is an index, not a
# listing — that is what lets the crawler find accnorth.htm's siblings and the
# homepage's tour sections without them being hardcoded.
INDEX_LINK_THRESHOLD = 12

# A record has to disappear from the crawl this many runs in a row before it is
# called gone — one 500 from an old Apache box should not retire a lodge.
MISSING_RUNS_BEFORE_GONE = 3

# ---------------------------------------------------------------------------
# Link filtering — what is a listing, and what is site chrome
# ---------------------------------------------------------------------------

# Site-wide navigation targets: never listing pages, whatever they link to.
_NAV_HREF_PATTERNS = [
    r"^index",
    r"^home",
    r"^main",
    r"^contact",
    r"^about",
    r"^search",
    r"^sitemap",
    r"^links?\.",
    r"^advert",
    r"^disclaimer",
    r"^privacy",
    r"^terms",
    r"^payment",
    r"^weather",
    r"^currency",
    r"^exchange",
    r"^newsletter",
    r"^guestbook",
    r"^feedback",
]

_NAV_TEXT = {
    "home", "back", "next", "previous", "top", "index", "contact us", "contact",
    "e-mail", "email", "click here", "more", "more info", "search", "site map",
    "sitemap", "advertise", "disclaimer", "terms and conditions",
    "privacy policy", "namibia", "namibweb.com", "back to top", "read more",
    "details", "info", "information", "photos", "map", "book now", "enquire",
}

_DOC_EXTENSIONS = (".htm", ".html")

# ---------------------------------------------------------------------------
# Image filtering — layout chrome vs. actual photography
# ---------------------------------------------------------------------------

_CHROME_IMAGE_TOKENS = (
    "spacer", "blank", "clear", "pixel", "dot", "logo", "banner", "button",
    "btn", "nav", "menu", "arrow", "bullet", "line", "bar_", "_bar", "header",
    "footer", "icon", "flag", "counter", "hit", "email", "mail", "print",
    "star", "divider", "corner", "bg_", "background", "advert", "ad_", "_ad",
    "paypal", "visa", "mastercard", "facebook", "instagram", "twitter", "youtube",
)

_MIN_PHOTO_DIMENSION = 120

# ---------------------------------------------------------------------------
# Social / further links
# ---------------------------------------------------------------------------

# host substring → the key stored on the listing. Order matters only for
# readability; matching is by host.
_SOCIAL_HOSTS: list[tuple[str, str]] = [
    ("facebook.com", "facebook"),
    ("fb.com", "facebook"),
    ("instagram.com", "instagram"),
    ("youtube.com", "youtube"),
    ("youtu.be", "youtube"),
    ("twitter.com", "twitter"),
    ("x.com", "twitter"),
    ("linkedin.com", "linkedin"),
    ("tiktok.com", "tiktok"),
    ("pinterest.com", "pinterest"),
    ("tripadvisor.com", "tripadvisor"),
    ("tripadvisor.co.za", "tripadvisor"),
    ("vimeo.com", "vimeo"),
]

# Share widgets and bare profile-less roots — a link to facebook.com/sharer is
# not the lodge's Facebook page.
_SOCIAL_JUNK_PATHS = (
    "/sharer", "/share", "/intent", "/plugins", "/dialog", "/login",
    "/signup", "/home", "/pages/create",
)

_SKIP_WEBSITE_DOMAINS = {
    "namibweb.com", "www.namibweb.com", "google.com", "maps.google.com",
    "booking.com", "airbnb.com", "whatsapp.com", "wa.me", "paypal.com",
} | {host for host, _ in _SOCIAL_HOSTS}

# ---------------------------------------------------------------------------
# Text cleaning
# ---------------------------------------------------------------------------

_BOILERPLATE_MARKERS = (
    "all rights reserved",
    "copyright",
    "namibweb.com",
    "click here",
    "terms and conditions",
    "cancellation policy applies",
    "page updated",
    "last updated",
    "webmaster",
    "site designed",
    "powered by",
    "no part of this",
    "javascript",
    "your browser",
)

# Volatile strings that change on every render but say nothing about the
# listing — removed *before* hashing so they never fake a content change.
_VOLATILE_PATTERNS = [
    re.compile(r"(?i)\b(?:page\s+)?(?:last\s+)?updated?[:\s].{0,40}"),
    re.compile(r"(?i)\bvisitors?\s*[:#]?\s*[\d,\.]+"),
    re.compile(r"(?i)\bhits?\s*[:#]?\s*[\d,\.]+"),
    re.compile(r"(?i)\bcopyright\b.{0,60}"),
    re.compile(r"\b(?:19|20)\d{2}\s*[-–/]\s*(?:19|20)\d{2}\b"),
]

_EMAIL_RE = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
_EMAIL_OBFUSCATED_RE = re.compile(
    r"([A-Za-z0-9._%+\-]+)\s*(?:\(|\[)?\s*(?:at|AT|@)\s*(?:\)|\])?\s*"
    r"([A-Za-z0-9.\-]+)\s*(?:\(|\[)?\s*(?:dot|DOT|\.)\s*(?:\)|\])?\s*([A-Za-z]{2,})"
)
_PHONE_RE = re.compile(
    r"(?:\+\s?264|\(?0(?:0264)?\)?)[\s\-/().]?\d{2,3}[\s\-/().]?\d{3}[\s\-/().]?\d{3,4}"
)
_PRICE_RE = re.compile(r"(?:N\$|NAD|ZAR|R)\s?([\d][\d\s.,]{1,12})", re.IGNORECASE)

# "S 22° 34' 12.5"" / "E 17 05 30" — namibweb prints GPS in several shapes.
_DMS_RE = re.compile(
    r"(?P<hemi>[NSEW])\s*(?P<deg>\d{1,3})\s*(?:°|º|deg|\s)\s*"
    r"(?P<min>\d{1,2})\s*(?:'|′|min|\s)?\s*"
    r"(?P<sec>\d{1,2}(?:[.,]\d+)?)?\s*(?:\"|″|sec)?",
    re.IGNORECASE,
)
_DECIMAL_COORD_RE = re.compile(
    r"(?<![\d.])(-\d{2}\.\d{3,7})\s*[,;/ ]\s*(\d{2}\.\d{3,7})(?![\d.])"
)

# ---------------------------------------------------------------------------
# Classification
# ---------------------------------------------------------------------------

# Maps onto App\Enums\ListingType. Checked in order; accommodation wins ties
# because "Etosha Safari Lodge" is a lodge, not a safari operator.
_TYPE_KEYWORDS: list[tuple[str, tuple[str, ...]]] = [
    ("accommodation", (
        "lodge", "guest house", "guesthouse", "guest farm", "hotel", "resort",
        "camp site", "campsite", "camping", "rest camp", "bed and breakfast",
        "b&b", "pension", "self catering", "self-catering", "chalet", "bungalow",
        "backpacker", "hostel", "tented camp", "accommodation",
    )),
    ("vehicle", (
        "car hire", "car rental", "4x4 hire", "4x4 rental", "vehicle hire",
        "camper hire", "campervan", "motorhome", "rent a car", "car-hire",
    )),
    ("restaurant", (
        "restaurant", "bistro", "coffee shop", "cafe", "café", "steakhouse",
        "brewery", "wine bar",
    )),
    ("activity", (
        "tour", "tours", "safari operator", "excursion", "day trip", "charter",
        "scenic flight", "balloon", "quad bike", "sandboard", "skydive",
        "kayak", "dolphin cruise", "fishing trip", "guided", "activities",
        "adventure",
    )),
]

# Accommodation shape, kept as scrape_data metadata alongside the platform type.
_CATEGORY_KEYWORDS = [
    ("campsite", ("campsite", "camp site", "camping", "caravan")),
    ("guest_farm", ("guest farm", "gaestefarm", "gästefarm", "farmstay")),
    ("guesthouse", ("guest house", "guesthouse", "pension", "b&b", "bed and breakfast", "bed & breakfast")),
    ("hotel", ("hotel", "resort", "spa")),
    ("self_catering", ("self catering", "self-catering", "apartment", "chalet", "cottage", "bungalow")),
    ("backpackers", ("backpacker", "hostel")),
    ("tented_camp", ("tented camp", "tented", "safari camp")),
    ("lodge", ("lodge", "camp", "safari")),
]

_FACILITY_KEYWORDS = {
    "pool": ("swimming pool", "splash pool", "plunge pool"),
    "restaurant": ("restaurant", "à la carte", "a la carte", "dining room"),
    "bar": ("bar ", "sundowner bar", "cocktail bar"),
    "wifi": ("wi-fi", "wifi", "wireless internet", "internet access"),
    "air_conditioning": ("air-conditioned", "air conditioning", "aircon"),
    "game_drives": ("game drive", "game viewing", "safari drive"),
    "airstrip": ("airstrip", "landing strip", "airfield"),
    "conference": ("conference", "meeting room", "seminar"),
    "wheelchair": ("wheelchair", "disabled access", "barrier-free"),
    "pets": ("pets allowed", "pet friendly", "pet-friendly"),
    "credit_cards": ("credit card", "visa and mastercard"),
}


def now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


# ---------------------------------------------------------------------------
# HTTP
# ---------------------------------------------------------------------------


class Fetcher:
    """Session wrapper with retries, rate limiting and conditional GET."""

    def __init__(self, delay: float = 0.7, retries: int = 3, timeout: int = 20):
        self.session = requests.Session()
        self.session.headers.update(HEADERS)
        self.delay = delay
        self.retries = retries
        self.timeout = timeout

    def _conditional_headers(self, validators: dict | None) -> dict:
        headers: dict[str, str] = {}
        if validators:
            if validators.get("etag"):
                headers["If-None-Match"] = validators["etag"]
            if validators.get("last_modified"):
                headers["If-Modified-Since"] = validators["last_modified"]
        return headers

    def get_binary(self, url: str, validators: dict | None = None) -> tuple[bytes | None, dict, int]:
        """Same contract as get(), for images. Returns (bytes_or_None, validators, status)."""
        headers = self._conditional_headers(validators)

        for attempt in range(self.retries):
            try:
                time.sleep(self.delay)
                r = self.session.get(url, headers=headers, timeout=self.timeout, stream=True)
                if r.status_code == 304:
                    return None, validators or {}, 304
                r.raise_for_status()
                content = r.content
                return content, {
                    "etag": r.headers.get("ETag"),
                    "last_modified": r.headers.get("Last-Modified"),
                    "content_type": r.headers.get("Content-Type"),
                }, r.status_code
            except requests.RequestException as e:
                status = getattr(getattr(e, "response", None), "status_code", None)
                if status in (404, 410):
                    return None, {}, status
                time.sleep(1.0 * (attempt + 1))

        return None, {}, 0

    def get(self, url: str, validators: dict | None = None) -> tuple[str | None, dict, int]:
        """Returns (html_or_None, http_validators, status). html is None on 304."""
        headers = self._conditional_headers(validators)
        last_status = 0
        for attempt in range(self.retries):
            try:
                time.sleep(self.delay)
                r = self.session.get(url, headers=headers, timeout=self.timeout)
                last_status = r.status_code

                if r.status_code == 304:
                    return None, validators or {}, 304

                r.raise_for_status()

                # Old Apache boxes serve latin-1 without declaring it; requests
                # then guesses ISO-8859-1 and mangles the German umlauts these
                # pages are full of. Let the parser sniff the meta charset.
                r.encoding = r.apparent_encoding or r.encoding

                return r.text, {
                    "etag": r.headers.get("ETag"),
                    "last_modified": r.headers.get("Last-Modified"),
                    "content_length": r.headers.get("Content-Length"),
                }, r.status_code
            except requests.RequestException as e:
                status = getattr(getattr(e, "response", None), "status_code", None)
                if status in (404, 410):
                    return None, {}, status
                print(f"  [warn] {url} → {e} (attempt {attempt + 1}/{self.retries})")
                time.sleep(1.5 * (attempt + 1))

        return None, {}, last_status


def robots_allows(url: str, fetcher: Fetcher) -> bool:
    parser = RobotFileParser()
    try:
        r = fetcher.session.get(urljoin(BASE, "/robots.txt"), timeout=10)
        if r.status_code >= 400:
            return True  # no robots.txt = no restriction
        parser.parse(r.text.splitlines())
    except requests.RequestException:
        return True
    return parser.can_fetch(HEADERS["User-Agent"], url)


# ---------------------------------------------------------------------------
# Link handling
# ---------------------------------------------------------------------------


def same_host(url: str) -> bool:
    host = urlparse(url).netloc.lower()
    return not host or host.replace("www.", "") == HOST


def normalise_url(base: str, href: str) -> str | None:
    href = (href or "").strip()
    if not href or href.startswith(("mailto:", "tel:", "javascript:", "#")):
        return None
    url = urljoin(base, href).split("#")[0]
    if not same_host(url):
        return None
    if not urlparse(url).path.lower().endswith(_DOC_EXTENSIONS + ("/",)):
        return None
    return url


def is_listing_link(url: str, text: str) -> bool:
    filename = urlparse(url).path.lower().rsplit("/", 1)[-1]
    if not filename.endswith(_DOC_EXTENSIONS):
        return False
    if any(re.search(p, filename) for p in _NAV_HREF_PATTERNS):
        return False

    label = clean_text(text).lower().strip(" .·|>-")
    if label in _NAV_TEXT or len(label) < 3:
        return False
    return True


def page_links(html: str, page_url: str) -> list[tuple[str, str]]:
    """All same-host document links on a page, as (url, link_text)."""
    soup = BeautifulSoup(html, "lxml")
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()

    out: list[tuple[str, str]] = []
    seen: set[str] = set()
    for a in soup.find_all("a", href=True):
        url = normalise_url(page_url, a["href"])
        if not url or url in seen:
            continue
        seen.add(url)
        out.append((url, a.get_text(" ", strip=True)))
    return out


def sections_by_link(html: str, page_url: str) -> dict[str, str]:
    """Maps a link URL to the section heading it appears under.

    Index pages group listings under region/area headings with no markup that
    says "this is a heading", so the heading is tracked as the last short,
    link-free bold/heading-ish line seen while walking the document.
    """
    soup = BeautifulSoup(html, "lxml")
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()

    mapping: dict[str, str] = {}
    section = None

    for node in soup.find_all(["h1", "h2", "h3", "h4", "b", "strong", "a"]):
        if node.name == "a":
            url = normalise_url(page_url, node.get("href", ""))
            if url and section and url not in mapping:
                mapping[url] = section
            continue

        if node.find("a"):
            continue
        text = clean_text(node.get_text(" ", strip=True))
        if 3 <= len(text) <= 60 and not text.endswith((".", ",")) and text.lower() not in _NAV_TEXT:
            section = text

    return mapping


def scrape_id_for(url: str) -> str:
    """Stable per-listing id: the page path, minus host and extension.

    Keyed on the URL rather than the name because namibweb renames listings
    ("Lodge" → "Safari Lodge") far more often than it moves pages.
    """
    path = urlparse(url).path.lower().strip("/")
    path = re.sub(r"\.html?$", "", path)
    return re.sub(r"[^a-z0-9]+", "-", path).strip("-") or "index"


# ---------------------------------------------------------------------------
# Text helpers
# ---------------------------------------------------------------------------


def clean_text(value: str | None) -> str:
    if not value:
        return ""
    value = value.replace("\xa0", " ").replace("​", "")
    return re.sub(r"\s+", " ", value).strip()


def strip_volatile(value: str) -> str:
    for pattern in _VOLATILE_PATTERNS:
        value = pattern.sub(" ", value)
    return clean_text(value)


def looks_like_boilerplate(block: str) -> bool:
    lowered = block.lower()
    if any(marker in lowered for marker in _BOILERPLATE_MARKERS):
        return True
    # Nav strips are mostly separators and single words.
    if len(block) < 40 and block.count("|") + block.count("»") + block.count(">") >= 2:
        return True
    return False


def text_blocks(html: str) -> list[str]:
    """Splits a page into candidate prose blocks."""
    soup = BeautifulSoup(html, "lxml")
    for tag in soup(["script", "style", "noscript", "head", "form", "select", "option"]):
        tag.decompose()

    blocks: list[str] = []
    for node in soup.find_all(["p", "td", "div", "li", "blockquote"]):
        # Only leaf-ish containers, so a wrapper <td> does not duplicate its children.
        if node.find(["p", "td", "div", "li", "table"]):
            continue
        text = clean_text(node.get_text(" ", strip=True))
        if len(text) >= 25:
            blocks.append(text)

    if not blocks:  # pages whose body is one flat blob of <br>-separated text
        body = soup.body or soup
        for chunk in re.split(r"\n{2,}", body.get_text("\n", strip=True)):
            text = clean_text(chunk)
            if len(text) >= 25:
                blocks.append(text)

    return blocks


def build_chrome_index(all_blocks: list[list[str]]) -> set[str]:
    """Finds template chrome by frequency: any block repeated across many pages.

    This is what replaces CSS selectors on a site that has none — the header,
    footer, nav strip and booking blurb are, by definition, the text that is
    identical on hundreds of otherwise unrelated pages.
    """
    if len(all_blocks) < 4:
        return set()

    counts: Counter[str] = Counter()
    for blocks in all_blocks:
        for block in set(blocks):
            counts[block] += 1

    threshold = max(3, int(len(all_blocks) * 0.2))
    return {block for block, count in counts.items() if count >= threshold}


def strip_boilerplate(blocks: list[str], chrome: set[str]) -> list[str]:
    return [b for b in blocks if b not in chrome and not looks_like_boilerplate(b)]


# ---------------------------------------------------------------------------
# Field extraction
# ---------------------------------------------------------------------------


def extract_name(html: str, fallback: str, section: str | None = None) -> str:
    soup = BeautifulSoup(html, "lxml")

    for tag in ("h1", "h2"):
        node = soup.find(tag)
        if node:
            text = clean_text(node.get_text(" ", strip=True))
            if 3 <= len(text) <= 90:
                return text

    if soup.title:
        title = clean_text(soup.title.get_text())
        # "Sossusvlei Lodge, Namibia - namibweb.com" → "Sossusvlei Lodge"
        title = re.split(r"\s*[|–—]\s*|\s+-\s+", title)[0]
        title = re.sub(r"(?i),?\s*namibia\b.*$", "", title).strip(" -,")
        # Titles usually end with the place the index already files them under
        # ("Hohewarte Guest House, Windhoek") — that is a region, not a name.
        if section:
            title = re.sub(rf"(?i),\s*{re.escape(section)}\s*$", "", title).strip(" -,")
        if 3 <= len(title) <= 90:
            return title

    return fallback


def extract_photos(html: str, page_url: str) -> list[str]:
    soup = BeautifulSoup(html, "lxml")
    photos: list[str] = []
    seen: set[str] = set()

    for img in soup.find_all("img"):
        src = (img.get("src") or img.get("data-src") or "").strip()
        if not src:
            continue

        url = urljoin(page_url, src)
        filename = urlparse(url).path.rsplit("/", 1)[-1].lower()

        if url in seen:
            continue
        if filename.endswith(".gif"):  # old-site chrome is gif, photography is jpg
            continue
        if any(token in filename for token in _CHROME_IMAGE_TOKENS):
            continue

        too_small = False
        for attr in ("width", "height"):
            raw = (img.get(attr) or "").strip().rstrip("px")
            if raw.isdigit() and int(raw) < _MIN_PHOTO_DIMENSION:
                too_small = True
        if too_small:
            continue

        seen.add(url)
        photos.append(url)

    return photos


def extract_social_links(html: str) -> dict[str, str]:
    """Facebook / Instagram / YouTube / … profile URLs found on a page.

    These end up in the listing sidebar as further links, so a share widget or
    a bare platform root is worse than nothing — both are filtered out here,
    and the site's own accounts are filtered later by corpus frequency.
    """
    soup = BeautifulSoup(html, "lxml")
    found: dict[str, str] = {}

    for a in soup.find_all("a", href=True):
        href = a["href"].strip()
        if not href.startswith(("http://", "https://")):
            continue

        parsed = urlparse(href)
        host = parsed.netloc.lower().replace("www.", "")
        key = next((k for h, k in _SOCIAL_HOSTS if host == h or host.endswith("." + h)), None)
        if not key:
            continue

        path = parsed.path.rstrip("/")
        if not path or path == "/":  # bare platform root, no profile
            continue
        if any(path.lower().startswith(junk) for junk in _SOCIAL_JUNK_PATHS):
            continue

        url = f"https://{parsed.netloc.lower()}{path}"
        if parsed.query and key in ("youtube", "vimeo"):  # watch?v=… is the video id
            url = f"{url}?{parsed.query}"

        found.setdefault(key, url)

    return found


def build_chrome_social(all_social: list[dict[str, str]]) -> set[str]:
    """namibweb's own Facebook/YouTube links sit in the template on every page.

    Same frequency trick as the text chrome: a social URL that shows up on a
    large share of unrelated listing pages belongs to the site, not the lodge.
    """
    if len(all_social) < 4:
        return set()

    counts: Counter[str] = Counter()
    for social in all_social:
        for url in set(social.values()):
            counts[url] += 1

    threshold = max(3, int(len(all_social) * 0.2))
    return {url for url, count in counts.items() if count >= threshold}


def extract_emails(html: str) -> list[str]:
    found: list[str] = []
    soup = BeautifulSoup(html, "lxml")

    for a in soup.select("a[href^='mailto:']"):
        addr = a["href"][7:].split("?")[0].strip()
        if _EMAIL_RE.fullmatch(addr):
            found.append(addr)

    text = soup.get_text(" ", strip=True)
    found.extend(_EMAIL_RE.findall(text))
    for user, domain, tld in _EMAIL_OBFUSCATED_RE.findall(text):
        found.append(f"{user}@{domain}.{tld}")

    out: list[str] = []
    for addr in found:
        addr = addr.lower().strip(".,;:")
        if addr.endswith((".png", ".jpg", ".gif")) or "namibweb.com" in addr:
            continue
        if addr not in out:
            out.append(addr)
    return out


def extract_phones(text: str) -> list[str]:
    out: list[str] = []
    for match in _PHONE_RE.findall(text):
        phone = re.sub(r"[\s\-/().]+", " ", match).strip()
        if phone not in out:
            out.append(phone)
    return out


def extract_website(html: str, page_url: str) -> str | None:
    soup = BeautifulSoup(html, "lxml")
    for a in soup.find_all("a", href=True):
        href = a["href"].strip()
        if not href.startswith(("http://", "https://")):
            continue
        host = urlparse(href).netloc.lower()
        bare = host.replace("www.", "")
        if not host or host in _SKIP_WEBSITE_DOMAINS or bare in _SKIP_WEBSITE_DOMAINS:
            continue
        if bare == HOST:
            continue
        return href.split("?")[0]
    return None


def dms_to_decimal(hemi: str, deg: str, minutes: str, seconds: str | None) -> float:
    value = float(deg) + float(minutes) / 60
    if seconds:
        value += float(seconds.replace(",", ".")) / 3600
    if hemi.upper() in ("S", "W"):
        value = -value
    return round(value, 6)


def extract_coordinates(text: str) -> tuple[float | None, float | None]:
    lat = lon = None

    for m in _DMS_RE.finditer(text):
        value = dms_to_decimal(m.group("hemi"), m.group("deg"), m.group("min"), m.group("sec"))
        if m.group("hemi").upper() in ("N", "S") and lat is None:
            lat = value
        elif m.group("hemi").upper() in ("E", "W") and lon is None:
            lon = value

    if lat is None or lon is None:
        m = _DECIMAL_COORD_RE.search(text)
        if m:
            lat, lon = float(m.group(1)), float(m.group(2))

    # Namibia's bounding box — anything outside it is a false positive
    # (a phone number, a price, another country's page).
    if lat is not None and not (-29.5 <= lat <= -16.5):
        lat = None
    if lon is not None and not (11.0 <= lon <= 26.0):
        lon = None
    if lat is None or lon is None:
        return None, None
    return lat, lon


def extract_price(text: str) -> tuple[float | None, str | None]:
    prices: list[float] = []
    for raw in _PRICE_RE.findall(text):
        cleaned = raw.replace(" ", "").replace(",", "")
        cleaned = re.sub(r"\.(?=\d{3}\b)", "", cleaned)  # 1.250 → 1250
        try:
            value = float(cleaned)
        except ValueError:
            continue
        if 50 <= value <= 100_000:  # below is a room count, above is a typo
            prices.append(value)
    if not prices:
        return None, None
    return min(prices), "NAD"


def classify_listing_type(name: str, text: str, hint: str | None) -> str:
    haystack = f"{name} {text[:2000]}".lower()
    for listing_type, keywords in _TYPE_KEYWORDS:
        if any(k in haystack for k in keywords):
            return listing_type
    return hint or "accommodation"


def classify_category(name: str, text: str) -> str | None:
    haystack = f"{name} {text[:1500]}".lower()
    for category, keywords in _CATEGORY_KEYWORDS:
        if any(k in haystack for k in keywords):
            return category
    return None


def extract_facilities(text: str) -> list[str]:
    lowered = text.lower()
    return sorted(
        key for key, keywords in _FACILITY_KEYWORDS.items()
        if any(k in lowered for k in keywords)
    )


def extract_raw(html: str, page_url: str) -> dict:
    """Everything on the page, unfiltered and uninterpreted.

    The parsed fields above are one reading of a page; this is the material
    they were read from. It exists so a later decision — a field we did not
    think to extract, a description assembled differently, an image we
    initially dismissed as chrome — can be made from the JSON instead of by
    hitting namibweb.com a second time.
    """
    soup = BeautifulSoup(html, "lxml")
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()

    meta = {}
    for node in soup.find_all("meta"):
        key = (node.get("name") or node.get("property") or "").lower().strip()
        value = clean_text(node.get("content"))
        if key and value:
            meta.setdefault(key, value[:600])

    images = []
    for img in soup.find_all("img"):
        src = (img.get("src") or img.get("data-src") or "").strip()
        if not src:
            continue
        images.append({
            "url": urljoin(page_url, src),
            "alt": clean_text(img.get("alt")) or None,
            "width": clean_text(img.get("width")) or None,
            "height": clean_text(img.get("height")) or None,
        })

    links = []
    for a in soup.find_all("a", href=True):
        href = a["href"].strip()
        if href.startswith(("javascript:", "#")):
            continue
        links.append({
            "url": href if href.startswith("mailto:") else urljoin(page_url, href),
            "text": clean_text(a.get_text(" ", strip=True))[:120] or None,
        })

    tables = []
    for table in soup.find_all("table"):
        if table.find("table"):  # layout wrapper, its children carry the data
            continue
        rows = []
        for tr in table.find_all("tr"):
            cells = [clean_text(td.get_text(" ", strip=True)) for td in tr.find_all(["td", "th"])]
            cells = [c for c in cells if c]
            if len(cells) >= 2:
                rows.append(cells)
        if rows:
            tables.append(rows[:40])

    headings = [
        clean_text(h.get_text(" ", strip=True))
        for h in soup.find_all(["h1", "h2", "h3", "h4"])
        if clean_text(h.get_text(" ", strip=True))
    ]

    return {
        "title": clean_text(soup.title.get_text()) if soup.title else None,
        "meta": meta,
        "headings": headings[:20],
        "text_blocks": text_blocks(html),
        "images": images[:60],
        "links": links[:200],
        "tables": tables[:12],
    }


# ---------------------------------------------------------------------------
# Hashing — what "changed" means
# ---------------------------------------------------------------------------


def sha(*parts: object) -> str:
    joined = "␟".join("" if p is None else str(p) for p in parts)
    return hashlib.sha256(joined.encode("utf-8")).hexdigest()[:32]


def compute_hashes(record: dict) -> dict:
    """Per-field content hashes over normalised values.

    Normalisation matters more than the hash: description text has its volatile
    bits stripped, contacts are lowercased, photo and social URLs are sorted. A
    page that re-renders identically in substance therefore produces identical
    hashes and is reported as unchanged.
    """
    description = strip_volatile(record.get("description") or "").lower()
    contacts = "|".join([
        (record.get("email") or "").lower(),
        re.sub(r"\D", "", record.get("phone") or ""),
        (record.get("website") or "").lower().rstrip("/"),
        clean_text(record.get("address")).lower(),
    ])
    # Hash the bytes when we have them: namibweb replaces images in place, so
    # an unchanged URL list can still mean an entirely new photo set.
    photo_files = record.get("photo_files") or []
    if photo_files:
        photos = "|".join(sorted(f"{p.get('url')}#{p.get('sha256')}" for p in photo_files))
    else:
        photos = "|".join(sorted(record.get("photos") or []))
    social = "|".join(f"{k}={v.lower()}" for k, v in sorted((record.get("social_links") or {}).items()))
    facts = "|".join([
        str(record.get("latitude") or ""),
        str(record.get("longitude") or ""),
        str(record.get("price_from") or ""),
        record.get("listing_type") or "",
        record.get("category") or "",
        record.get("region") or "",
        ",".join(record.get("facilities") or []),
    ])

    hashes = {
        "name": sha(clean_text(record.get("name")).lower()),
        "description": sha(description),
        "contact": sha(contacts),
        "photos": sha(photos),
        "social": sha(social),
        "facts": sha(facts),
    }
    hashes["content"] = sha(*[hashes[k] for k in _DIFF_FIELDS])
    return hashes


_DIFF_FIELDS = ("name", "description", "contact", "photos", "social", "facts")


# ---------------------------------------------------------------------------
# Detail page → record
# ---------------------------------------------------------------------------


def parse_detail(page: "Page", chrome: set[str], chrome_social: set[str]) -> dict:
    html = page.html or ""
    blocks = strip_boilerplate(text_blocks(html), chrome)
    body = " ".join(blocks)
    full_text = clean_text(BeautifulSoup(html, "lxml").get_text(" ", strip=True))

    name = extract_name(html, page.name_hint or page.scrape_id, page.section)
    description = " ".join(b for b in blocks if len(b) >= 60)[:6000] or None

    emails = extract_emails(html)
    phones = extract_phones(full_text)
    lat, lon = extract_coordinates(full_text)
    price_from, currency = extract_price(full_text)
    social = {k: v for k, v in extract_social_links(html).items() if v not in chrome_social}

    # The address line is the block that mentions a postal marker and stays short.
    address = None
    for block in blocks:
        if len(block) <= 220 and re.search(r"(?i)\b(p\.?\s?o\.?\s?box|postal|street|str\.|avenue|ave\b|road\b)", block):
            address = block
            break

    return {
        "scrape_id": page.scrape_id,
        "source_url": page.url,
        "index_url": page.parent_url,
        "name": name,
        "region": page.section,
        "listing_type": classify_listing_type(name, body, page.type_hint),
        "category": classify_category(name, body),
        "description": description,
        "photos": extract_photos(html, page.url)[:30],
        "social_links": social,
        "email": emails[0] if emails else None,
        "emails": emails[:5],
        "phone": phones[0] if phones else None,
        "phones": phones[:5],
        "website": extract_website(html, page.url),
        "address": address,
        "latitude": lat,
        "longitude": lon,
        "price_from": price_from,
        "price_currency": currency,
        "facilities": extract_facilities(body),
        "raw": extract_raw(html, page.url),
    }


# ---------------------------------------------------------------------------
# Photo download — the bytes, not just the URLs
# ---------------------------------------------------------------------------


def photo_filename(url: str, index: int) -> str:
    name = urlparse(url).path.rsplit("/", 1)[-1] or f"image-{index}"
    name = re.sub(r"[^A-Za-z0-9._-]+", "-", name)[-80:]
    return f"{index:02d}-{name}"


def download_photos(records: dict[str, dict], baseline: dict[str, dict], photos_dir: Path,
                    fetcher: Fetcher, workers: int) -> tuple[int, int]:
    """Downloads every photo once and records its sha256 next to the URL.

    The byte hash is not bookkeeping — namibweb overwrites images in place
    (`lodge1.jpg` gets new content, same name), so URL-based change detection
    would miss a whole new photo set. Hashing the bytes catches it, and lets a
    re-run skip files it already holds.
    """
    photos_dir.mkdir(parents=True, exist_ok=True)
    downloaded = reused = 0

    previous_by_url: dict[str, dict] = {}
    for record in baseline.values():
        for entry in record.get("photo_files") or []:
            if entry.get("url") and entry.get("sha256"):
                previous_by_url[entry["url"]] = entry

    def fetch_one(job: tuple[str, str, int]) -> dict | None:
        scrape_id, url, index = job
        previous = previous_by_url.get(url)
        target_dir = photos_dir / scrape_id
        existing_path = target_dir / photo_filename(url, index)

        validators = previous.get("http") if previous else None
        if previous and existing_path.exists():
            content, new_validators, status = fetcher.get_binary(url, validators)
            if status == 304 or content is None:
                return {**previous, "file": str(existing_path.relative_to(photos_dir))}
        else:
            content, new_validators, status = fetcher.get_binary(url, None)

        if not content:
            return None

        target_dir.mkdir(parents=True, exist_ok=True)
        existing_path.write_bytes(content)
        return {
            "url": url,
            "file": str(existing_path.relative_to(photos_dir)),
            "sha256": hashlib.sha256(content).hexdigest(),
            "bytes": len(content),
            "content_type": (new_validators.get("content_type") or "").split(";")[0] or None,
            "http": {k: new_validators.get(k) for k in ("etag", "last_modified")},
        }

    jobs = [
        (scrape_id, url, i)
        for scrape_id, record in records.items()
        for i, url in enumerate(record.get("photos") or [])
    ]

    with ThreadPoolExecutor(max_workers=workers) as pool:
        results = list(pool.map(fetch_one, jobs))

    by_record: dict[str, list[dict]] = {}
    for (scrape_id, url, _), entry in zip(jobs, results):
        if not entry:
            continue
        by_record.setdefault(scrape_id, []).append(entry)
        if url in previous_by_url and entry.get("sha256") == previous_by_url[url].get("sha256"):
            reused += 1
        else:
            downloaded += 1

    for scrape_id, record in records.items():
        record["photo_files"] = by_record.get(scrape_id, [])

    return downloaded, reused


# ---------------------------------------------------------------------------
# Reverse geocoding — coordinates are often all a detail page gives
# ---------------------------------------------------------------------------

NOMINATIM_REVERSE = "https://nominatim.openstreetmap.org/reverse"
NOMINATIM_UA = "NamibWay/1.0 (+https://namibway.com; enrichment@namibway.com)"

# Nominatim's usage policy caps the public endpoint at 1 request/second and
# wants an identifying User-Agent — same terms App\Services\Enrichment\
# OsmLocationFinder already works under.
NOMINATIM_DELAY = 1.1


def reverse_geocode(records: dict[str, dict], baseline: dict[str, dict], session: requests.Session) -> int:
    """Turns GPS into an address, a town and a region, once per coordinate pair.

    Plenty of namibweb pages give coordinates and nothing else — no street, no
    town, sometimes not even a region heading. Resolving that here (rather than
    at import time) keeps it in the same "fetch everything once" pass, and the
    result is cached against the coordinates so an unchanged listing never
    costs a second call.
    """
    cached: dict[tuple[float, float], dict] = {}
    for record in baseline.values():
        geo = record.get("geo")
        if geo and record.get("latitude") and record.get("longitude"):
            cached[(round(record["latitude"], 5), round(record["longitude"], 5))] = geo

    resolved = 0

    for record in records.values():
        lat, lon = record.get("latitude"), record.get("longitude")
        if lat is None or lon is None:
            continue

        key = (round(lat, 5), round(lon, 5))
        if key in cached:
            record["geo"] = cached[key]
            continue

        try:
            time.sleep(NOMINATIM_DELAY)
            r = session.get(
                NOMINATIM_REVERSE,
                params={"lat": lat, "lon": lon, "format": "jsonv2", "zoom": 14, "addressdetails": 1},
                headers={"User-Agent": NOMINATIM_UA, "Accept-Language": "en"},
                timeout=15,
            )
            r.raise_for_status()
            payload = r.json()
        except (requests.RequestException, ValueError) as e:
            print(f"  [warn] reverse geocode {lat},{lon} → {e}")
            continue

        address = payload.get("address") or {}
        geo = {
            "display_name": payload.get("display_name"),
            "place": address.get("city") or address.get("town") or address.get("village")
                     or address.get("hamlet") or address.get("suburb"),
            "county": address.get("county"),
            "region": address.get("state"),
            "postcode": address.get("postcode"),
            "road": address.get("road"),
            "country": address.get("country"),
            "osm_id": payload.get("osm_id"),
            "source": "nominatim",
        }
        cached[key] = geo
        record["geo"] = geo
        resolved += 1

    return resolved


# ---------------------------------------------------------------------------
# HTML archive — the pages exactly as served
# ---------------------------------------------------------------------------


def load_archive(path: Path) -> dict[str, dict]:
    if not path.exists():
        return {}
    entries: dict[str, dict] = {}
    try:
        with gzip.open(path, "rt", encoding="utf-8") as fh:
            for line in fh:
                entry = json.loads(line)
                if entry.get("scrape_id"):
                    entries[entry["scrape_id"]] = entry
    except (OSError, json.JSONDecodeError) as e:
        print(f"  [warn] archive {path} unreadable ({e}) — starting a fresh one")
        return {}
    return entries


def write_archive(path: Path, pages: dict[str, "Page"], previous: dict[str, dict], run_at: str) -> int:
    """Keeps the raw HTML of every page, so nothing needs fetching twice.

    Pages that answered 304 are copied from the previous archive rather than
    re-fetched — that is the whole point of the conditional GET.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    entries = dict(previous)

    for scrape_id, page in pages.items():
        if not page.html:
            continue
        entries[scrape_id] = {
            "scrape_id": scrape_id,
            "url": page.url,
            "fetched_at": run_at,
            "status": page.status,
            "html": page.html,
        }

    with gzip.open(path, "wt", encoding="utf-8") as fh:
        for scrape_id in sorted(entries):
            fh.write(json.dumps(entries[scrape_id], ensure_ascii=False) + "\n")

    return len(entries)


# ---------------------------------------------------------------------------
# Crawl
# ---------------------------------------------------------------------------


class Page:
    """A fetched page plus how the crawler arrived at it."""

    __slots__ = ("url", "html", "validators", "status", "name_hint", "section",
                 "type_hint", "parent_url", "depth", "scrape_id")

    def __init__(self, url: str, *, name_hint: str = "", section: str | None = None,
                 type_hint: str | None = None, parent_url: str | None = None, depth: int = 0):
        self.url = url
        self.html: str | None = None
        self.validators: dict = {}
        self.status = 0
        self.name_hint = name_hint
        self.section = section
        self.type_hint = type_hint
        self.parent_url = parent_url
        self.depth = depth
        self.scrape_id = scrape_id_for(url)


def is_index_page(html: str, url: str) -> bool:
    listing_links = [t for t in page_links(html, url) if is_listing_link(t[0], t[1])]
    return len(listing_links) >= INDEX_LINK_THRESHOLD


def crawl(seeds: list[tuple[str, str | None]], fetcher: Fetcher, baseline: dict[str, dict],
          *, max_depth: int, max_pages: int, limit: int, use_conditional: bool,
          workers: int) -> tuple[dict[str, Page], list[str], set[str]]:
    """Breadth-first crawl that sorts pages into indexes and listings as it goes.

    Returns (listing pages by scrape_id, scrape_ids that answered 304, index URLs).
    """
    queue: deque[Page] = deque(
        Page(url, type_hint=hint, depth=0) for url, hint in seeds
    )
    queued: set[str] = {p.url for p in queue}

    listings: dict[str, Page] = {}
    unchanged: list[str] = []
    indexes: set[str] = set()
    fetched = 0

    def fetch(page: Page) -> Page:
        # Only detail pages we already have a record for may answer 304 — an
        # index must always come back in full, or its links are invisible.
        previous = baseline.get(page.scrape_id)
        validators = None
        if use_conditional and previous and previous.get("source_url") == page.url:
            validators = previous.get("http")
        page.html, page.validators, page.status = fetcher.get(page.url, validators)
        return page

    while queue and fetched < max_pages:
        batch: list[Page] = []
        while queue and len(batch) < workers:
            batch.append(queue.popleft())

        with ThreadPoolExecutor(max_workers=workers) as pool:
            results = list(pool.map(fetch, batch))

        for page in results:
            fetched += 1

            if page.status == 304:
                unchanged.append(page.scrape_id)
                continue
            if not page.html:
                if page.depth == 0:
                    print(f"  [warn] seed {page.url} failed (status {page.status})")
                continue

            if page.depth < max_depth and is_index_page(page.html, page.url):
                indexes.add(page.url)
                sections = sections_by_link(page.html, page.url)
                for url, text in page_links(page.html, page.url):
                    if url in queued or not is_listing_link(url, text):
                        continue
                    queued.add(url)
                    queue.append(Page(
                        url,
                        name_hint=clean_text(text),
                        section=sections.get(url) or page.section,
                        type_hint=page.type_hint,
                        parent_url=page.url,
                        depth=page.depth + 1,
                    ))
                continue

            if page.depth == 0:
                continue  # a seed that is not an index carries no listing of its own

            listings[page.scrape_id] = page
            if limit and len(listings) >= limit:
                queue.clear()
                break

        print(f"  crawled {fetched} pages — {len(listings)} listings, {len(queue)} queued")

    return listings, unchanged, indexes


# ---------------------------------------------------------------------------
# Baseline merge + change report
# ---------------------------------------------------------------------------


def merge_with_baseline(fresh: dict[str, dict], baseline: dict[str, dict], run_at: str) -> tuple[list[dict], dict]:
    """Applies provenance fields and builds the change report.

    `fresh` holds this run's records keyed by scrape_id; entries whose page
    answered 304 are inherited from `baseline` unchanged.
    """
    report = {
        "generated_at": run_at,
        "source": BASE,
        "counts": {"total": 0, "new": 0, "changed": 0, "unchanged": 0, "missing": 0, "gone": 0},
        "new": [],
        "changed": [],
        "missing": [],
    }

    records: list[dict] = []
    seen_ids: set[str] = set()

    for scrape_id, record in fresh.items():
        seen_ids.add(scrape_id)
        previous = baseline.get(scrape_id)
        record["hashes"] = compute_hashes(record)
        record["last_seen_at"] = run_at
        record["missing_runs"] = 0
        record["gone"] = False

        if previous is None:
            record["first_seen_at"] = run_at
            record["changed_at"] = run_at
            record["revision"] = 1
            record["status"] = "new"
            report["new"].append({
                "scrape_id": scrape_id,
                "name": record["name"],
                "url": record["source_url"],
                "type": record.get("listing_type"),
            })
        else:
            old_hashes = previous.get("hashes") or {}
            changed_fields = [f for f in _DIFF_FIELDS if old_hashes.get(f) != record["hashes"].get(f)]

            record["first_seen_at"] = previous.get("first_seen_at", run_at)
            if changed_fields:
                record["changed_at"] = run_at
                record["revision"] = int(previous.get("revision", 1)) + 1
                record["status"] = "changed"
                record["changed_fields"] = changed_fields
                report["changed"].append({
                    "scrape_id": scrape_id,
                    "name": record["name"],
                    "url": record["source_url"],
                    "fields": changed_fields,
                    "diff": build_diff(previous, record, changed_fields),
                })
            else:
                record["changed_at"] = previous.get("changed_at", record["first_seen_at"])
                record["revision"] = int(previous.get("revision", 1))
                record["status"] = "unchanged"

        records.append(record)

    # Records in the baseline that this run did not see.
    for scrape_id, previous in baseline.items():
        if scrape_id in seen_ids:
            continue
        missing_runs = int(previous.get("missing_runs", 0)) + 1
        previous = dict(previous)
        previous["missing_runs"] = missing_runs
        previous["status"] = "missing"
        previous["gone"] = missing_runs >= MISSING_RUNS_BEFORE_GONE
        if previous["gone"]:
            previous.setdefault("gone_since", run_at)
            report["counts"]["gone"] += 1
        report["missing"].append({
            "scrape_id": scrape_id,
            "name": previous.get("name"),
            "url": previous.get("source_url"),
            "missing_runs": missing_runs,
            "gone": previous["gone"],
        })
        records.append(previous)

    counts = report["counts"]
    counts["total"] = len(records)
    counts["new"] = len(report["new"])
    counts["changed"] = len(report["changed"])
    counts["missing"] = len(report["missing"])
    counts["unchanged"] = sum(1 for r in records if r.get("status") == "unchanged")

    records.sort(key=lambda r: r["scrape_id"])
    return records, report


def build_diff(previous: dict, current: dict, fields: list[str]) -> dict:
    """Human-readable before→after, truncated — the report is for reviewing."""

    def snippet(value: object) -> object:
        if isinstance(value, str) and len(value) > 300:
            return value[:300] + "…"
        return value

    field_sources = {
        "name": ["name"],
        "description": ["description"],
        "contact": ["email", "phone", "website", "address"],
        "photos": ["photos"],
        "social": ["social_links"],
        "facts": ["latitude", "longitude", "price_from", "listing_type", "category", "region", "facilities"],
    }

    diff: dict[str, dict] = {}
    for field in fields:
        for key in field_sources.get(field, []):
            before, after = previous.get(key), current.get(key)
            if before != after:
                diff[key] = {"before": snippet(before), "after": snippet(after)}
    return diff


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------


def load_baseline(path: Path) -> dict[str, dict]:
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        print(f"  [warn] baseline {path} is not valid JSON — treating every record as new")
        return {}
    records = data.get("records", data) if isinstance(data, dict) else data
    return {r["scrape_id"]: r for r in records if isinstance(r, dict) and r.get("scrape_id")}


def probe(pages: dict[str, Page], count: int) -> None:
    """Prints what the extractor sees, so extraction can be tuned from CI logs."""
    for page in list(pages.values())[:count]:
        html = page.html or ""
        blocks = text_blocks(html)
        print("\n" + "=" * 78)
        print(f"URL      : {page.url}  [{page.status}]  via {page.parent_url}")
        print(f"Validators: {page.validators}")
        print(f"Name     : {extract_name(html, page.name_hint, page.section)}")
        print(f"Section  : {page.section}")
        print(f"Photos   : {extract_photos(html, page.url)[:6]}")
        print(f"Social   : {extract_social_links(html)}")
        print(f"Emails   : {extract_emails(html)[:3]}")
        print(f"Blocks   : {len(blocks)}")
        for block in blocks[:12]:
            print(f"  · {block[:160]}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Scrape namibweb.com listings")
    parser.add_argument("--seed", action="append", default=[], help="Extra entry-point URL (repeatable)")
    parser.add_argument("--limit", type=int, default=0, help="Max listing pages (0 = all)")
    parser.add_argument("--max-pages", type=int, default=3000, help="Crawl budget, index pages included")
    parser.add_argument("--max-depth", type=int, default=2, help="How deep index pages may nest")
    parser.add_argument("--workers", type=int, default=4, help="Parallel fetches")
    parser.add_argument("--delay", type=float, default=0.7, help="Seconds between requests per worker")
    parser.add_argument("--out", default=str(DATA_FILE), help="Output JSON path")
    parser.add_argument("--changes", default=str(CHANGES_FILE), help="Change report path")
    parser.add_argument("--baseline", default=None, help="Baseline JSON (defaults to --out)")
    parser.add_argument("--full", action="store_true", help="Ignore ETag/Last-Modified, re-fetch everything")
    parser.add_argument("--probe", type=int, default=0, help="Dump structure for N pages and exit")
    parser.add_argument("--ignore-robots", action="store_true", help="Skip the robots.txt check")
    parser.add_argument("--photos-dir", default=str(OUTPUT_DIR / "namibweb_photos"),
                        help="Where downloaded photos go")
    parser.add_argument("--no-photos", action="store_true", help="Skip photo downloads (URLs are still recorded)")
    parser.add_argument("--archive", default=str(ARCHIVE_FILE), help="Gzipped JSONL of the raw HTML")
    parser.add_argument("--no-archive", action="store_true", help="Skip the raw HTML archive")
    parser.add_argument("--no-reverse-geocode", action="store_true",
                        help="Skip turning GPS into an address via Nominatim")
    args = parser.parse_args()

    run_at = now_iso()
    fetcher = Fetcher(delay=args.delay)
    seeds = SEEDS + [(url, None) for url in args.seed]

    if not args.ignore_robots and not robots_allows(seeds[0][0], fetcher):
        print("robots.txt disallows this path. Re-run with --ignore-robots only if you have permission.")
        return 1

    baseline_path = Path(args.baseline) if args.baseline else Path(args.out)
    baseline = load_baseline(baseline_path)
    print(f"Baseline holds {len(baseline)} records from a previous run")

    print("Crawling from: " + ", ".join(url for url, _ in seeds))
    pages, unchanged_ids, indexes = crawl(
        seeds, fetcher, baseline,
        max_depth=args.max_depth,
        max_pages=args.max_pages,
        limit=args.limit,
        use_conditional=not (args.full or args.probe),
        workers=args.workers,
    )
    print(f"Crawl done: {len(pages)} listing pages, {len(unchanged_ids)} unchanged (304), "
          f"{len(indexes)} index pages ({', '.join(sorted(indexes)) or 'none'})")

    if args.probe:
        probe(pages, args.probe)
        return 0

    if not pages and not unchanged_ids:
        print("No listing pages found — the site layout changed; re-run with --probe to inspect.")
        return 1

    # Parsing needs the whole corpus first: template chrome (text blocks and the
    # site's own social accounts) is identified by how often it repeats.
    all_blocks = [text_blocks(p.html or "") for p in pages.values()]
    all_social = [extract_social_links(p.html or "") for p in pages.values()]
    chrome = build_chrome_index(all_blocks)
    chrome_social = build_chrome_social(all_social)
    print(f"Stripping {len(chrome)} repeated template blocks and "
          f"{len(chrome_social)} site-owned social links")

    fresh: dict[str, dict] = {}
    for scrape_id, page in pages.items():
        try:
            record = parse_detail(page, chrome, chrome_social)
        except Exception as e:  # one broken page must not lose the run
            print(f"  [warn] parse failed for {page.url}: {e}")
            continue
        record["http"] = page.validators
        fresh[scrape_id] = record

    # 304s carry their baseline record forward untouched.
    for scrape_id in unchanged_ids:
        previous = baseline.get(scrape_id)
        if previous and scrape_id not in fresh:
            fresh[scrape_id] = {k: v for k, v in previous.items() if k not in ("status", "changed_fields")}

    if not args.no_archive:
        archive_path = Path(args.archive)
        total = write_archive(archive_path, pages, load_archive(archive_path), run_at)
        print(f"Archived {total} pages of raw HTML → {archive_path}")

    if not args.no_photos:
        downloaded, reused = download_photos(fresh, baseline, Path(args.photos_dir), fetcher, args.workers)
        print(f"Photos: {downloaded} downloaded, {reused} already held → {args.photos_dir}")

    if not args.no_reverse_geocode:
        resolved = reverse_geocode(fresh, baseline, fetcher.session)
        print(f"Reverse-geocoded {resolved} new coordinate pairs via Nominatim")

    records, report = merge_with_baseline(fresh, baseline, run_at)

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(
        json.dumps(
            {"scraped_at": run_at, "source": BASE, "indexes": sorted(indexes), "records": records},
            ensure_ascii=False, indent=2,
        ),
        encoding="utf-8",
    )
    Path(args.changes).write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    counts = report["counts"]
    print(
        f"\nWrote {counts['total']} records → {out_path}\n"
        f"  new       : {counts['new']}\n"
        f"  changed   : {counts['changed']}\n"
        f"  unchanged : {counts['unchanged']}\n"
        f"  missing   : {counts['missing']} (gone: {counts['gone']})\n"
        f"Change report → {args.changes}"
    )

    by_type = Counter(r.get("listing_type") for r in records)
    print("By type: " + ", ".join(f"{t}: {n}" for t, n in sorted(by_type.items(), key=lambda kv: str(kv[0]))))
    print(
        "Coverage: "
        f"description {sum(1 for r in records if r.get('description'))}, "
        f"photos {sum(1 for r in records if r.get('photos'))} "
        f"({sum(len(r.get('photo_files') or []) for r in records)} files), "
        f"social {sum(1 for r in records if r.get('social_links'))}, "
        f"coordinates {sum(1 for r in records if r.get('latitude'))}, "
        f"geocoded {sum(1 for r in records if r.get('geo'))}, "
        f"contact {sum(1 for r in records if r.get('email') or r.get('phone'))}"
    )

    return 0


if __name__ == "__main__":
    sys.exit(main())
