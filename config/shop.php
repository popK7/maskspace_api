<?php

return [
    // Commission de la plateforme sur la valeur des produits (apres remise), en %.
    'commission_rate' => (float) env('SHOP_COMMISSION_RATE', 10),

    // Nombre maximum de livraisons simultanees pour un livreur.
    'livreur_max_active' => (int) env('LIVREUR_MAX_ACTIVE', 3),

    // Duree (minutes) pendant laquelle une course proposee a un livreur habituel lui est reservee.
    // Passe ce delai sans reponse, elle redevient visible de tous les livreurs.
    'direct_offer_minutes' => (int) env('SHOP_DIRECT_OFFER_MINUTES', 10),

    // Vitesse moyenne (km/h) d'un livreur en ville, pour estimer l'arrivee affichee au client.
    'courier_speed_kmh' => (float) env('SHOP_COURIER_SPEED_KMH', 22),

    // Duree (heures) d'une ouverture exceptionnelle activee hors des horaires habituels.
    'force_open_hours' => (int) env('SHOP_FORCE_OPEN_HOURS', 4),

    // Durée (heures) du mode rush d'un restaurant avant extinction automatique.
    'rush_hours' => (int) env('SHOP_RUSH_HOURS', 2),

    // Base des URL medias (CDN / S3). Vide : chemins relatifs /storage/... (disque public local).
    'media_url' => env('MEDIA_URL'),

    // Images : dimension max (px, plus grand cote) et taille max d'upload (Ko).
    'image_max_dimension' => 1280,

    // Miniature generee pour les listes (plus grand cote, px).
    'thumb_dimension' => 480,
    'image_max_kb' => 5120,
    'product_max_images' => 6,

    // Photos d'un logement Keur (appartements / chambres).
    'lodging_max_photos' => 8,
];
