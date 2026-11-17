@extends('layouts.app')
@php
    $title = $resource->name;
    // Its turntable (#707), rendered by tools/render: a slowly turning picture,
    // and a still for visitors who ask their system to reduce motion
    $picture = 'images/resources/' . $resource->id;
    $hasPicture = is_file(public_path($picture . '.webp'));
@endphp
@section('pageTitle', $title)
@include('partials.market-styles')
@push('styles')
    <style>
        .rv-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 .5rem; }
        .rv-tile { background: #111729; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 12px 14px; min-width: 0; }
        .rv-tile small { display: block; color: #9aa3b8; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; }
        .rv-tile b { display: block; font-family: "Space Grotesk", system-ui, sans-serif; font-size: 1.45rem; line-height: 1.3; font-variant-numeric: tabular-nums; }
        .rv-tile span { display: block; color: #c3c9d8; font-size: 13px; }
        .rv-sides { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .rv-sides > div { min-width: 0; }
        .craft-parts { background: #000; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; margin: 0 0 1.5rem; overflow: hidden; }
        .craft-parts-picture { padding: 12px; }
        .craft-parts-frame { position: relative; }
        .craft-parts-picture img { display: block; width: 100%; height: auto; }
        .craft-parts-frame .craft-parts-n { position: absolute; transform: translate(-50%, -50%); }
        .craft-parts-n { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%;
            background: #4cc9f0; color: #0b0f1a !important; font-weight: 700; font-size: 14px; border: 2px solid #0b0f1a; text-decoration: none !important; }
        .craft-parts-frame a.craft-parts-n:hover, .craft-parts-frame a.craft-parts-n:focus { background: #ffb547; }
        .craft-parts figcaption { padding: 4px 16px 14px; }
        .craft-parts-title { margin: 0 0 6px; color: #9aa3b8; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; }
        .craft-parts-key { list-style: none; margin: 0; padding: 0; columns: 3 200px; column-gap: 24px; }
        .craft-parts-key li { break-inside: avoid; margin: 4px 0; }
        .craft-parts-key .craft-parts-n { width: 24px; height: 24px; font-size: 12px; margin-right: 8px; border: 0; }
        .craft-parts-key .unseen .craft-parts-n { background: transparent; color: #4cc9f0 !important; box-shadow: inset 0 0 0 2px #4cc9f0; }
        .craft-parts-key small { color: #9aa3b8; margin-left: 4px; }
        @media (max-width: 860px) { .rv-tiles { grid-template-columns: 1fr 1fr; } .rv-sides { grid-template-columns: 1fr; } }
    </style>
@endpush
@section('content')
    <x-page-header>
        <x-slot:title>{{$title}}</x-slot:title>
        <li><a href="/market">Market</a></li>
        <li class="active">{{$title}}</li>
    </x-page-header>
    @php($n = fn ($v) => number_format($v))
    <section class="section section-xs section-first text-md-left">

        <div class="container" id="container">
            @if($hasPicture)
                <div class="row align-items-center">
                    <div class="col-12 col-md-7 col-lg-8">
                        <p class="mk-lead">{{ $resource->description }}</p>
                    </div>
                    <div class="col-12 col-md-5 col-lg-4 mb-4 text-center resource-picture">
                        <picture>
                            <source srcset="{{ url($picture . '-still.webp') }}" media="(prefers-reduced-motion: reduce)">
                            <img src="{{ url($picture . '.webp') }}" alt="{{ $resource->name }}, turning slowly"
                                 width="400" height="400" style="width: 100%; max-width: min(320px, 70vw); height: auto;">
                        </picture>
                    </div>
                </div>
            @else
                <p class="mk-lead">{{ $resource->description }}</p>
            @endif
            @if($parts)
                {{-- A craft's subsystems (#802): numbered on the picture, listed in the key --}}
                <figure class="craft-parts" id="craft-parts">
                    <div class="craft-parts-picture"><div class="craft-parts-frame">
                        <img src="{{ $parts['image'] }}" width="{{ $parts['width'] }}" height="{{ $parts['height'] }}" loading="lazy"
                             alt="{{ $resource->name }}, with each subsystem it is built from numbered">
                        @foreach($parts['items'] as $item)
                            @foreach($item['at'] as [$x, $y])
                                <a class="craft-parts-n" href="/resources/{{ $item['id'] }}" style="left: {{ $x }}%; top: {{ $y }}%"
                                   title="{{ $item['name'] }}" aria-label="{{ $item['n'] }}: {{ $item['name'] }}">{{ $item['n'] }}</a>
                            @endforeach
                        @endforeach
                    </div></div>
                    <figcaption>
                        <p class="craft-parts-title">Built from</p>
                        <ol class="craft-parts-key">
                            @foreach($parts['items'] as $item)
                                <li @class(['unseen' => !$item['at']])>
                                    <span class="craft-parts-n" aria-hidden="true">{{ $item['n'] }}</span>
                                    <a href="/resources/{{ $item['id'] }}">{{ $item['name'] }}</a>@if($item['qty'] > 1) &times;{{ $item['qty'] }}@endif
                                    @if(!$item['at'])<small>out of sight</small>@endif
                                </li>
                            @endforeach
                        </ol>
                    </figcaption>
                </figure>
            @endif
            {{-- Its place in the manufacturing pathways (#804) --}}
            <p id="tech-map-link"><a href="{{ url('technology') }}#r{{ $resource->id }}">See what {{ $resource->name }} is made from and what it goes into on the tech map</a></p>
            {{-- At a glance (#721), the best of the book below, so they agree with its table (#744) --}}
            @php($sellTop = $book['sell'][0] ?? null)
            @php($buyTop = $book['buy'][0] ?? null)
            @php($places = fn ($side, $price) => collect($book[$side])->where('price', $price)->pluck('place')->unique()->values())
            <div class="rv-tiles" id="good-tiles">
                <div class="rv-tile"><small>Cheapest seller</small>
                    <b>{{ $sellTop ? $n($sellTop['price']) : '–' }}</b>
                    <span>{{ $sellTop ? $places('sell', $sellTop['price'])->implode(', ') . ', ' . $n(collect($book['sell'])->where('price', $sellTop['price'])->sum('units')) . ' on sale at that price' : 'nobody sells it' }}</span></div>
                <div class="rv-tile"><small>Best buyer</small>
                    <b>{{ $buyTop ? $n($buyTop['price']) : '–' }}</b>
                    <span>{{ $buyTop ? $places('buy', $buyTop['price'])->implode(', ') . ', wants ' . $n(collect($book['buy'])->where('price', $buyTop['price'])->sum('units')) . ' at that price' : 'nobody buys it' }}</span></div>
                <div class="rv-tile"><small>Traded, 7 game days</small>
                    <b>{{ $n($board['units'] ?? 0) }}</b>
                    <span>{{ $board && $board['average'] !== null ? 'average ' . $board['average'] . ' a unit' : 'no trades' }}{{ $board && $board['change_percent'] !== null ? ', ' . ($board['change_percent'] > 0 ? '+' : ($board['change_percent'] < 0 ? '−' : '')) . abs($board['change_percent']) . '% on the week before' : '' }}</span></div>
                <div class="rv-tile"><small>Resource</small><b>{{ $resource->id }}</b><span>{{ $resource->mass }} kg a unit</span></div>
            </div>

            {{-- Every open order for it (#721: moved here from the Market page) --}}
            <h2 class="h4 mt-4" id="order-book">Who sells it, and who buys it?</h2>
            <div class="rv-sides">
                @foreach(['sell' => ['Selling', 'Seller', 'Nobody sells it right now.'], 'buy' => ['Buying', 'Buyer', 'Nobody buys it right now.']] as $side => [$heading, $who, $none])
                    <div>
                        <h3 class="h6 mt-2">{{ $heading }}, best first{{ count($book[$side]) > 10 ? ': the 10 best of ' . $n(count($book[$side])) : '' }}</h3>
                        <div class="mk-scroll">
                            <table class="mk-table mk-cards" id="{{ $side }}-orders">
                                <thead><tr><th>{{ $who }}</th><th class="num">Price</th><th class="num">Units</th><th>Where</th></tr></thead>
                                <tbody>
                                @forelse(array_slice($book[$side], 0, 10) as $o)
                                    <tr>
                                        <td><a href="{{ url('spacecraft/' . $o['drone']) }}">{{ $o['drone_name'] }}</a>@if($o['house'])<span class="mk-tag">House</span>@endif</td>
                                        <td class="num" data-label="Price">{{ $n($o['price']) }} cr</td>
                                        <td class="num" data-label="Units">{{ $n($o['units']) }}</td>
                                        <td data-label="Where">{{ $o['place'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">{{ $none }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mk-src">The 10 best on each side (a phone needn't scroll past 80 sellers, #744); every order: <code>GET /api/v1/orders?side=sell&amp;resource={{ $resource->id }}</code> and <code>?side=buy</code></p>

            @if($demand || $supply)
                {{-- What the House buys and sells of it (#550, #630) --}}
                <h2 class="h4 mt-4" id="house">What does the House buy and sell of it?</h2>
                <div class="mk-scroll">
                    <table class="mk-table mk-cards" id="good-house">
                        <thead><tr><th>Where</th><th class="num">Bid now</th><th class="num">Still buying today</th><th class="num">Asks</th><th class="num">On sale now</th></tr></thead>
                        <tbody>
                        @php($supplyAt = collect($supply)->keyBy('place_name'))
                        @foreach(collect($demand)->pluck('place_name')->merge($supplyAt->keys())->unique()->sort() as $place)
                            @php($d = collect($demand)->firstWhere('place_name', $place))
                            @php($s = $supplyAt->get($place))
                            <tr>
                                <td>{{ $place }}</td>
                                <td class="num" data-label="Bid now">{{ $d ? ($d['left'] > 0 ? $n($d['price']) . ' cr' : 'no bid') : '–' }}</td>
                                <td class="num" data-label="Still buying today">{{ $d ? $n($d['left']) : '–' }}@if($d)<br><small>it buys about {{ $n($d['daily']) }} a game day</small>@endif</td>
                                <td class="num" data-label="Asks">{{ $s ? ($s['on_sale'] > 0 ? $n($s['price']) . ' cr' : 'sold out') : '–' }}</td>
                                <td class="num" data-label="On sale now">{{ $s ? $n($s['on_sale']) : '–' }}@if($s)<br><small>{{ $s['on_sale'] > $s['refill_to'] ? 'overstocked: it restocks only to ' . $n($s['refill_to']) : 'it restocks to ' . $n($s['refill_to']) }}</small>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mk-src">Data: <code>GET /api/v1/house-demand?resource={{ $resource->id }}</code> and <code>/house-supply?resource={{ $resource->id }}</code>.
                    Every good's: <a href="{{ url('market/house') }}">House demand and supply</a>.</p>
            @endif

            {{-- Its recent price and volume (#597) --}}
            <div class="row row-30 mt-4">
                <div class="col" id="recent-sales">
                    <h2 class="h4">What has it sold for?</h2>
                    @if($traded)
                        @include('partials.price-volume-chart', ['chartResource' => (int) $resource->id, 'chartDays' => 30,
                            'chartTitle' => 'Price a unit and units traded'])
                        <p style="white-space: normal">Every resource's history is on <a href="{{ url('market/history') }}">Market history</a>.</p>
                    @else
                        <p>No trades yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
