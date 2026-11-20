{{-- The market pages' tab bar (#744): Goods, House, History, Shares and the Trading desk (#779), the page you are on marked --}}
@php($mtHere = request()->path())
<nav class="mt-tabs" aria-label="Market pages">
    @foreach(['market' => 'Goods', 'market/house' => 'House', 'market/history' => 'History', 'market/shares' => 'Stock exchange', 'market/trade' => 'Trading desk'] as $mtPath => $mtLabel)
        <a href="{{ url($mtPath) }}" @if($mtHere === $mtPath) aria-current="page" @endif>{{ $mtLabel }}</a>
    @endforeach
</nav>
{{-- In the page's head (#830): Vue on #app drops <style> tags inside it --}}
@once
@push('styles')
<style>
    .mt-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 1rem; border-bottom: 1px solid #263052; padding-bottom: 6px; }
    .mt-tabs a { padding: 4px 12px; border-radius: 99px; color: #c3c9d8 !important; text-decoration: none; border: 1px solid transparent; }
    .mt-tabs a:hover { border-color: #263052; }
    .mt-tabs a[aria-current="page"] { background: #ffb547; color: #1a1200 !important; font-weight: 700; }
    /* In the page header, under the title (#840) */
    .page-tabs .mt-tabs{ margin: .9rem 0 0; border-bottom: 0; padding-bottom: 0; }
    .page-tabs .mt-tabs a:not([aria-current]) { background: rgba(11, 15, 26, .55); }
</style>
@endpush
@endonce
