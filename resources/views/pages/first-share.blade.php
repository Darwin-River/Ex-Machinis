@extends('layouts.app')
@php $title = 'Your first share' ; @endphp
@section('pageTitle', $title)
@push('styles')
<style>
    /* One card, one button (#1044) */
    .step-card { background: #111729; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; max-width: 34rem; white-space: normal; }
    .step-card.is-receipt { border-color: #7ee2a8; }
    .step-card.is-next { border-color: #4cc9f0; box-shadow: 0 0 0 1px #4cc9f0 inset; }
    .step-card .lbl { font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #4cc9f0; }
    .step-card.is-receipt .lbl { color: #7ee2a8; }
    .step-card dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; margin: 10px 0 12px; }
    .step-card dt { color: #9aa3b8; font-weight: 400; }
    .step-card dd { margin: 0; font-variant-numeric: tabular-nums; }
    .step-rule { color: #9aa3b8; font-size: 14px; max-width: 40em; white-space: normal; }
    .step-stack { display: grid; gap: 16px; }
</style>
@endpush
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container step-stack">
            @if($claimed)
                {{-- Claiming its shares (#1040) brought the player here --}}
                <div class="step-card is-receipt" id="claimed">
                    <div class="lbl">Done &middot; you own a company</div>
                    <p class="mb-0 mt-1"><b>{{ $claimed['name'] }}</b> has matured: it repaid the bank {{ number_format($claimed['repaid']) }} credits,
                        and its {{ number_format($claimed['shares']) }} shares are yours. What it pays out comes to you. You can invest now too.</p>
                </div>
            @endif
            @include('_flash_message')
            @include('partials.ladder', ['here' => 'invest', 'path' => $path])

            @if($bought)
                <div class="step-card is-receipt" id="first-share-done">
                    <div class="lbl">Done &middot; step 7</div>
                    <p class="mb-0 mt-1">You own a piece of another player's company: what it pays out, you get your share of.
                        See your shares on <a href="{{ url('account') }}">your account</a>.</p>
                </div>
                <div class="step-card is-next" id="first-share-next">
                    <div class="lbl">Next &middot; step 8</div>
                    <p class="mb-2 mt-1"><b>{{ $next['title'] }}</b>: {{ $next['text'] }}</p>
                    <a class="button button-primary" href="{{ $next['url'] }}">{{ $next['action'] }} &rarr;</a>
                </div>
            @else
                <h2 class="h3 mb-0">Own a piece of someone else's company</h2>
                @if(!$user)
                    <p class="mb-0" style="white-space: normal; max-width: 44em">Players buy shares in each other's companies and earn what they pay out.
                        It opens once you own a matured company of your own. <a href="{{ url('try') }}">Start with a drone</a>.</p>
                @elseif(!$mayInvest)
                    <div class="step-card" id="invest-locked">
                        <div class="lbl">Opens when you own a company</div>
                        <p class="mb-2 mt-1">You can buy shares once you have matured a company of your own: grow a startup to its mark,
                            then press <b>Claim my {{ \App\Services\Startups::SHARES }} shares</b>. Then the shares you hold earn you money,
                            and you can invest them in others.</p>
                        <a class="button button-primary" href="{{ url('account') }}#startups">Your startups &rarr;</a>
                    </div>
                @endif
                @if($pick)
                    <div class="step-card" id="first-share">
                        <div class="lbl">One share</div>
                        <h3 class="h4 mb-0 mt-1"><a href="{{ url('companies/' . $pick['company']) }}">{{ $pick['name'] }}</a></h3>
                        <dl>
                            <dt>One share now</dt><dd>{{ number_format($pick['price']) }} credits</dd>
                            <dt>Paid out a share</dt><dd>{{ number_format($pick['paid']) }} credits over the last {{ \App\Services\FirstSteps::DAYS }} game days</dd>
                            <dt>Its code</dt><dd><a href="{{ url('companies/' . $pick['company']) }}#code">read it before you buy</a></dd>
                        </dl>
                        @if($user && $mayInvest)
                            <form method="POST" action="{{ url('companies/' . $pick['company'] . '/share-now') }}">
                                {{ csrf_field() }}<input type="hidden" name="side" value="buy"><input type="hidden" name="units" value="1">
                                <button class="button button-primary" type="submit" id="buy-first-share">Buy 1 share</button>
                            </form>
                        @endif
                    </div>
                @else
                    <p class="mb-0" id="no-share">No company has a share for sale right now. Look again soon, or watch the <a href="{{ url('market/shares') }}">stock exchange</a>.</p>
                @endif
                <p class="step-rule mb-0" id="pick-rule">Picked by one rule: {{ \App\Services\FirstSteps::SHARE_RULE }}
                    Every company is on the <a href="{{ url('market/shares') }}">stock exchange</a>.</p>
            @endif
        </div>
    </section>
@endsection
