{{-- The account verification mail as HTML (#754), beside its plain-text twin
     (verify-account.blade.php). Mail clients ignore stylesheets, so every
     style is inline and the layout is tables; the name is text, not an image,
     so it reads with images blocked. Colours are the site's (_tokens.scss). --}}
@php
    $credits = number_format(config('forge.player_share_credits'));
    $font = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>Verify your Ex Machinis account</title>
</head>
<body style="margin:0; padding:0; background-color:#0b0f1a;">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">Open the link within two days to verify your account@if(config('forge.player_share_credits') > 0) and get your {{ $credits }} credits for buying shares@endif.</div>
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
                        <h1 style="margin:0 0 16px; font-size:24px; line-height:1.3; font-weight:700; color:#ffffff;">Verify your account</h1>
                        <p style="margin:0 0 16px; font-size:16px; line-height:1.6;">Hello {{ $name }},</p>
                        <p style="margin:0 0 24px; font-size:16px; line-height:1.6;">Confirm this is your e-mail address to finish making your Ex Machinis account. @if(config('forge.player_share_credits') > 0)Verifying brings your account its <strong style="color:#ffb547;">{{ $credits }} credits</strong> for buying shares.@else Verifying lets you mature startups and trade shares.@endif</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 32px 8px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" bgcolor="#ffb547" style="border-radius:6px;">
                                    <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:14px 32px; font-family:{!! $font !!}; font-size:16px; font-weight:700; color:#0b0f1a; text-decoration:none; border-radius:6px;">Verify my account</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 32px; font-family:{!! $font !!}; color:#9aa3b8;">
                        <p style="margin:0 0 16px; font-size:14px; line-height:1.6; text-align:center;">The button works for two days.</p>
                        <p style="margin:0 0 8px; font-size:13px; line-height:1.6;">If the button doesn't work, copy this link into your browser:</p>
                        <p style="margin:0 0 24px; font-size:13px; line-height:1.5; word-break:break-all;"><a href="{{ $url }}" target="_blank" style="color:#4cc9f0;">{{ $url }}</a></p>
                        <p style="margin:0; font-size:13px; line-height:1.6;">If you didn't make an account, ignore this e-mail: nothing happens without the link.</p>
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
