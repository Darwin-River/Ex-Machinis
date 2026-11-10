@extends('layouts.app')
@php $title = 'Keep the Lights On' ; @endphp
@section('pageTitle', $title)
@push('styles')
    <style>
        .support-page, .support-page p, .support-page li, .support-page h2,
        .support-page td, .support-page th { text-align: left !important; }
        .support-page ul li { display: list-item !important; }
        .support-page ul > li::before { content: none !important; }
        .support-page ul { list-style: disc !important; padding-left: 1.5rem !important; margin: 0.75rem 0; }
        .support-page table { margin: 1rem 0; border-collapse: collapse; width: 100%; max-width: 36rem; }
        .support-page th, .support-page td { border: 1px solid rgba(255, 255, 255, 0.2); padding: 0.4rem 0.75rem; }
        .support-page td.amount, .support-page th.amount { text-align: right !important; white-space: nowrap; }
        .support-page .meter { max-width: 36rem; height: 1.25rem; border-radius: 0.625rem;
            background: rgba(255, 255, 255, 0.12); overflow: hidden; margin: 0.5rem 0; }
        .support-page .meter-fill { height: 100%; background: #29abe0; }
        .support-page .kofi-button { display: inline-block; margin: 1rem 0; padding: 0.75rem 1.5rem;
            border-radius: 0.5rem; background: #29abe0; color: #fff !important; font-weight: 700; }
        .support-page .kofi-button:hover { background: #1f8fbd; }
    </style>
@endpush
@section('content')
    <!-- Breadcrumbs-->
    <section class="breadcrumbs-modern box-transform-wrap bg-image context-dark"
             style="background-image: url(/images/bg-breadcrumbs.jpg);">
        <div class="container">
            <div class="breadcrumbs-modern-body">
                <h1 class="breadcrumbs-modern-title">{{ $title }}</h1>
            </div>
            <ul class="breadcrumbs-path breadcrumbs-modern-path">
                <li><a href="/">Home</a></li>
                <li class="active">{{ $title }}</li>
            </ul>
        </div>
        <div class="box-transform" style="background-image: url(/images/bg-breadcrumbs.jpg);"></div>
        <div class="comets comets-left"></div>
        <div class="comets comets-center"></div>
        <div class="comets comets-top"></div>
    </section>
    <section class="section section-lg bg-default">
        <div class="container">
            <div class="row">
                {{-- config/support.php (#522) --}}
                <div class="col-md-12 col-lg-10 col-xl-9 single-post support-page">
                    <p>Ex Machinis is free to play, with no accounts, no ads and nothing to buy. If you enjoy it, you can chip in to keep the servers running. Support pays for running costs only; anything beyond them goes back into the game.</p>

                    <h2>What it costs each month</h2>
                    <table>
                        <tbody>
                        @foreach ($costs as $what => $amount)
                            <tr><td>{{ $what }}</td><td class="amount">${{ number_format($amount) }}</td></tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                            <tr><th>Total</th><th class="amount">${{ number_format($total) }}</th></tr>
                        </tfoot>
                    </table>

                    <h2>How much is covered</h2>
                    <div class="meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                        <div class="meter-fill" style="width: {{ $percent }}%"></div>
                    </div>
                    <p>
                        {{ $percent }}% covered: ${{ number_format($covered) }} of ${{ number_format($total) }}@if ($month !== '') in {{ $month }}@endif.
                    </p>

                    @if ($kofi !== '')
                        <a class="kofi-button" href="https://ko-fi.com/{{ rawurlencode($kofi) }}" rel="noopener" target="_blank">Support us on Ko-fi</a>
                        <p>Give once, or monthly. Any amount helps.</p>
                    @else
                        <p><b>Our Ko-fi page is coming soon.</b> Until then, the free ways below help just as much.</p>
                    @endif

                    <h2>Free ways to help</h2>
                    <ul>
                        <li><a href="{{ url('release') }}">Release a drone</a> and tell a friend about the game.</li>
                        <li>Share a <a href="{{ url('blog') }}">strategy blog</a> post with someone learning to code.</li>
                        <li>Report a bug or suggest a change on <a href="https://github.com/Darwin-River/Ex-Machinis/issues">GitHub</a>.</li>
                    </ul>

                    <p><b>Thank you for keeping the lights on!</b></p>
                </div>
            </div>
        </div>
    </section>
@endsection
