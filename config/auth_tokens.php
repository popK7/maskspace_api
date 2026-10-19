<?php

return [
    // Duree de vie du refresh token opaque (jours). L'access token JWT est regle par JWT_TTL (minutes).
    'refresh_ttl_days' => (int) env('REFRESH_TOKEN_TTL_DAYS', 30),

    // Codes a usage unique (reset mot de passe / verification e-mail).
    'otp_ttl_minutes' => (int) env('OTP_TTL_MINUTES', 15),
    'otp_max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    // Delai minimal entre deux envois de code pour un meme e-mail (secondes).
    'otp_resend_cooldown' => (int) env('OTP_RESEND_COOLDOWN', 60),
];
