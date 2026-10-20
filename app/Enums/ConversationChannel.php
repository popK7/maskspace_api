<?php

namespace App\Enums;

/**
 * Fil de discussion rattache a une demande.
 *
 * Commande boutique / resto :
 *   customer_shop     client ↔ commerce
 *   shop_courier      commerce ↔ livreur
 *   customer_courier  client ↔ livreur
 *
 * Intervention artisanale :
 *   customer_artisan  client ↔ artisan
 *   artisan_driver    artisan ↔ transporteur
 *   customer_driver   client ↔ transporteur
 *
 * Course sans job (futur) :
 *   passenger_driver  passager ↔ chauffeur
 */
enum ConversationChannel: string
{
    case CustomerShop = 'customer_shop';
    case ShopCourier = 'shop_courier';
    case CustomerCourier = 'customer_courier';
    case CustomerArtisan = 'customer_artisan';
    case ArtisanDriver = 'artisan_driver';
    case CustomerDriver = 'customer_driver';
    case PassengerDriver = 'passenger_driver';
    case CustomerChangeur = 'customer_changeur';
    case ChangeurDriver = 'changeur_driver';
    case CustomerHost = 'customer_host';
    case CustomerDemarcheur = 'customer_demarcheur';
    case CustomerMedecin = 'customer_medecin';
    case CustomerBeaute = 'customer_beaute';
}
