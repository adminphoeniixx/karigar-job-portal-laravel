<?php

namespace App\Support;

/**
 * The centre of every city in ReferenceData::CITIES_BY_STATE, so a city
 * typed into the feed's place picker is found at once instead of after a
 * round trip to Nominatim: most karigars move their feed to a town, not a
 * street. Localities and anything else still go to Nominatim.
 *
 * The coordinates are Nominatim's own, from the lookup Geocoder::cityCentre()
 * makes (city + state, India). A city added to the reference list needs its
 * centre added here too, or it is simply searched on Nominatim.
 */
final class CityCentres
{
    /** @var array<string, array<string, array{0: float, 1: float}>> state => city => [latitude, longitude] */
    private const CENTRES = [
        'Andhra Pradesh' => ['Visakhapatnam' => [17.69355, 83.29213], 'Vijayawada' => [16.51153, 80.61605], 'Guntur' => [16.29152, 80.45416], 'Nellore' => [14.44937, 79.98738], 'Kurnool' => [15.83093, 78.04254], 'Rajahmundry' => [17.00505, 81.78047], 'Tirupati' => [13.63164, 79.42317], 'Kadapa' => [14.47529, 78.82169], 'Anantapur' => [14.67832, 77.6065], 'Kakinada' => [16.94374, 82.23506]],
        'Arunachal Pradesh' => ['Itanagar' => [27.09797, 93.62373], 'Naharlagun' => [27.10305, 93.69239], 'Pasighat' => [28.05897, 95.33249], 'Tawang' => [27.58792, 91.86373], 'Ziro' => [27.53867, 93.81535]],
        'Assam' => ['Guwahati' => [26.1806, 91.75394], 'Silchar' => [24.82959, 92.79715], 'Dibrugarh' => [27.48446, 94.90194], 'Jorhat' => [26.75779, 94.20796], 'Nagaon' => [26.34822, 92.68582], 'Tinsukia' => [27.48797, 95.36019], 'Tezpur' => [26.62299, 92.79761], 'Bongaigaon' => [26.48001, 90.55804]],
        'Bihar' => ['Patna' => [25.60932, 85.12353], 'Gaya' => [24.79644, 85.00796], 'Bhagalpur' => [25.24948, 86.98281], 'Muzaffarpur' => [26.11829, 85.38579], 'Purnia' => [25.77733, 87.47312], 'Darbhanga' => [26.157, 85.89951], 'Bihar Sharif' => [25.19386, 85.52086], 'Arrah' => [25.56032, 84.66321], 'Begusarai' => [25.41391, 86.13488], 'Katihar' => [25.54338, 87.56904]],
        'Chhattisgarh' => ['Raipur' => [21.23809, 81.6337], 'Bhilai' => [21.21207, 81.37328], 'Bilaspur' => [22.07856, 82.14765], 'Korba' => [22.35475, 82.71109], 'Durg' => [21.18965, 81.28511], 'Rajnandgaon' => [21.09649, 81.02915], 'Jagdalpur' => [19.08708, 82.02357], 'Raigarh' => [21.89762, 83.39655]],
        'Goa' => ['Panaji' => [15.49899, 73.82821], 'Margao' => [15.27292, 73.95832], 'Vasco da Gama' => [15.39842, 73.81288], 'Mapusa' => [15.59085, 73.81021], 'Ponda' => [15.40103, 74.0064]],
        'Gujarat' => ['Ahmedabad' => [23.02154, 72.58006], 'Surat' => [21.20949, 72.83171], 'Vadodara' => [22.29731, 73.19426], 'Rajkot' => [22.30533, 70.80284], 'Bhavnagar' => [21.77188, 72.14164], 'Jamnagar' => [22.47324, 70.05521], 'Junagadh' => [21.52204, 70.45825], 'Gandhinagar' => [23.22329, 72.64923], 'Anand' => [22.55866, 72.96272], 'Nadiad' => [22.68955, 72.87136]],
        'Haryana' => ['Faridabad' => [28.40315, 77.31056], 'Gurugram' => [28.46461, 77.02992], 'Panipat' => [29.39127, 76.97717], 'Ambala' => [30.38437, 76.77042], 'Yamunanagar' => [30.12313, 77.28633], 'Rohtak' => [28.90109, 76.58019], 'Hisar' => [29.15627, 75.72923], 'Karnal' => [29.68033, 76.98963], 'Sonipat' => [28.99538, 77.02336], 'Panchkula' => [30.69754, 76.85511]],
        'Himachal Pradesh' => ['Shimla' => [31.10404, 77.17079], 'Mandi' => [31.70845, 76.92938], 'Solan' => [30.90776, 77.10236], 'Dharamshala' => [32.2143, 76.31967], 'Kullu' => [31.9579, 77.10892], 'Bilaspur' => [31.3381, 76.76116], 'Hamirpur' => [31.68487, 76.52545]],
        'Jharkhand' => ['Ranchi' => [23.37005, 85.32504], 'Jamshedpur' => [22.80152, 86.20296], 'Dhanbad' => [23.79528, 86.43096], 'Bokaro' => [23.65443, 86.14564], 'Hazaribagh' => [23.99244, 85.36163], 'Deoghar' => [24.48983, 86.69902], 'Giridih' => [24.18626, 86.30872]],
        'Karnataka' => ['Bengaluru' => [12.97679, 77.59008], 'Mysuru' => [12.30518, 76.65536], 'Hubballi' => [15.35184, 75.13798], 'Mangaluru' => [12.86981, 74.84301], 'Belagavi' => [15.85727, 74.50693], 'Kalaburagi' => [17.331, 76.83342], 'Davanagere' => [14.46613, 75.92064], 'Ballari' => [15.14302, 76.91733], 'Vijayapura' => [16.82699, 75.71754], 'Shivamogga' => [13.93261, 75.57498], 'Tumakuru' => [13.34008, 77.10062]],
        'Kerala' => ['Thiruvananthapuram' => [8.48823, 76.94755], 'Kochi' => [9.9679, 76.24444], 'Kozhikode' => [11.24506, 75.77547], 'Thrissur' => [10.52723, 76.21607], 'Kollam' => [8.88705, 76.59067], 'Kannur' => [11.87638, 75.3738], 'Alappuzha' => [9.49233, 76.32915], 'Palakkad' => [10.76819, 76.65213], 'Malappuram' => [11.04289, 76.08078]],
        'Madhya Pradesh' => ['Indore' => [22.72036, 75.8682], 'Bhopal' => [23.25849, 77.40199], 'Jabalpur' => [23.17015, 79.93245], 'Gwalior' => [26.20372, 78.15736], 'Ujjain' => [23.18851, 75.77166], 'Sagar' => [23.84178, 78.74668], 'Dewas' => [22.96363, 76.05487], 'Satna' => [24.57513, 80.83257], 'Ratlam' => [23.3311, 75.03608], 'Rewa' => [24.5359, 81.2934]],
        'Maharashtra' => ['Mumbai' => [19.055, 72.8692], 'Pune' => [18.52137, 73.85451], 'Nagpur' => [21.14981, 79.08206], 'Nashik' => [20.01125, 73.79024], 'Thane' => [19.19433, 72.97018], 'Aurangabad' => [19.87726, 75.33902], 'Solapur' => [17.66997, 75.90081], 'Kolhapur' => [16.70284, 74.24053], 'Amravati' => [20.93162, 77.75885], 'Navi Mumbai' => [19.03083, 73.01985], 'Sangli' => [16.85025, 74.59489], 'Jalgaon' => [21.01007, 75.5696]],
        'Manipur' => ['Imphal' => [24.79912, 93.93644], 'Thoubal' => [24.6387, 93.99687], 'Bishnupur' => [24.62673, 93.76005], 'Churachandpur' => [24.34688, 93.69941]],
        'Meghalaya' => ['Shillong' => [25.57599, 91.88279], 'Tura' => [25.51256, 90.21715], 'Jowai' => [25.44347, 92.19732], 'Nongstoin' => [25.51939, 91.26775]],
        'Mizoram' => ['Aizawl' => [23.72776, 92.71799], 'Lunglei' => [22.89317, 92.74704], 'Champhai' => [23.47872, 93.31989]],
        'Nagaland' => ['Kohima' => [25.66188, 94.10192], 'Dimapur' => [25.91174, 93.7217], 'Mokokchung' => [26.32388, 94.52141], 'Tuensang' => [26.23933, 94.81469]],
        'Odisha' => ['Bhubaneswar' => [20.2603, 85.83945], 'Cuttack' => [20.4686, 85.8792], 'Rourkela' => [22.23093, 84.86787], 'Berhampur' => [19.30981, 84.79716], 'Sambalpur' => [21.47054, 83.97615], 'Puri' => [19.80761, 85.82525], 'Balasore' => [21.50171, 86.92167]],
        'Punjab' => ['Ludhiana' => [30.90902, 75.8516], 'Amritsar' => [31.63567, 74.87875], 'Jalandhar' => [31.33238, 75.57689], 'Patiala' => [30.3302, 76.40077], 'Bathinda' => [30.20679, 74.94637], 'Mohali' => [30.69088, 76.71149], 'Hoshiarpur' => [31.53285, 75.90255], 'Pathankot' => [32.26925, 75.65289], 'Moga' => [30.82234, 75.1731]],
        'Rajasthan' => ['Jaipur' => [26.91546, 75.81898], 'Jodhpur' => [26.29677, 73.03514], 'Udaipur' => [24.57872, 73.68626], 'Kota' => [25.1737, 75.85742], 'Bikaner' => [28.01593, 73.31714], 'Ajmer' => [26.4691, 74.639], 'Bhilwara' => [25.348, 74.636], 'Alwar' => [27.56459, 76.61322], 'Sikar' => [27.60919, 75.13955], 'Sri Ganganagar' => [29.92706, 73.87615]],
        'Sikkim' => ['Gangtok' => [27.32905, 88.61227], 'Namchi' => [27.16613, 88.3652], 'Gyalshing' => [27.29004, 88.2573], 'Mangan' => [27.50115, 88.53553]],
        'Tamil Nadu' => ['Chennai' => [13.08009, 80.2808], 'Coimbatore' => [11.00181, 76.96284], 'Madurai' => [9.92612, 78.1141], 'Tiruchirappalli' => [10.80711, 78.68809], 'Salem' => [11.6552, 78.15818], 'Tirunelveli' => [8.73317, 77.71026], 'Tiruppur' => [11.10178, 77.34519], 'Erode' => [11.33065, 77.72765], 'Vellore' => [12.90718, 79.13097], 'Thoothukudi' => [8.80526, 78.14527]],
        'Telangana' => ['Hyderabad' => [17.36059, 78.47406], 'Warangal' => [17.98206, 79.5971], 'Nizamabad' => [18.67319, 78.09782], 'Karimnagar' => [18.43481, 79.1328], 'Khammam' => [17.24654, 80.15003], 'Ramagundam' => [18.76152, 79.47878], 'Mahbubnagar' => [16.74345, 77.99232]],
        'Tripura' => ['Agartala' => [23.83124, 91.28238], 'Udaipur' => [23.54162, 91.49176], 'Dharmanagar' => [24.37341, 92.16553], 'Kailashahar' => [24.31943, 91.99843]],
        'Uttar Pradesh' => ['Lucknow' => [26.8381, 80.9346], 'Kanpur' => [26.46091, 80.32176], 'Ghaziabad' => [28.67115, 77.41204], 'Agra' => [27.17526, 78.00982], 'Varanasi' => [25.33565, 83.00763], 'Meerut' => [28.99633, 77.70619], 'Prayagraj' => [25.43813, 81.8338], 'Noida' => [28.57063, 77.32721], 'Bareilly' => [28.3551, 79.41785], 'Aligarh' => [27.88154, 78.06902], 'Moradabad' => [28.8335, 78.77329], 'Gorakhpur' => [26.76002, 83.36681]],
        'Uttarakhand' => ['Dehradun' => [30.32556, 78.04368], 'Haridwar' => [29.93845, 78.1453], 'Roorkee' => [29.86935, 77.89021], 'Haldwani' => [29.21448, 79.5279], 'Rudrapur' => [28.97072, 79.39728], 'Kashipur' => [29.21176, 78.96173], 'Rishikesh' => [30.10865, 78.29162]],
        'West Bengal' => ['Kolkata' => [22.57265, 88.3639], 'Howrah' => [22.57363, 88.3251], 'Durgapur' => [23.53505, 87.33804], 'Asansol' => [23.68686, 86.97456], 'Siliguri' => [26.71641, 88.43099], 'Bardhaman' => [23.24957, 87.86818], 'Malda' => [25.00574, 88.13985], 'Kharagpur' => [22.34309, 87.30129]],
        'Andaman and Nicobar Islands' => ['Port Blair' => [11.66453, 92.73904]],
        'Chandigarh' => ['Chandigarh' => [30.73344, 76.77971]],
        'Dadra and Nagar Haveli and Daman and Diu' => ['Silvassa' => [20.27368, 73.00458], 'Daman' => [20.41697, 72.83317], 'Diu' => [20.71378, 70.98536]],
        'Delhi' => ['New Delhi' => [28.6139, 77.20901], 'Delhi' => [28.66645, 77.21698], 'Dwarka' => [28.57781, 77.05824], 'Rohini' => [28.70631, 77.10879], 'Pitampura' => [28.69951, 77.1301], 'Saket' => [28.52441, 77.21373]],
        'Jammu and Kashmir' => ['Srinagar' => [34.07474, 74.82044], 'Jammu' => [32.71856, 74.85809], 'Anantnag' => [33.73688, 75.14551], 'Baramulla' => [34.20869, 74.3435], 'Udhampur' => [32.92197, 75.13315]],
        'Ladakh' => ['Leh' => [34.1642, 77.58481], 'Kargil' => [34.5593, 76.12558]],
        'Lakshadweep' => ['Kavaratti' => [10.56722, 72.63946]],
        'Puducherry' => ['Puducherry' => [11.93406, 79.83064], 'Karaikal' => [10.91571, 79.83758], 'Yanam' => [16.7385, 82.21062], 'Mahe' => [11.7022, 75.53647]],
    ];

    /**
     * Cities whose name, or a word of it, starts with what was typed: "jai"
     * finds Jaipur, "delhi" finds Delhi and New Delhi. A state after the
     * name narrows it ("Udaipur, Tri", "udaipur tripura").
     *
     * @return list<array{label: string, area: null, city: string, state: string, latitude: float, longitude: float}>
     */
    public static function search(string $query, int $limit = 5): array
    {
        [$cityPart, $statePart] = array_pad(explode(',', $query, 2), 2, '');
        $cityPart = self::normalize($cityPart);
        $statePart = self::normalize($statePart);

        if (strlen($cityPart) < 2) {
            return [];
        }

        $matches = [];

        foreach (self::CENTRES as $state => $cities) {
            if ($statePart !== '' && ! str_starts_with(self::normalize($state), $statePart)) {
                continue;
            }

            foreach ($cities as $city => [$latitude, $longitude]) {
                $name = self::normalize($city);
                $rank = match (true) {
                    $name === $cityPart => 0,
                    // "jaipur raj": the city, then the start of its state.
                    $statePart === '' && str_starts_with($cityPart, $name.' ')
                        && str_starts_with(self::normalize($state), substr($cityPart, strlen($name) + 1)) => 0,
                    str_starts_with($name, $cityPart) => 1,
                    str_contains(' '.$name, ' '.$cityPart) => 2,
                    default => null,
                };

                if ($rank !== null) {
                    $matches[] = compact('rank', 'city', 'state', 'latitude', 'longitude');
                }
            }
        }

        // Exact names first, then the shortest; ties keep the list's order.
        usort($matches, fn (array $a, array $b) => [$a['rank'], strlen($a['city'])] <=> [$b['rank'], strlen($b['city'])]);

        return array_map(fn (array $match) => [
            'label' => $match['city'].', '.$match['state'],
            'area' => null,
            'city' => $match['city'],
            'state' => $match['state'],
            'latitude' => $match['latitude'],
            'longitude' => $match['longitude'],
        ], array_slice($matches, 0, $limit));
    }

    /**
     * The centre of one city of the list, or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function find(?string $city, ?string $state): ?array
    {
        $city = self::normalize((string) $city);
        $state = self::normalize((string) $state);

        foreach (self::CENTRES as $name => $cities) {
            if ($state !== '' && self::normalize($name) !== $state) {
                continue;
            }

            foreach ($cities as $cityName => $pin) {
                if (self::normalize($cityName) === $city) {
                    return $pin;
                }
            }
        }

        return null;
    }

    /**
     * Lower case, letters and digits only: what was typed in Hindi comes out
     * empty and goes to Nominatim, which reads it.
     */
    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }
}
