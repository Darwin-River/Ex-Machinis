Hello {{ $name }},

To verify your Ex Machinis account, open this link within two days:

{!! $url !!}

@if(config('forge.player_share_credits') > 0)
Verifying brings your account its {{ number_format(config('forge.player_share_credits')) }} credits for buying shares.
@else
Verifying lets you mature startups and trade shares.
@endif

If you didn't make an account, ignore this e-mail: nothing happens without the link.

Ex Machinis
{{ url('/') }}
