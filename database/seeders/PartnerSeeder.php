<?php

namespace Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;

class PartnerSeeder extends Seeder
{
    /**
     * One partner per seeded accommodation — realistic contact details so the
     * listing detail page has something real to show and the inquiry form has
     * somewhere to send notifications.
     */
    public function run(): void
    {
        $partners = [
            ['name' => 'Olive Grove Guesthouse', 'email' => 'stay@olivegrovewindhoek.com', 'phone' => '+264 61 239 199', 'website' => 'https://www.olivegrove-namibia.com', 'instagram' => 'https://instagram.com/olivegroveguesthouse'],
            ['name' => 'Etosha Safari Lodge', 'email' => 'bookings@etoshasafarilodge.com', 'phone' => '+264 67 229 100', 'website' => 'https://www.gondwana-collection.com/etosha-safari-lodge', 'instagram' => 'https://instagram.com/gondwanacollection'],
            ['name' => 'Ongava Tented Camp', 'email' => 'res@ongava.com', 'phone' => '+264 61 274 500', 'website' => 'https://www.ongava.com', 'instagram' => 'https://instagram.com/ongavagamereserve'],
            ['name' => 'Doro Nawas Camp', 'email' => 'reservations@wilderness-safaris.com', 'phone' => '+264 61 274 500', 'website' => 'https://wilderness-safaris.com/camps/doro-nawas-camp', 'instagram' => 'https://instagram.com/wildernesssafaris'],
            ['name' => 'The Delight Hotel', 'email' => 'info@thedelighthotel.com', 'phone' => '+264 64 405 050', 'website' => 'https://www.thedelighthotel.com', 'instagram' => 'https://instagram.com/thedelighthotel'],
            ['name' => 'Sossus Dune Lodge', 'email' => 'reservations@nwr.com.na', 'phone' => '+264 63 293 621', 'website' => 'https://www.nwr.com.na/sossus-dune-lodge', 'instagram' => null],
            ['name' => 'Little Kulala', 'email' => 'res@wilderness-safaris.com', 'phone' => '+264 61 274 500', 'website' => 'https://wilderness-safaris.com/camps/little-kulala', 'instagram' => 'https://instagram.com/wildernesssafaris'],
            ['name' => 'Canyon Roadhouse', 'email' => 'info@gondwana-collection.com', 'phone' => '+264 63 683 501', 'website' => 'https://www.gondwana-collection.com/canyon-roadhouse', 'instagram' => 'https://instagram.com/gondwanacollection'],
            ['name' => 'Bushtrack Car & Camper Hire', 'email' => 'rentals@bushtrack-namibia.com', 'phone' => '+264 61 220 404', 'website' => 'https://www.bushtrack-namibia.com', 'instagram' => 'https://instagram.com/bushtracknamibia'],
        ];

        foreach ($partners as $partner) {
            Partner::updateOrCreate(
                ['name' => $partner['name']],
                [
                    'email' => $partner['email'],
                    'phone' => $partner['phone'],
                    'website' => $partner['website'],
                    'instagram' => $partner['instagram'],
                    'bio' => ['en' => 'One of NamibWay\'s vetted accommodation partners in Namibia.'],
                ]
            );
        }
    }
}
