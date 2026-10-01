<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email address</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F9F9F9; color: #18181B; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent; mso-hide: all;">
        Finish setting up your Medasin account. Your verification code expires {{ $expiresInMinutes }} minutes after it was requested.
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; background-color: #F9F9F9;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <!--[if mso]>
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"><tr><td>
                <![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 560px; border: 1px solid #E4E4E7; border-radius: 16px; background-color: #FFFFFF;">
                    <tr>
                        <td align="center" style="padding: 32px 32px 28px;">
                            <img src="{{ $message->embed(public_path('images/medasin-logo.png')) }}" alt="Medasin" width="50" height="50" style="display: block; width: 50px; height: 50px; margin: 0 auto 12px; border: 0;">
                            <p style="margin: 0; color: #18181B; font-size: 20px; font-weight: 700; line-height: 28px; letter-spacing: -0.5px;">Medasin</p>
                            <p style="margin: 8px 0 0; color: #71717A; font-size: 11px; font-weight: 700; line-height: 18px; letter-spacing: 1.5px;">ACCOUNT VERIFICATION</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 0 32px 24px;">
                            <h1 style="margin: 0; color: #18181B; font-size: 28px; font-weight: 700; line-height: 36px; letter-spacing: -0.5px;">Verify your email</h1>
                            <p style="margin: 12px 0 0; color: #52525B; font-size: 15px; line-height: 24px;">You're almost ready. Use this code to verify your email address and finish setting up your Medasin account.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border: 1px solid #E4E4E7; border-radius: 12px; background-color: #F9F9F9;">
                                <tr>
                                    <td align="center" style="padding: 20px 12px;">
                                        <p style="margin: 0 0 8px; color: #71717A; font-size: 12px; line-height: 18px;">Your verification code</p>
                                        <p style="margin: 0; color: #18181B; font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 700; line-height: 44px; letter-spacing: 6px; white-space: nowrap;">{{ $code }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 20px 32px 28px;">
                            <p style="margin: 0; color: #52525B; font-size: 13px; line-height: 21px;">This code expires <strong style="color: #18181B;">{{ $expiresInMinutes }} minutes after it was requested</strong> and can only be used once.</p>
                            <p style="margin: 10px 0 0; color: #52525B; font-size: 13px; line-height: 21px;">Enter it on the verification screen to continue.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 24px 32px; border-top: 1px solid #E4E4E7;">
                            <p style="margin: 0; color: #71717A; font-size: 12px; line-height: 20px;">Keep this code private. If you did not request this code, you can ignore this email.</p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->
                <p style="margin: 20px 0 0; color: #71717A; font-size: 12px; line-height: 20px;">Medasin &middot; A calmer place for your day.</p>
            </td>
        </tr>
    </table>
</body>
</html>
