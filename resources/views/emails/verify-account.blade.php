Hello {{ $name }},

To verify your Ex Machinis account, open this link within two days:

{!! $url !!}

Verifying brings your account its {{ number_format(config('forge.player_share_credits')) }} credits for buying shares.

If you didn't make an account, ignore this e-mail: nothing happens without the link.

Ex Machinis
{{ url('/') }}
