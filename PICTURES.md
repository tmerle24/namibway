# Image Handling — Architecture and Gotchas

This file exists because image handling has caused production incidents more than once.
Read it before touching anything that uploads, displays, or deletes images.

## Storage architecture

One R2 bucket, two roles, one rule:

| What | Disk config key | Visibility | Served by |
|------|-----------------|------------|-----------|
| All media (listings, sites, products, galleries) | `r2` | **public** | Direct R2 URL or `/thumbs/{width}/{key}` |
| Nightly backups | `r2-backups` | **private** | Never directly |
| Admin documents (contracts, internal wiki) | `local` | private | `DocumentDownloadController` only |

The `r2` disk URL is `CLOUDFLARE_R2_URL` in `.env`. In production this points at the R2 bucket's default public URL (`https://pub-xxxx.r2.dev`). The bucket has no Cloudflare Image Transformations active (would require namibway.com's DNS at Cloudflare, which is at OVH). Do not put a path-with-host into an image key — keys are always relative to the bucket root.

## Keys vs. URLs

`SiteImage`, `Listing.image`, gallery arrays — everything in the database stores a **key** (the path within the R2 bucket, e.g. `sites/abc123/xyz.jpg`), never a full URL. URLs are derived at render time.

```php
// Correct: key in DB, URL at render time
Storage::disk('r2')->url($key);   // → https://pub-xxx.r2.dev/sites/abc123/xyz.jpg

// Wrong: storing a full URL as a key — breaks when the bucket domain changes
SiteImage::create(['key' => 'https://pub-xxx.r2.dev/sites/abc123/xyz.jpg']);
```

Old scraped photos (imported before 2026-08-02) were stored as absolute URLs. `Controller::resolveMediaUrl` and `MediaUrl` handle this legacy case. Do not add new code that stores absolute URLs.

## Thumbnails

`/thumbs/{width}/{key}` — served by `ThumbnailController` → `ThumbnailGenerator`. Originals are the only stored truth; thumbnails are produced on demand and cached in the same R2 bucket under `thumbs/`. Deleting `thumbs/` is safe and is how a changed width ladder is applied. The render width is a property of the component, not of the DB payload.

```php
// In PHP (server-rendered pages):
app(MediaUrl::class)->thumb($key, 800);   // → /thumbs/800/sites/...

// In Vue:
import { thumbUrl } from '@/lib/media'
thumbUrl(key, 800)
```

Never hardcode pixel widths in a query or DB column. Set them in the component.

## Filament FileUpload — temp files

**Every Filament FileUpload goes through Livewire's temp storage first**, regardless of what `->disk()` you set. The file lands in `livewire-tmp/` on the `local` disk. On form submit, Filament moves it to the target disk. This has two consequences:

### 1. `afterStateUpdated` receives file objects, not keys

This is the single thing that has cost the most time here. While the form is being
edited, a Filament FileUpload's state is **an array keyed by a UUID Filament
generates, whose values are live `TemporaryUploadedFile` objects** — not strings,
not R2 keys, not filenames. The strings only appear later, after
`saveUploadedFiles()` runs at dehydration time (`BaseFileUpload::setUp`).

```php
// What afterStateUpdated actually gives you:
$state = ['9f1c…-uuid' => TemporaryUploadedFile { … }];

// So: iterate with keys, guard the type, and read through the object.
foreach ($state ?? [] as $uuid => $file) {
    if (! $file instanceof TemporaryUploadedFile) {
        continue;                       // already a stored key
    }
    $content = $file->get();            // reads from the right disk + directory
    $ext     = $file->getClientOriginalExtension();
}
```

Three traps, all of which have actually fired:

- **`basename($file)` throws a `TypeError` on an object.** `TypeError` implements
  `Throwable`, so a `catch (Throwable)` around the move swallows it and you get a
  silent empty preview with nothing in the log. Never catch `Throwable` around
  this without logging.
- **Do not rebuild the temp path from config.**
  `config('livewire.temporary_file_upload.disk', 'local')` returns **null**, not
  `'local'` — Livewire ships that key explicitly set to `null` to mean "use the
  default", and `config()`'s second argument only applies to *missing* keys.
  `$file->get()` already knows the right disk and directory; use it.
- **Do not use `TemporaryUploadedFile::getRealPath()`** on a value you
  reconstructed yourself. Use the object handed to you.

The UUID key is the only stable per-file identity across re-renders, so dedupe on
it — the file object is not comparable and the client filename is not unique.

### 2. If you need a preview URL before submit, move to R2 immediately

Livewire's signed preview route (`livewire.preview-file`) exists but its URL expires within 30 minutes and requires Laravel's signed URL middleware. Simpler and more durable: move the file to R2 inside `afterStateUpdated` and use the resulting public R2 URL as the preview source.

```php
->afterStateUpdated(function (?array $state, Get $get, Set $set) use ($record): void {
    foreach ($state ?? [] as $uuid => $file) {
        if (! $file instanceof TemporaryUploadedFile) {
            continue;
        }
        [$r2Key, $previewUrl] = self::moveTempToR2($file, $site);
        // Store $uuid + r2Key + previewUrl in the repeater state
    }
})

private static function moveTempToR2(TemporaryUploadedFile $file, Site $site): array
{
    $content = $file->get();
    $ext     = strtolower($file->getClientOriginalExtension() ?: '') ?: 'jpg';
    $r2Key   = $site->mediaPrefix().'/'.Str::random(20).'.'.$ext;

    Storage::disk('r2')->put($r2Key, $content, 'public');

    return [$r2Key, Storage::disk('r2')->url($r2Key)];
}
```

Add both `->dehydrated(false)` and `->storeFiles(false)` to the FileUpload.
`dehydrated(false)` keeps the raw state out of the submitted data;
`storeFiles(false)` makes `saveUploadedFiles()` return early, so Filament does not
upload the same file to R2 a second time on submit.

### 3. Orphan risk

Moving to R2 in `afterStateUpdated` means a cancel or browser-close leaves an orphaned R2 object. `photos:audit-r2` handles cleanup — it matches by filename, not by URL, so it is safe to run even after the R2 URL base changes. Do NOT gate the keep/delete decision on a value derived from `CLOUDFLARE_R2_URL`; that variable can change.

## Image previews in Filament forms (CSS)

`h-full` on an `<img>` inside an `aspect-ratio` container does not work in all browsers unless the parent has an **explicitly set height** (which `aspect-ratio` alone does not count as). Use absolute positioning instead:

```php
// Broken — img collapses to one line-height (~20 px)
'<div class="aspect-square w-full overflow-hidden rounded-lg">'
.'<img class="w-full h-full object-cover" src="..." />'
.'</div>'

// Correct — img fills the aspect-ratio container
'<div class="relative w-full overflow-hidden rounded-lg" style="aspect-ratio:1/1">'
.'<img class="absolute inset-0 w-full h-full object-cover" src="..." />'
.'</div>'
```

`aspect-square` (Tailwind) is fine for standalone elements; add `relative` and use `absolute inset-0` on the child when the child needs `h-full`.

## `SiteImage` — the site's image record

`SiteImage` holds a key, a `content_source`, and a `prospect_only` flag. Products, blocks, and galleries reference `SiteImage` by id (stored as `image_ids JSON` or similar). Deleting a `SiteImage` row does NOT delete the R2 object — `photos:audit-r2` is the only thing that cleans up orphaned objects.

```php
SiteImage::create([
    'site_id'        => $site->id,
    'key'            => $r2Key,          // R2 key, never a URL
    'content_source' => ContentSource::Partner,
    'prospect_only'  => false,
    'sort'           => 0,
]);
```

## `photos:audit-r2` — the safety valve

The audit command compares R2 objects against DB records, matching on the **filename tail** (not the full URL). Never key the keep/delete decision on a value derived from `CLOUDFLARE_R2_URL` — if the domain changes, every referenced photo looks orphaned and the entire library gets deleted on confirm. The command requires an explicit `--delete-orphaned` flag; it always reports first and acts only on confirmation.

## Past incidents

**2026-08-09 — MEDIA_TRANSFORMS_ENABLED enabled while DNS was not at Cloudflare**
Setting `CLOUDFLARE_R2_URL=https://cdn.namibway.com` while namibway.com's DNS is at OVH (not Cloudflare) rewrote every image URL through `/cdn-cgi/image/` → 404 for all images. Fix: revert `.env`, never rewrite app-origin/root-relative URLs through `/cdn-cgi/image/`, and never set a CDN URL without confirming DNS delegation.

**2026-08-09 (near miss) — `photos:audit-r2 --delete-orphaned` nearly wiped the library**
The command compared DB values against `Storage::disk('r2')->url($key)`. Scraper photos were stored as absolute URLs built from `CLOUDFLARE_R2_URL`; changing that variable made every referenced photo look orphaned. Fixed by matching on filename instead of full URL.

**2026-08-16 — Bulk upload preview broken, fixed three times before the cause was found**
The state in `afterStateUpdated` was assumed to be strings. It is
`[uuid => TemporaryUploadedFile]`. `basename()` on the object threw a `TypeError`,
which `catch (Throwable)` swallowed without logging, so every attempt produced an
empty preview and an empty log — and each fix was a new guess about *which* wrong
string path was being read, rather than a check of what the state actually holds.

Two lessons beyond the API detail. **A `catch (Throwable)` with no log turns a
type error into a blank screen**, and a blank screen is not diagnosable. And when
a fix does not work, read the framework source before writing the second one —
`BaseFileUpload::setUp()` answers this in twenty lines.

Separately, in the same feature: `h-full` on an `<img>` inside an `aspect-ratio`
div collapsed to 20 px (one line-height), because `aspect-ratio` alone does not
establish a definite height for `h-full` children. See the CSS section above.
