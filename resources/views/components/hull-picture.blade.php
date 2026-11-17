{{-- A drone's hull, with the subsystems it is built from numbered on it and
     listed beneath (#802). $picture comes from App\Services\HullPicture::of. --}}
@props(['picture', 'hull' => null])
<figure class="hull-picture" id="hull-picture">
    <div class="hull-picture-image"><div class="hull-picture-frame">
        <img src="{{ $picture['image'] }}" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" loading="lazy"
             alt="{{ $hull ? $hull . ' hull' : 'Its hull' }}{{ $picture['items'] ? ', with each subsystem it is built from numbered' : '' }}">
        @foreach($picture['items'] as $item)
            @foreach($item['at'] as [$x, $y])
                <a class="hull-picture-n" href="/resources/{{ $item['id'] }}" style="left: {{ $x }}%; top: {{ $y }}%"
                   title="{{ $item['name'] }}" aria-label="{{ $item['n'] }}: {{ $item['name'] }}">{{ $item['n'] }}</a>
            @endforeach
        @endforeach
    </div></div>
    <figcaption>
        @if($picture['items'])
            <p class="hull-picture-title">{{ $hull ? $hull . ': built from' : 'Built from' }}</p>
            <ol class="hull-picture-key">
                @foreach($picture['items'] as $item)
                    <li @class(['unseen' => !$item['at']])>
                        <span class="hull-picture-n" aria-hidden="true">{{ $item['n'] }}</span>
                        <a href="/resources/{{ $item['id'] }}">{{ $item['name'] }}</a>@if($item['qty'] > 1) &times;{{ $item['qty'] }}@endif
                        @if(!$item['at'])<small>out of sight</small>@endif
                    </li>
                @endforeach
            </ol>
        @else
            <p class="hull-picture-title">{{ $hull ? $hull . ' hull' : 'Its hull' }}</p>
        @endif
    </figcaption>
</figure>
@once
    @push('styles')
        <style>
            .hull-picture { background: #000; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; margin: 1.5rem 0; overflow: hidden; }
            .hull-picture-image { padding: 12px; }
            .hull-picture-frame { position: relative; max-width: 900px; margin: 0 auto; }
            .hull-picture-frame img { display: block; width: 100%; height: auto; }
            .hull-picture-frame .hull-picture-n { position: absolute; transform: translate(-50%, -50%); }
            .hull-picture-n { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%;
                background: #4cc9f0; color: #0b0f1a !important; font-weight: 700; font-size: 14px; border: 2px solid #0b0f1a; text-decoration: none !important; }
            .hull-picture-frame a.hull-picture-n:hover, .hull-picture-frame a.hull-picture-n:focus { background: #ffb547; }
            .hull-picture figcaption { padding: 4px 16px 14px; }
            .hull-picture-title { margin: 0 0 6px; color: #9aa3b8; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; }
            .hull-picture-key { list-style: none; margin: 0; padding: 0; columns: 3 200px; column-gap: 24px; }
            .hull-picture-key li { break-inside: avoid; margin: 4px 0; }
            .hull-picture-key .hull-picture-n { width: 24px; height: 24px; font-size: 12px; margin-right: 8px; border: 0; }
            .hull-picture-key .unseen .hull-picture-n { background: transparent; color: #4cc9f0 !important; box-shadow: inset 0 0 0 2px #4cc9f0; }
            .hull-picture-key small { color: #9aa3b8; margin-left: 4px; }
        </style>
    @endpush
@endonce
