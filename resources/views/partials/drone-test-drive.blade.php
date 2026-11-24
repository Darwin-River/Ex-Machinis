{{-- A drone script's test drive (#1007): what the draft would do, run against the world now; nothing changed --}}
@if($drive = session('drone_test_drive'))
    <div class="alert alert-info mt-2" id="drone-test-drive" style="white-space: normal">
        <b>Test drive</b> of your draft on {{ $drive['drone_name'] }}, from {{ $drive['place'] }}, against the world as it is now
        (nothing was sent, bought, sold or saved). Times are game hours:minutes into the drive.
        @if($drive['lines'])
            <ul class="mb-1" v-pre>@foreach($drive['lines'] as $line)<li>{{ $line }}</li>@endforeach</ul>
        @else
            <p class="mb-1">It would do nothing.</p>
        @endif
        @if($drive['error'])<p class="mb-1" v-pre><b>Error:</b> {{ $drive['error'] }}</p>@endif
        <p class="mb-0"><small>It stopped because {{ $drive['why'] }}. A test drive can't show other drones and players
            reacting, prices or deposits changing over time, or what an order it posts would bring later: see the manual,
            <a href="{{ url('manual#test-driving-your-code') }}">Test driving your code</a>.</small></p>
    </div>
@endif
