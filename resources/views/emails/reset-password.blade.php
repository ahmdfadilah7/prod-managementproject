<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Reset Password</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.6; color: #334155;">
    <p>Halo {{ $userName }},</p>
    <p>Anda menerima email ini karena ada permintaan reset password untuk akun ManagementPro Anda.</p>
    <p>
        <a href="{{ $resetUrl }}" style="display: inline-block; padding: 10px 18px; background: #4f46e5; color: #fff; text-decoration: none; border-radius: 8px;">
            Reset password
        </a>
    </p>
    <p style="font-size: 14px; color: #64748b;">
        Jika tombol tidak berfungsi, salin tautan berikut ke browser:<br>
        <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
    </p>
    <p style="font-size: 14px; color: #64748b;">Jika Anda tidak meminta reset password, abaikan email ini.</p>
</body>
</html>
