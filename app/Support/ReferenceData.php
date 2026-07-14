<?php

namespace App\Support;

/**
 * Static reference lists the mobile app needs to populate dropdowns / chips
 * during registration and job filtering. Mirrors the frontend TS data files
 * (resources/js/data/*) so web and app stay in sync.
 */
class ReferenceData
{
    /**
     * Indian states/UTs → major cities (mirrors resources/js/data/indianLocations.ts).
     *
     * @var array<string, list<string>>
     */
    public const CITIES_BY_STATE = [
        'Andhra Pradesh' => ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Nellore', 'Kurnool', 'Rajahmundry', 'Tirupati', 'Kadapa', 'Anantapur', 'Kakinada'],
        'Arunachal Pradesh' => ['Itanagar', 'Naharlagun', 'Pasighat', 'Tawang', 'Ziro'],
        'Assam' => ['Guwahati', 'Silchar', 'Dibrugarh', 'Jorhat', 'Nagaon', 'Tinsukia', 'Tezpur', 'Bongaigaon'],
        'Bihar' => ['Patna', 'Gaya', 'Bhagalpur', 'Muzaffarpur', 'Purnia', 'Darbhanga', 'Bihar Sharif', 'Arrah', 'Begusarai', 'Katihar'],
        'Chhattisgarh' => ['Raipur', 'Bhilai', 'Bilaspur', 'Korba', 'Durg', 'Rajnandgaon', 'Jagdalpur', 'Raigarh'],
        'Goa' => ['Panaji', 'Margao', 'Vasco da Gama', 'Mapusa', 'Ponda'],
        'Gujarat' => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Bhavnagar', 'Jamnagar', 'Junagadh', 'Gandhinagar', 'Anand', 'Nadiad'],
        'Haryana' => ['Faridabad', 'Gurugram', 'Panipat', 'Ambala', 'Yamunanagar', 'Rohtak', 'Hisar', 'Karnal', 'Sonipat', 'Panchkula'],
        'Himachal Pradesh' => ['Shimla', 'Mandi', 'Solan', 'Dharamshala', 'Kullu', 'Bilaspur', 'Hamirpur'],
        'Jharkhand' => ['Ranchi', 'Jamshedpur', 'Dhanbad', 'Bokaro', 'Hazaribagh', 'Deoghar', 'Giridih'],
        'Karnataka' => ['Bengaluru', 'Mysuru', 'Hubballi', 'Mangaluru', 'Belagavi', 'Kalaburagi', 'Davanagere', 'Ballari', 'Vijayapura', 'Shivamogga', 'Tumakuru'],
        'Kerala' => ['Thiruvananthapuram', 'Kochi', 'Kozhikode', 'Thrissur', 'Kollam', 'Kannur', 'Alappuzha', 'Palakkad', 'Malappuram'],
        'Madhya Pradesh' => ['Indore', 'Bhopal', 'Jabalpur', 'Gwalior', 'Ujjain', 'Sagar', 'Dewas', 'Satna', 'Ratlam', 'Rewa'],
        'Maharashtra' => ['Mumbai', 'Pune', 'Nagpur', 'Nashik', 'Thane', 'Aurangabad', 'Solapur', 'Kolhapur', 'Amravati', 'Navi Mumbai', 'Sangli', 'Jalgaon'],
        'Manipur' => ['Imphal', 'Thoubal', 'Bishnupur', 'Churachandpur'],
        'Meghalaya' => ['Shillong', 'Tura', 'Jowai', 'Nongstoin'],
        'Mizoram' => ['Aizawl', 'Lunglei', 'Champhai'],
        'Nagaland' => ['Kohima', 'Dimapur', 'Mokokchung', 'Tuensang'],
        'Odisha' => ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Berhampur', 'Sambalpur', 'Puri', 'Balasore'],
        'Punjab' => ['Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala', 'Bathinda', 'Mohali', 'Hoshiarpur', 'Pathankot', 'Moga'],
        'Rajasthan' => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota', 'Bikaner', 'Ajmer', 'Bhilwara', 'Alwar', 'Sikar', 'Sri Ganganagar'],
        'Sikkim' => ['Gangtok', 'Namchi', 'Gyalshing', 'Mangan'],
        'Tamil Nadu' => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem', 'Tirunelveli', 'Tiruppur', 'Erode', 'Vellore', 'Thoothukudi'],
        'Telangana' => ['Hyderabad', 'Warangal', 'Nizamabad', 'Karimnagar', 'Khammam', 'Ramagundam', 'Mahbubnagar'],
        'Tripura' => ['Agartala', 'Udaipur', 'Dharmanagar', 'Kailashahar'],
        'Uttar Pradesh' => ['Lucknow', 'Kanpur', 'Ghaziabad', 'Agra', 'Varanasi', 'Meerut', 'Prayagraj', 'Noida', 'Bareilly', 'Aligarh', 'Moradabad', 'Gorakhpur'],
        'Uttarakhand' => ['Dehradun', 'Haridwar', 'Roorkee', 'Haldwani', 'Rudrapur', 'Kashipur', 'Rishikesh'],
        'West Bengal' => ['Kolkata', 'Howrah', 'Durgapur', 'Asansol', 'Siliguri', 'Bardhaman', 'Malda', 'Kharagpur'],
        'Andaman and Nicobar Islands' => ['Port Blair'],
        'Chandigarh' => ['Chandigarh'],
        'Dadra and Nagar Haveli and Daman and Diu' => ['Silvassa', 'Daman', 'Diu'],
        'Delhi' => ['New Delhi', 'Delhi', 'Dwarka', 'Rohini', 'Pitampura', 'Saket'],
        'Jammu and Kashmir' => ['Srinagar', 'Jammu', 'Anantnag', 'Baramulla', 'Udhampur'],
        'Ladakh' => ['Leh', 'Kargil'],
        'Lakshadweep' => ['Kavaratti'],
        'Puducherry' => ['Puducherry', 'Karaikal', 'Yanam', 'Mahe'],
    ];

    /**
     * Common skill suggestions (mirrors resources/js/data/skills.ts).
     *
     * @var list<string>
     */
    public const SKILLS = [
        'Plumbing', 'Pipe Fitting', 'Electrical Wiring', 'Electrician', 'Carpentry',
        'Woodwork', 'Painting', 'Wall Putty', 'Welding', 'Fabrication', 'Masonry',
        'Tiling', 'Plastering', 'POP / False Ceiling', 'AC Repair', 'Refrigerator Repair',
        'Washing Machine Repair', 'Appliance Repair', 'Driving', 'Heavy Vehicle Driving',
        'Gardening', 'Landscaping', 'Cooking', 'Housekeeping', 'Cleaning', 'Tailoring',
        'Stitching', 'Beautician', 'Hair Styling', 'Security', 'Loading / Unloading',
        'Helper', 'Mechanic', 'Two-Wheeler Repair', 'Mobile Repair', 'CCTV Installation',
        'Solar Panel Installation', 'Borewell', 'Roofing', 'Waterproofing',
    ];

    /**
     * Languages a worker may speak — used for job matching (not the app UI locale).
     *
     * @var list<string>
     */
    public const SPOKEN_LANGUAGES = [
        'Hindi', 'English', 'Tamil', 'Telugu', 'Kannada', 'Malayalam',
        'Marathi', 'Bengali', 'Gujarati', 'Punjabi', 'Odia', 'Urdu',
    ];

    /**
     * Highest-education options, low → high.
     *
     * @var list<string>
     */
    public const EDUCATION_LEVELS = [
        'Below 10th', '10th Pass', '12th Pass', 'ITI / Diploma', 'Graduate', 'Post Graduate',
    ];

    /**
     * Wage period options (must match WorkerProfileUpdateRequest wage_type rule).
     *
     * @var list<string>
     */
    public const WAGE_TYPES = ['hourly', 'daily', 'monthly'];

    /**
     * App UI languages (mirrors the LANGS list in the frontend switcher).
     *
     * @var list<array{code: string, native: string, english: string}>
     */
    public const APP_LANGUAGES = [
        ['code' => 'en', 'native' => 'English', 'english' => 'English'],
        ['code' => 'hi', 'native' => 'हिन्दी', 'english' => 'Hindi'],
        ['code' => 'ta', 'native' => 'தமிழ்', 'english' => 'Tamil'],
        ['code' => 'te', 'native' => 'తెలుగు', 'english' => 'Telugu'],
        ['code' => 'bn', 'native' => 'বাংলা', 'english' => 'Bengali'],
        ['code' => 'mr', 'native' => 'मराठी', 'english' => 'Marathi'],
    ];

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        $states = array_keys(self::CITIES_BY_STATE);
        sort($states);

        return $states;
    }

    /**
     * @return list<string>
     */
    public static function citiesFor(?string $state): array
    {
        return $state ? (self::CITIES_BY_STATE[$state] ?? []) : [];
    }
}
