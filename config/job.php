<?php

return [
    // Rayon (km) dans lequel un artisan / un transporteur voit une demande.
    'radius_km' => (float) env('JOB_RADIUS_KM', 15),

    // Vitesse moyenne en ville pour les ETA artisan et transporteur.
    'speed_kmh' => (float) env('JOB_SPEED_KMH', 25),

    // Coefficient trajet reel / vol d'oiseau.
    'road_factor' => (float) env('JOB_ROAD_FACTOR', 1.35),

    // Tarif d'une course artisan -> client : base + prix/km, plancher.
    'ride_base_cents' => (int) env('JOB_RIDE_BASE_CENTS', 1000),
    'ride_per_km_cents' => (int) env('JOB_RIDE_PER_KM_CENTS', 400),
    'ride_min_cents' => (int) env('JOB_RIDE_MIN_CENTS', 1500),

    // Au-dela, une position GPS n'est plus consideree comme live.
    'stale_seconds' => (int) env('JOB_LOCATION_STALE_SECONDS', 90),

    // Offre directe a un transporteur habituel : il a la priorite, puis la course s'ouvre a tous.
    'direct_offer_minutes' => (int) env('JOB_DIRECT_OFFER_MINUTES', 10),
];
