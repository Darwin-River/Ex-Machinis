@extends('layouts.app')
@php $title = 'Your first loan' ; @endphp
@section('pageTitle', $title)
@push('styles')
<style>
    /* One card, one button (#1044) */
    .step-card { background: #111729; border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; max-width: 34rem; white-space: normal; }
    .step-card.is-receipt { border-color: #7ee2a8; }
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
            @include('_flash_message')
            @include('partials.ladder', ['here' => 'lend', 'path' => $path])
            @php($lent = \App\Services\Pipeline::step($path, 'lend')['done'] && $user)
            @if($lent && session('success'))
                <div class="step-card is-receipt" id="first-loan-done">
                    <div class="lbl">Done &middot; every step</div>
                    <p class="mb-0 mt-1">You are a lender: the company repays you with interest when the loan is due, before its shareholders
                        are paid. You have climbed the whole ladder. From here the game is yours: found more startups, build a
                        <a href="{{ url('account') }}#trade-script">trading script</a>, or read the <a href="{{ url('blog') }}">strategy blog</a>.</p>
                </div>
            @endif
            <h2 class="h3 mb-0">Lend to a company and earn its interest</h2>
            @if($pick)
                <div class="step-card" id="first-loan">
                    <div class="lbl">One loan request</div>
                    <h3 class="h4 mb-0 mt-1"><a href="{{ url('companies/' . $pick['company']) }}">{{ $pick['name'] }}</a></h3>
                    <dl>
                        <dt>It asks for</dt><dd>{{ number_format($pick['left']) }} more credits, of {{ number_format($pick['amount']) }}</dd>
                        <dt>Interest</dt><dd>{{ rtrim(rtrim(number_format($pick['rate'], 2), '0'), '.') }}% for the whole loan, {{ $pick['term_days'] }} game days</dd>
                        <dt>Due</dt><dd>{{ $pick['due'] }}</dd>
                        <dt>Paid out a share</dt><dd>{{ number_format($pick['paid']) }} credits over the last {{ \App\Services\FirstSteps::DAYS }} game days</dd>
                    </dl>
                    @if($user && $credits >= $min)
                        <form method="POST" action="{{ url('loans/' . $pick['id'] . '/back') }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center">
                            {{ csrf_field() }}
                            <label for="loan-credits" class="mb-0">Lend</label>
                            <input class="form-control" id="loan-credits" name="credits" type="number" min="1" max="{{ min($pick['left'], $credits) }}"
                                   value="{{ min($pick['left'], 1000, $credits) }}" style="width: 8em">
                            <span>credits</span>
                            <button class="button button-primary" type="submit" id="back-first-loan">Back this loan</button>
                        </form>
                    @elseif($user)
                        <p class="mb-0" id="loan-short">You need {{ number_format($min) }} credits in your account to lend; you have {{ number_format($credits) }}.
                            Your account's credits come from what your companies pay out and the shares you sell.</p>
                    @else
                        <p class="mb-0">Players with {{ number_format($min) }} credits back companies' loan requests. <a href="{{ url('try') }}">Start with a drone</a>.</p>
                    @endif
                </div>
            @else
                <p class="mb-0" id="no-loan">No company is asking to borrow right now. Public companies' code asks for loans: look again soon.</p>
            @endif
            <p class="step-rule mb-0" id="pick-rule">Picked by one rule: {{ \App\Services\FirstSteps::LOAN_RULE }}
                Every open request is in the <a href="{{ url('market/loans') }}">loan book</a>.</p>
        </div>
    </section>
@endsection
