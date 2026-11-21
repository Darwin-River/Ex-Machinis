Hello {{ $name }},

Someone asked to reset the password of your Ex Machinis account. To choose a new one, open this link within an hour:

{!! $url !!}

The link works once. Choosing a new password logs the account out everywhere else and cancels its API token.

If you didn't ask, ignore this e-mail: your password stays as it is.

Ex Machinis
{{ url('/') }}
