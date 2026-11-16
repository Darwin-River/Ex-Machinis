{{--
    What the event marks on a page's time charts mean (#720). Takes
    $company (true: the charts also mark the company's own events) and
    optionally $companyId for its API call.
--}}
<p class="ex-marks-key">Marks on the charts:
    <i style="border-color: #e0a43a"></i>a rule change,
    <i style="border-color: #4fb38f"></i>a repair,
    @if(!empty($company))<i class="ex-company" style="border-color: #c9cbd6"></i>the company's own (it matured, a code push, shares sold
    to the bank or a big trade), @endif
    and <i class="ex-band"></i>an outage, when the world stopped.
    Hover over a mark, or tap it, for what happened.
    Data: <code>GET /api/v1/world-events</code>@if(!empty($companyId)) and <code>GET /api/v1/companies/{{ $companyId }}/events</code>@endif</p>
