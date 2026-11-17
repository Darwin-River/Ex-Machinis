{{-- An opt-in notice (#761) as HTML, beside its plain-text twin
     (notice.blade.php): inline styles and tables, as the verification
     e-mail (#754). Colours are the site's (_tokens.scss). --}}
@php($font = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif")
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:#0b0f1a;">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $items[0]['text'] ?? $intro }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0b0f1a;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#111729; border:1px solid #263052; border-radius:8px;">
                <tr>
                    <td style="padding:24px 32px; border-bottom:1px solid #263052; font-family:{!! $font !!}; font-size:15px; font-weight:700; letter-spacing:0.18em; color:#e6e9f2;">
                        <span style="color:#ffb547;">&#9679;</span>&nbsp; EX MACHINIS
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 32px 8px; font-family:{!! $font !!}; color:#e6e9f2;">
                        <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; font-weight:700; color:#ffffff;">{{ $heading }}</h1>
                        <p style="margin:0 0 16px; font-size:16px; line-height:1.6;">Hello {{ $name }},</p>
                        <p style="margin:0 0 8px; font-size:16px; line-height:1.6;">{{ $intro }}</p>
                    </td>
                </tr>
                @foreach($items as $item)
                    <tr>
                        <td style="padding:12px 32px; font-family:{!! $font !!}; color:#e6e9f2; border-top:1px solid #263052;">
                            <p style="margin:0 0 6px; font-size:15px; line-height:1.6;">{{ $item['text'] }}</p>
                            @if($item['try'])
                                <p style="margin:0 0 6px; font-size:14px; line-height:1.6; color:#9aa3b8;"><strong style="color:#ffb547;">Try next:</strong> {{ $item['try'] }}</p>
                            @endif
                            <p style="margin:0; font-size:14px;"><a href="{{ $item['link'] }}" target="_blank" style="color:#4cc9f0;">Open it</a></p>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:20px 32px 28px; font-family:{!! $font !!}; color:#9aa3b8; border-top:1px solid #263052;">
                        <p style="margin:0 0 8px; font-size:13px; line-height:1.6;">You get this because you turned these e-mails on.
                            <a href="{{ url('account#notices') }}" target="_blank" style="color:#4cc9f0;">Change which you get</a>
                            or <a href="{{ $unsubscribe }}" target="_blank" style="color:#4cc9f0;">turn them all off</a>.</p>
                    </td>
                </tr>
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                <tr>
                    <td align="center" style="padding:20px 16px; font-family:{!! $font !!}; font-size:12px; line-height:1.6; color:#9aa3b8;">
                        Ex Machinis &middot; <a href="{{ url('/') }}" style="color:#9aa3b8;">{{ preg_replace('#^https?://#', '', url('/')) }}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
