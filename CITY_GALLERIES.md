# City / destination photo galleries (planned, not built)

Status: **not started.** Written 2026-08-08 after the itinerary stage card gained a
clickable thumbnail that opens `ImageLightbox` — the slider works, but there is nothing
to slide through for most stages. This file is the handoff for doing it properly later.

## Why the slider currently looks thin

`stageImages()` in `resources/js/components/home/ItinerarySection.vue` composes the
lightbox from whatever photos exist for a stage:

1. the destination/city image from `regionCoords` (exactly one), then
2. the stay's `image` + `gallery`.

So a stage's "city photos" are mostly *the lodge's* photos. Verified on production: the
Otjiwarongo stage opens with 5 images — 1 is `regions/windhoek.jpg`, the other 4 are
`hodygos-backpackers-and-hostel-*.jpg`. A stage whose accommodation has no photos shows a
single image and no arrows.

Root cause — **there is no gallery column anywhere for places**:

| table | image column | gallery column | rows |
|---|---|---|---|
| `destinations` | `image` (populated, `regions/*.jpg`) | — | 12 |
| `cities` | `image` (exists, **0 rows populated**) | — | 105 |

`KaiaController::regionCoords()` (`app/Http/Controllers/KaiaController.php:46-105`) returns
`{lat, lng, image}` per key, keyed in this precedence:

1. destination name (curated image),
2. political region name (legacy saved plans),
3. city name — and this branch **hardcodes `'image' => null`** even though `cities.image`
   exists. That's a latent gap to fix as part of this work, not a separate bug.

Frontend type: `RegionCoords` in `resources/js/lib/kaia-client.ts:61-65`.

## Scope: which places actually need photos

Not all 105 cities. A day's `location` can only ever be a city that has a published
listing — that's exactly what `/kaia/cities` returns. On production today that is **6**:

```
Keetmanshoop, Mariental, Otjiwarongo, Outjo, Swakopmund, Windhoek
```

Plus the 12 curated destinations (`DestinationSeeder.php`), which also serve as map/legacy
keys: Windhoek, Etosha, Sossusvlei, Swakopmund, Spitzkoppe, Sandwich Harbour, Skeleton
Coast, Twyfelfontein, Waterberg, Fish River Canyon, Lüderitzbucht, Kolmanskop.

Windhoek and Swakopmund appear in both lists — destination wins in the lookup precedence,
so give *those two* their gallery on the destination row and skip the city row.

**Net: 12 destinations + 4 city-only places (Keetmanshoop, Mariental, Otjiwarongo, Outjo)
= 16 galleries.** Re-run `/kaia/cities` before starting; the list grows as listings are
published.

## Implementation

### 1. Schema

One migration adding a nullable `jsonb` `gallery` to both tables (same shape as
`listings.gallery`: a JSON array of storage paths).

- `app/Models/Destination.php` — add `'gallery'` to `$fillable`, `'gallery' => 'array'` to
  `$casts`.
- `app/Models/City.php` — same.

### 2. Expose it

`KaiaController::regionCoords()` — add `gallery` to all three branches, and fix the city
branch to stop hardcoding `image => null`:

```php
'image'   => $city->image ? self::resolveMediaUrl($city->image) : null,
'gallery' => collect($city->gallery ?? [])->map(self::resolveMediaUrl(...))->all(),
```

Remember to widen the `->get([...])` column lists — they currently select only
`id, name, lat, lng` (+ `image, region_id` for destinations).

### 3. Frontend

- `resources/js/lib/kaia-client.ts` — `RegionCoords` gains `gallery?: string[]`. Note
  `STATIC_REGION_COORDS` (the offline fallback) can stay gallery-less.
- `ItinerarySection.vue` `stageImages()` — insert the place gallery right after the place
  image and before the accommodation photos, so the lightbox leads with the *place*:

```ts
...(regionCoords.value[key]?.gallery ?? []),
```

The existing `.filter((src, i, all) => all.indexOf(src) === i)` dedupe already covers a
photo appearing in both lists.

### 4. Filament

Copy the `gallery` FileUpload from `ListingResource.php:245-257` verbatim into
`DestinationResource.php` and `CityResource.php` (both already have a single-`image`
FileUpload to sit next to):

```php
Forms\Components\FileUpload::make('gallery')
    ->image()->multiple()->reorderable()
    ->panelLayout('grid')->itemPanelAspectRatio(1)
    ->imageEditor()->openable()
    ->disk('r2')->directory('destinations/gallery')   // 'cities/gallery' for CityResource
    ->fetchFileInformation(false)
    ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
    ->columnSpanFull(),
```

`ListingResource` also renders a `gallery_preview` Placeholder above the upload — worth
copying too, it's the only way to see current gallery contents while editing.

### 5. The photos

A `namibway:assign-place-photos` command in the mould of
`app/Console/Commands/AssignListingPhotos.php` — a hardcoded slug → photo-URL map, with
`--dry-run` and `--force`, writing to the same disk convention the seeded destination
images already use.

**Sourcing method that works** (established 2026-08-02, see CLAUDE.md / memory):
`https://unsplash.com/photos/{id}/download` 302-redirects to the real CDN URL and is not
bot-blocked. Scraping Unsplash search pages, the `napi` JSON endpoint, and
`source.unsplash.com` are all blocked/dead.

**Verify every photo's location before committing it.** In the 2026-08-02 session two
candidates were caught being wrong — a Newport Beach pier offered as "Swakopmund" and a
Zion National Park waterfall as "Waterberg" — because WebSearch's location summaries were
wrong twice. Fetch each photo's own Unsplash page and read its location tag.

**Expect the four small towns to have little or nothing genuinely on Unsplash.**
Keetmanshoop, Mariental, Otjiwarongo and Outjo are small settlements; Unsplash coverage is
thin to non-existent. Do not paper over that with generic "Namibia dunes" shots presented
as photos of the town — that is exactly the kind of quiet wrongness the address-matching
incident (CLAUDE.md, 2026-08-04) came from. Options, in order of preference:

1. genuine photos of the town → use them;
2. a clearly regional/landscape shot of that area → acceptable, but then the UI should say
   so (e.g. caption "Region Karas" rather than implying it is the town itself);
3. nothing → leave the gallery empty. The lightbox already degrades correctly: one image,
   no arrows.

Licensing: Unsplash is free for commercial use without attribution, but crediting the
photographer is requested. `ImageLightbox` already has an `attribution` prop (added for
Google Places, which *requires* it) — reuse it if photographer credit is wanted.

## Verification

1. `php artisan migrate`, then set a gallery on one destination and one city via Filament.
2. `curl /kaia/region-coords` → confirm `gallery` arrays come back resolved to full URLs,
   and that a city with an image no longer returns `image: null`.
3. On a trip page, click a stage thumbnail: the lightbox counter should show the place
   photos first, then the stay's, with working arrows.
4. Regression: a stage whose place has no gallery and whose stay has no photos must still
   open with one image and no arrows (not a crash, not an empty overlay).
5. `npx vue-tsc --noEmit`, `npm run build`, and **`npx eslint resources/js`** — ESLint is
   CI-blocking here and `vue-tsc`/build do not catch its rules.
