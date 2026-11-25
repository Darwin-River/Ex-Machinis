@extends('layouts.app')
@php $title = 'Cohort feedback'; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" :subtitle="'The AI players\' end-of-round reports and the weekly reviews (DV-04). Only you see this page.'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            <p><a href="{{ url('account#claude-players') }}">Back to your account</a>. This round is {{ $roundNow }}.</p>
            <form method="GET" action="{{ url('account/cohort-feedback') }}" class="form-inline mb-3" id="cohort-feedback-search">
                <input class="form-control mr-2 mb-2" name="q" type="search" placeholder="Words to find" value="{{ $f['q'] ?? '' }}">
                <input class="form-control mr-2 mb-2" name="persona" type="text" placeholder="Persona (C-03)" size="10" value="{{ $f['persona'] ?? '' }}">
                <input class="form-control mr-2 mb-2" name="round" type="text" placeholder="Round (2026-W48)" size="12" value="{{ $f['round'] ?? '' }}">
                <select class="form-control mr-2 mb-2" name="kind">
                    <option value="">Reports and reviews</option>
                    @foreach(\App\Services\CohortFeedback::KINDS as $k)
                        <option value="{{ $k }}" @selected(($f['kind'] ?? '') === $k)>{{ ucfirst($k) }}s only</option>
                    @endforeach
                </select>
                <button class="button button-secondary button-sm mb-2" type="submit">Search</button>
            </form>
            @forelse($entries as $e)
                <div class="mb-3 pb-2 border-bottom feedback-entry">
                    <p class="mb-1"><strong>{{ $e['persona'] }}</strong>, {{ $e['round'] }}, {{ $e['kind'] }}
                        <small class="text-muted">({{ $e['written'] }})</small>
                        @if($e['ratings'])
                            <small>· @foreach($e['ratings'] as $name => $n){{ $name }} {{ $n }}@if(!$loop->last), @endif @endforeach</small>
                        @endif
                    </p>
                    <p class="mb-1" style="white-space: pre-line"><em>Experience:</em> {{ $e['experience'] }}</p>
                    <p class="mb-0" style="white-space: pre-line"><em>Recommendations:</em> {{ $e['recommendations'] }}</p>
                </div>
            @empty
                <p>No entries match.</p>
            @endforelse
        </div>
    </section>
@endsection
