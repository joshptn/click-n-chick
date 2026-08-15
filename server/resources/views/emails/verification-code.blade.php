<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email</title>
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
                            <p style="margin:0 0 8px; font-size:18px; font-weight:600; color:#292929;">Verify your email address</p>

                            <p style="margin:0 0 24px; font-size:14px; line-height:1.6; color:#6f6b68;">
                                Enter this code to finish creating your account.
                            </p>

                            <div style="background-color:#faf6f0; border:1px solid #f0e6da; border-radius:12px; padding:20px; text-align:center;">
                                <span style="font-size:32px; font-weight:700; letter-spacing:8px; color:#412111;">{{ $code }}</span>
                            </div>

                            <p style="margin:24px 0 0; font-size:13px; line-height:1.6; color:#6f6b68;">
                                The code expires in {{ $expiryMinutes }} minutes. Do not share it with anyone &mdash;
                                we will never ask you for it.
                            </p>

                            <p style="margin:16px 0 0; font-size:13px; line-height:1.6; color:#6f6b68;">
                                If you did not sign up for Click n Chick, you can ignore this message.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 32px 28px;">
                            <p style="margin:0; font-size:12px; color:#a39f9b; border-top:1px solid #f0e6da; padding-top:16px;">
                                BES House of Chicken &mdash; this is an automated message, please do not reply.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
