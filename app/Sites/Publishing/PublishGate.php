<?php

namespace App\Sites\Publishing;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What has to be true before a draft becomes publication.
 *
 * A draft is research: a page built about a business to show them what they
 * could have, before they have agreed to anything. Publishing is that page
 * going out under their name, and there is exactly one thing on a generated
 * draft that must not survive the transition.
 *
 * **Prospecting photographs.** Google Places imagery is publishable on
 * namibway.com under Google's terms and it expires. Neither is true of a site
 * we have promised the customer keeps if they leave — so it may win a meeting
 * and may not be published. The gate names the pictures rather than silently
 * dropping them, because a hero that vanishes on publication is a bug report
 * and a hero that was never replaced is a decision nobody made.
 *
 * Once nothing references them, the objects go. A prospecting copy of somebody
 * else's photograph is not something to keep lying around in a bucket.
 */
class PublishGate
{
    /**
     * Reasons this site cannot be published. Empty means it can.
     *
     * @return array<int, string>
     */
    public function blockers(Site $site): array
    {
        $blockers = [];

        foreach ($this->referencedProspectImages($site) as $image) {
            $blockers[] = "the photograph {$image->key} came from Google Places — "
                .'it may be shown while prospecting but not published on a site the customer keeps';
        }

        if ($site->pages()->count() === 0) {
            $blockers[] = 'the site has no pages';
        }

        return $blockers;
    }

    /**
     * Publish, or throw with every reason it cannot be.
     */
    public function publish(Site $site): void
    {
        $blockers = $this->blockers($site);

        if ($blockers !== []) {
            throw new CannotPublish($blockers);
        }

        $this->discardUnreferencedProspectImages($site);

        $site->forceFill([
            'status' => SiteStatus::Published,
            'published_at' => now(),
        ])->save();
    }

    /**
     * Prospect-only pictures a block still points at.
     *
     * @return array<int, SiteImage>
     */
    public function referencedProspectImages(Site $site): array
    {
        $referenced = $this->referencedImageIds($site);

        return $site->images()
            ->where('prospect_only', true)
            ->whereIn('id', $referenced === [] ? [0] : $referenced)
            ->get()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function referencedImageIds(Site $site): array
    {
        $ids = [];

        $blocks = SiteBlock::whereIn('site_page_id', $site->pages()->select('id'))->get();

        foreach ($blocks as $block) {
            foreach (['image_id', 'image_ids'] as $key) {
                foreach ((array) ($block->data[$key] ?? []) as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function discardUnreferencedProspectImages(Site $site): void
    {
        $referenced = $this->referencedImageIds($site);

        $orphans = $site->images()
            ->where('prospect_only', true)
            ->when($referenced !== [], fn ($query) => $query->whereNotIn('id', $referenced))
            ->get();

        foreach ($orphans as $image) {
            try {
                Storage::disk('r2')->delete($image->key);
            } catch (Throwable) {
                // The row goes either way. An object left behind costs storage;
                // a row left behind would let a withdrawn photograph come back.
            }

            $image->delete();
        }
    }
}
