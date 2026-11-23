@extends('layouts.app')
@section('pageTitle', 'Loans')
@section('content')
    {{-- Loans (#928): public companies' code asks to borrow; players with enough credits back the requests --}}
    <x-page-header :title="'Loans'" />
    <section class="section section-xs section-first text-md-left">
        <div class="container" id="container">
            <div style="white-space: normal; margin-bottom: 1em">
                A public company's code can ask to borrow: <code>request_loan(amount, rate, due_in)</code>. Its code is locked and
                anyone can read it, so read it before you lend: what it will do with the money, including paying it straight out,
                is all there. Each backing is lent to the company at once. On the due date it repays what you lent plus the rate
                (simple interest for the whole loan); it can repay early with the interest due so far. If it can't repay in full,
                every credit it receives goes to its lenders, in proportion to what each is owed, until they are paid, and its
                dividends stop meanwhile. You need at least <b>{{ number_format($min) }}</b> credits to back new loans.
            </div>
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

            <h3 class="mt-3">Asking to borrow</h3>
            <div class="table-custom-responsive">
                <table class="table-ticket" id="loan-requests">
                    <tr><th>Company</th><th class="num">Asks</th><th class="num">Still to back</th><th class="num">Rate</th>
                        <th class="num">A game year</th><th>Due</th>@if($credits !== null)<th>Back it</th>@endif</tr>
                    @forelse($requests as $r)
                        <tr>
                            <td><a href="{{ url('companies/' . $r['company']) }}#loans">{{ $r['name'] }}</a> <small>(<a href="{{ url('companies/' . $r['company']) }}#code">its code</a>)</small></td>
                            <td class="num">{{ number_format($r['amount']) }}</td><td class="num">{{ number_format($r['left']) }}</td>
                            <td class="num">{{ $r['rate'] }}%</td><td class="num">{{ $r['per_year'] }}%</td>
                            <td>{{ $r['due'] }} <small>(in {{ $r['due_in'] }} game days)</small></td>
                            @if($credits !== null)
                                <td>@if($credits >= $min)
                                    <form method="POST" action="{{ url('loans/' . $r['id'] . '/back') }}" class="form-inline">{{ csrf_field() }}
                                        <input class="form-control mr-2" name="credits" type="number" min="1" max="{{ min($r['left'], $credits) }}" value="{{ min($r['left'], $credits) }}" style="width: 8em" aria-label="Credits to lend">
                                        <button class="button button-secondary button-sm" type="submit">Lend</button></form>
                                @else <small>{{ number_format($min - $credits) }} more credits to lend</small> @endif</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7">No company is asking to borrow now.</td></tr>
                    @endforelse
                </table>
            </div>

            @if($mine !== null)
                <h3 class="mt-4" id="my-loans">Your loans</h3>
                <p>Lent {{ number_format($mine['lent']) }} credits; interest earned {{ number_format($mine['interest']) }};
                    still owed to you {{ number_format($mine['outstanding']) }}; defaults {{ $mine['defaults'] }}.</p>
                @if(count($mine['loans']))
                    <div class="table-custom-responsive">
                        <table class="table-ticket" id="my-loan-list">
                            <tr><th>Company</th><th class="num">Lent</th><th class="num">Rate</th><th class="num">Owed</th><th class="num">Repaid</th><th>Due</th><th></th></tr>
                            @foreach($mine['loans'] as $l)
                                <tr><td><a href="{{ url('companies/' . $l['company']) }}">{{ $l['name'] }}</a></td>
                                    <td class="num">{{ number_format($l['principal']) }}</td><td class="num">{{ $l['rate'] }}%</td>
                                    <td class="num">{{ number_format($l['owed']) }}</td><td class="num">{{ number_format($l['repaid']) }}</td>
                                    <td>{{ $l['due'] }}</td><td>{{ $l['closed'] ? 'repaid' : ($l['defaulted'] ? 'in default' : 'open') }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            @endif
            <p class="ex-src">Data: <code>GET /api/v1/loans</code>, <code>POST /api/v1/loans/{id}/back</code>, <code>GET /api/v1/account/loans</code></p>
        </div>
    </section>
@endsection
