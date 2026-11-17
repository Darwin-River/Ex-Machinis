Hello {{ $name }},

{{ $intro }}

@foreach($items as $item)
- {!! $item['text'] !!}
@if($item['try'])
  Try next: {!! $item['try'] !!}
@endif
  {!! $item['link'] !!}

@endforeach
You get this because you turned these e-mails on. Change which you get on your account page: {!! url('account#notices') !!}
Turn them all off: {!! $unsubscribe !!}

Ex Machinis
{{ url('/') }}
