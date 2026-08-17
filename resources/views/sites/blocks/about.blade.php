@php
    $image = $images->get($data['image_id'] ?? null);
    $body = $data['body'] ?? null;

    // Paragraphs, whatever the text is marked up as — or not. See
    // App\Sites\Rendering\StoryText.
    $slides = \App\Sites\Rendering\StoryText::slides($body);

    // Slideshow: opt-in (default true), only when there are at least 2 paragraphs.
    $useSlideshow = ($data['slideshow'] ?? true) && count($slides) >= 2;
    $storyUrl = $useSlideshow ? $site->pageUrl('about') : null;
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $data['eyebrow'] ?? $definition->label()])

        <div class="split reveal">
            <div>
                @if (filled($data['heading'] ?? null))
                    <h2>{{ $data['heading'] }}</h2>
                @endif

                @if ($useSlideshow)
                    {{-- Story slideshow: one paragraph per slide, navigated with
                         previous/next and dot indicators. --}}
                    <style>
                        .story{overflow:hidden}
                        .story__track{display:flex;transition:transform .35s cubic-bezier(.4,0,.2,1);will-change:transform}
                        .story__slide{flex:0 0 100%;min-width:0}
                        .story__nav{display:flex;align-items:center;justify-content:flex-start;gap:var(--s4);margin-top:var(--s5)}
                        .story__btn{background:none;border:1px solid var(--bone);border-radius:50%;width:36px;height:36px;cursor:pointer;font-size:16px;line-height:1;color:var(--ink);transition:background .15s;flex-shrink:0}
                        .story__btn:hover{background:var(--bone)}
                        .story__btn:disabled{opacity:.3;cursor:default}
                        .story__dots{display:flex;gap:var(--s2);align-items:center}
                        .story__dot{width:7px;height:7px;border-radius:50%;background:var(--bone);border:none;cursor:pointer;padding:0;transition:background .15s,transform .15s}
                        .story__dot--active{background:var(--accent);transform:scale(1.4)}
                        .story__full{display:inline-block;margin-top:var(--s4);font-size:.85rem;color:var(--slate);text-decoration:underline}
                    </style>

                    <div class="story" data-story>
                        <div class="story__track" data-story-track>
                            @foreach ($slides as $slide)
                                <div class="story__slide prose">{!! $slide !!}</div>
                            @endforeach
                        </div>

                        <div class="story__nav">
                            <button class="story__btn" data-story-prev aria-label="Previous">&#8592;</button>
                            <div class="story__dots" role="tablist">
                                @foreach ($slides as $i => $slide)
                                    <button class="story__dot{{ $i === 0 ? ' story__dot--active' : '' }}"
                                            data-story-dot="{{ $i }}"
                                            role="tab"
                                            aria-label="Paragraph {{ $i + 1 }}"></button>
                                @endforeach
                            </div>
                            <button class="story__btn" data-story-next aria-label="Next">&#8594;</button>
                        </div>

                        @if ($storyUrl)
                            <a class="story__full" href="{{ $storyUrl }}">Read the full story &rarr;</a>
                        @endif
                    </div>

                    <script>
                    document.querySelectorAll('[data-story]').forEach(function(story){
                        var track=story.querySelector('[data-story-track]');
                        var dots=story.querySelectorAll('[data-story-dot]');
                        var prev=story.querySelector('[data-story-prev]');
                        var next=story.querySelector('[data-story-next]');
                        var n=track.children.length,i=0;
                        function go(to){
                            i=Math.max(0,Math.min(n-1,to));
                            track.style.transform='translateX(-'+i*100+'%)';
                            dots.forEach(function(d,j){d.classList.toggle('story__dot--active',j===i);});
                            prev.disabled=i===0;
                            next.disabled=i===n-1;
                        }
                        prev.addEventListener('click',function(){go(i-1);});
                        next.addEventListener('click',function(){go(i+1);});
                        dots.forEach(function(d,j){d.addEventListener('click',function(){go(j);});});
                        go(0);
                    });
                    </script>
                @else
                    {{-- Already sanitised on the way in through the same purifier
                         allow-list as a listing description — see SiteBlock and
                         Listing::sanitizeRichText. StoryText only puts the
                         paragraph breaks back. --}}
                    <div class="prose">{!! \App\Sites\Rendering\StoryText::html($body) !!}</div>
                @endif
            </div>

            @if ($image)
                <figure class="figure figure--lb" style="margin:0">
                    <img src="{{ $image->thumb(800) }}"
                         data-lb="{{ $image->thumb(1200) }}"
                         @if ($srcset = $image->srcset(800)) srcset="{{ $srcset }}" @endif
                         sizes="(min-width: 860px) 50vw, 100vw"
                         alt="{{ $image->alt ?? $site->name }}"
                         loading="lazy" decoding="async">
                </figure>
            @endif
        </div>
    </div>
</section>
