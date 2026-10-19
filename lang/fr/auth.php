<?php

return [
    'failed' => 'Identifiants incorrects. Vérifiez votre e-mail et votre mot de passe.',
    'password' => 'Le mot de passe est incorrect.',
    'throttle' => 'Trop de tentatives. Réessayez dans :seconds secondes.',
    'suspended' => 'Ce compte est suspendu. Contactez le support.',
    'forbidden_role' => 'Votre rôle ne vous permet pas d\'accéder à cette ressource.',
    'invalid_refresh_token' => 'Session expirée. Veuillez vous reconnecter.',
    'invalid_code' => 'Code invalide ou expiré.',
    'unauthenticated' => 'Authentification requise.',
    'email_unverified' => 'Votre adresse e-mail n\'est pas vérifiée.',

    'messages' => [
        'logged_out' => 'Déconnexion effectuée.',
        'logged_out_all' => 'Toutes les sessions ont été fermées.',
        'reset_link_sent' => 'Si un compte correspond à cette adresse, un code de réinitialisation vient d\'être envoyé.',
        'password_reset' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.',
        'password_changed' => 'Mot de passe modifié.',
        'verification_sent' => 'Un nouveau code de vérification a été envoyé.',
        'email_verified' => 'Adresse e-mail vérifiée.',
        'account_deleted' => 'Compte supprimé.',
    ],

    'mail' => [
        'greeting' => 'Bonjour :name,',
        'reset_subject' => ':app — Réinitialisation du mot de passe',
        'reset_intro' => 'Voici votre code de réinitialisation du mot de passe :',
        'verify_subject' => ':app — Vérification de votre e-mail',
        'verify_intro' => 'Voici votre code de vérification d\'adresse e-mail :',
        'expires' => 'Ce code expire dans :minutes minutes.',
        'ignore_reset' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.',
    ],
];
