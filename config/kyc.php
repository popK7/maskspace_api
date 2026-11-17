<?php

/*
 * Verification des pieces justificatives des prestataires (KYC).
 *
 * Un prestataire dont le role figure dans `required` ne peut ni passer en ligne
 * ni accepter de demande tant qu'un admin n'a pas valide toutes ses pieces.
 */
return [
    'enforce' => (bool) env('KYC_ENFORCE', true),

    'max_kb' => (int) env('KYC_MAX_KB', 8192),

    'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    // Libelles affiches dans l'application.
    'types' => [
        'id_card' => 'Pièce d’identité (CNI, passeport ou titre de séjour)',
        'driving_license' => 'Permis de conduire',
        'vehicle_registration' => 'Carte grise du véhicule',
        'insurance' => 'Attestation d’assurance du véhicule',
        'medical_license' => 'Inscription à l’Ordre des médecins',
        'diploma' => 'Diplôme ou certificat professionnel',
        'business_registration' => 'Registre de commerce ou statut d’auto-entrepreneur',
    ],

    // Pieces exigees par role (roles absents = pas de verification).
    'required' => [
        'driver' => ['id_card', 'driving_license', 'vehicle_registration', 'insurance'],
        'livreur' => ['id_card', 'driving_license'],
        'artisan' => ['id_card'],
        'changeur' => ['id_card', 'business_registration'],
        'medecin' => ['id_card', 'medical_license'],
        'beaute' => ['id_card', 'diploma'],
        'demarcheur' => ['id_card', 'business_registration'],
    ],
];
