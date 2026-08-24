<?php

namespace Tests\Feature\Support;

use App\Support\HeroPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The homepage hero's daily photograph — see config/hero.php.
 *
 * Two properties matter beyond "it returns a photo": an empty configuration
 * has to stay the supported illustrated hero rather than a null-pointer
 * somewhere down the page, and the rotation has to be stable within a day
 * (a visitor reloading twice sees the same page) while moving on the next.
 */
class HeroPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function configure(int $count): void
    {
        config(['hero.photos' => array_map(fn (int $i): array => [
            'slug' => "photo-{$i}",
            'file' => "images/hero/photo-{$i}.jpg",
            'credit' => "Photographer {$i}",
            'focus' => '50% 60%',
        ], range(1, $count))]);
    }

    public function test_no_configured_photos_leaves_the_hero_illustrated(): void
    {
        config(['hero.photos' => []]);

        $this->assertNull(HeroPhoto::forDay(Carbon::parse('2026-08-24')));
    }

    public function test_an_entry_without_a_file_is_skipped_rather_than_rendered_broken(): void
    {
        config(['hero.photos' => [
            ['slug' => 'placeholder', 'file' => null],
            ['slug' => 'real', 'file' => 'images/hero/real.jpg'],
        ]]);

        $photo = HeroPhoto::forDay(Carbon::parse('2026-08-24'));

        $this->assertNotNull($photo);
        $this->assertSame('real', $photo['slug']);
    }

    public function test_it_holds_the_same_photo_all_day_and_moves_on_the_next(): void
    {
        $this->configure(3);

        $morning = HeroPhoto::forDay(Carbon::parse('2026-08-24 06:00'));
        $evening = HeroPhoto::forDay(Carbon::parse('2026-08-24 21:30'));
        $tomorrow = HeroPhoto::forDay(Carbon::parse('2026-08-25 06:00'));

        $this->assertSame($morning['slug'], $evening['slug']);
        $this->assertNotSame($morning['slug'], $tomorrow['slug']);
    }

    public function test_it_cycles_through_the_whole_set_rather_than_favouring_some(): void
    {
        $this->configure(4);

        $seen = [];

        for ($day = 0; $day < 4; $day++) {
            $seen[] = HeroPhoto::forDay(Carbon::parse('2026-08-24')->addDays($day))['slug'];
        }

        $this->assertSame(['photo-1', 'photo-2', 'photo-3', 'photo-4'], array_values(array_unique($seen)));
        $this->assertCount(4, array_unique($seen));
    }

    public function test_a_slug_can_be_previewed_out_of_turn(): void
    {
        $this->configure(3);

        $this->assertSame('photo-3', HeroPhoto::forDay(Carbon::parse('2026-08-24'), 'photo-3')['slug']);
    }

    public function test_an_unknown_preview_slug_falls_back_to_the_day(): void
    {
        $this->configure(3);

        $this->assertSame(
            HeroPhoto::forDay(Carbon::parse('2026-08-24'))['slug'],
            HeroPhoto::forDay(Carbon::parse('2026-08-24'), 'not-a-photo')['slug'],
        );
    }

    public function test_a_public_path_becomes_a_url_and_a_url_is_left_alone(): void
    {
        config(['hero.photos' => [
            ['slug' => 'local', 'file' => 'images/hero/dune.jpg'],
        ]]);
        $this->assertSame(
            asset('images/hero/dune.jpg'),
            HeroPhoto::forDay(Carbon::parse('2026-08-24'))['url'],
        );

        config(['hero.photos' => [
            ['slug' => 'remote', 'file' => 'https://media.namibway.test/hero/dune.jpg'],
        ]]);
        $this->assertSame(
            'https://media.namibway.test/hero/dune.jpg',
            HeroPhoto::forDay(Carbon::parse('2026-08-24'))['url'],
        );
    }

    public function test_the_homepage_hands_the_photo_to_the_hero(): void
    {
        $this->configure(2);

        $this->get('/?hero=photo-2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('heroPhoto.slug', 'photo-2')
                ->where('heroPhoto.credit', 'Photographer 2')
                ->where('heroPhoto.focus', '50% 60%'));
    }
}
