<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New sign-in to your account</title>
</head>
{{-- Inline styles throughout: email clients strip <style> blocks unpredictably. --}}
<body style="margin:0; padding:0; background-color:#faf6f0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#faf6f0; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:16px; overflow:hidden; font-family:'Segoe UI', Arial, sans-serif;">
                    <tr>
                        <td style="background-color:#ff8b2b; padding:24px 32px;">
                            <p style="margin:0; font-size:19px; font-weight:700; color:#ffffff;">Click n Chick</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 8px; font-size:18px; font-weight:600; color:#292929;">
                                New sign-in to your account
                            </p>

                            <p style="margin:0 0 24px; font-size:14px; line-height:1.6; color:#6f6b68;">
                                @if ($firstName)Hi {{ $firstName }}, someone @else Someone @endif just signed in
                                to your Click n Chick account from a device we have not seen before.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#faf6f0; border:1px solid #f0e6da; border-radius:12px; padding:16px 20px;">
                                <tr>
                                    <td style="padding:4px 0; font-size:13px; color:#8d8884;">Device</td>
                                    <td style="padding:4px 0; font-size:13px; font-weight:600; color:#412111; text-align:right;">{{ $deviceName }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; font-size:13px; color:#8d8884;">IP address</td>
                                    <td style="padding:4px 0; font-size:13px; font-weight:600; color:#412111; text-align:right;">{{ $ipAddress }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; font-size:13px; color:#8d8884;">When</td>
                                    <td style="padding:4px 0; font-size:13px; font-weight:600; color:#412111; text-align:right;">{{ $seenAt }}</td>
                                </tr>
                            </table>

                            <p style="margin:24px 0 0; font-size:13px; line-height:1.6; color:#6f6b68;">
                                <strong style="color:#292929;">Was this you?</strong> Then nothing needs doing &mdash;
                                you can ignore this message.
                            </p>

                            <p style="margin:12px 0 0; font-size:13px; line-height:1.6; color:#6f6b68;">
                                <strong style="color:#292929;">If it was not</strong>, open <em>Your devices</em> from
                                your account menu, sign that device out, and change your password.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color:#faf6f0; padding:16px 32px; text-align:center;">
                            <p style="margin:0; font-size:12px; color:#a39f9b;">
                                You are receiving this because it is a security alert for your account.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
