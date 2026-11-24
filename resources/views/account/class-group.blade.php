@extends('layouts.app')
@php $title = 'Class group: ' . $group['name'] ; @endphp
@section('pageTitle', $title)
@section('content')
    <x-page-header :title="$title" />
    <section class="section section-xs section-first text-md-left">
        <div class="container">
            @include('_flash_message')
            @include('account._errors')
            @if($fresh)
                <div class="alert alert-warning" style="white-space: normal">Print or copy this list now: the passwords
                    won't be shown again. You can give any pupil a new password later.</div>
                <table class="table table-sm" style="max-width: 32rem">
                    <thead><tr><th scope="col">Name</th><th scope="col">Password</th></tr></thead>
                    <tbody>
                    @foreach($group['accounts'] as $pupil)
                        <tr><td>{{ $pupil['name'] }}</td><td><code>{{ $pupil['password'] }}</code></td></tr>
                    @endforeach
                    </tbody>
                </table>
                <p><a href="{{ url('account/classes/' . $group['id']) }}">The group's page</a></p>
            @else
                @if($reset = session('reset'))
                    <div class="alert alert-info" style="white-space: normal">{{ $reset['name'] }}'s new password:
                        <code>{{ $reset['password'] }}</code> (it won't be shown again)</div>
                @endif
                <p>{{ count($group['pupils']) }} pupils. Give a pupil a new password if they lose theirs: the old one
                    stops working.</p>
                <table class="table table-sm" style="max-width: 32rem">
                    <tbody>
                    @foreach($group['pupils'] as $pupil)
                        <tr><td>{{ $pupil }}</td><td class="text-right">
                            <form method="POST" action="{{ url('account/classes/' . $group['id'] . '/reset') }}">
                                {{ csrf_field() }}
                                <input type="hidden" name="pupil" value="{{ $pupil }}">
                                <button class="button button-secondary button-sm" type="submit">New password</button>
                            </form>
                        </td></tr>
                    @endforeach
                    </tbody>
                </table>
                @if($settings ?? null)
                    {{-- Class settings (#1003): the teacher's choices, not game rules for anyone else --}}
                    <h2 class="h5 mt-4" id="class-settings">Class settings</h2>
                    <form method="POST" action="{{ url('account/classes/' . $group['id'] . '/settings') }}" style="white-space: normal">
                        {{ csrf_field() }}
                        <div><label><input type="checkbox" name="takeovers" value="1" @checked($settings['takeovers'])>
                            <b>Takeovers within the class:</b> a pupil may buy every share of a company a classmate founded, and so take over its code.</label></div>
                        <div><label><input type="checkbox" name="loans_within" value="1" @checked($settings['loans_within'])>
                            <b>Loans only within the class:</b> pupils back only loans their classmates' companies ask for, and those companies borrow only from the class.</label></div>
                        <button class="button button-secondary button-sm mt-1" type="submit">Save the settings</button>
                    </form>
                    <p class="mt-1"><small>Shares already trade only within the class.</small></p>
                @endif
                {{-- What passed between the pupils (#1003), read-only --}}
                <h2 class="h5 mt-4" id="class-log">Between your pupils, the last {{ \App\Services\ClassSettings::LOG_DAYS }} game days</h2>
                @if(!$log['trades'] && !$log['loans'] && !$log['dividends'] && !$log['payouts'])
                    <p>Nothing has passed between your pupils yet.</p>
                @else
                    <ul style="white-space: normal" v-pre>
                        @foreach($log['trades'] as $t)<li class="log-trade">{{ $t['when'] }}: {{ $t['seller'] }} sold {{ $t['buyer'] }} {{ $t['units'] }} {{ $t['company'] }} {{ \Illuminate\Support\Str::plural('share', $t['units']) }} at {{ number_format($t['price']) }}.</li>@endforeach
                        @foreach($log['loans'] as $l)<li class="log-loan">{{ $l['when'] }}: {{ $l['lender'] }} lent {{ $l['company'] }}@if($l['founder']) ({{ $l['founder'] }}'s)@endif {{ number_format($l['principal']) }} at {{ $l['rate'] }}%{{ $l['defaulted'] ? ', in default' : ($l['closed'] ? ', repaid' : '') }}.</li>@endforeach
                        @foreach($log['dividends'] as $d)<li class="log-dividend">{{ $d['company'] }} paid {{ $d['pupil'] }} {{ number_format($d['total']) }} in dividends.</li>@endforeach
                        @foreach($log['payouts'] as $p)<li class="log-payout">{{ $p['when'] }}: {{ $p['company'] }} paid out {{ number_format($p['paid']) }} to its holders.</li>@endforeach
                    </ul>
                @endif
            @endif
            <p><a href="{{ url('account') }}">Your account</a></p>
        </div>
    </section>
@endsection
