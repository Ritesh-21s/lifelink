<?php
/**
 * LifeLink – Scale Seed Generator
 * Generates 10,000 synthetic donor users across 6 Indian cities for performance testing.
 *
 * Usage: php database/seed_scale.php | mysql -u root lifelink
 *   OR:  php database/seed_scale.php > database/seed_scale.sql
 *        then run seed_scale.sql in phpMyAdmin
 *
 * All synthetic accounts use password: Password@123
 * All have status='active' and are clearly marked synthetic (email domain @synthetic.test)
 */

// City centres with realistic coordinate ranges
$cities = [
    ['city'=>'Chennai',   'state'=>'Tamil Nadu',     'country'=>'India', 'lat'=>13.0827, 'lng'=>80.2707, 'spread'=>0.15],
    ['city'=>'Mumbai',    'state'=>'Maharashtra',     'country'=>'India', 'lat'=>19.0760, 'lng'=>72.8777, 'spread'=>0.12],
    ['city'=>'Delhi',     'state'=>'Delhi',           'country'=>'India', 'lat'=>28.7041, 'lng'=>77.1025, 'spread'=>0.18],
    ['city'=>'Bengaluru', 'state'=>'Karnataka',       'country'=>'India', 'lat'=>12.9716, 'lng'=>77.5946, 'spread'=>0.14],
    ['city'=>'Hyderabad', 'state'=>'Telangana',       'country'=>'India', 'lat'=>17.3850, 'lng'=>78.4867, 'spread'=>0.13],
    ['city'=>'Kolkata',   'state'=>'West Bengal',     'country'=>'India', 'lat'=>22.5726, 'lng'=>88.3639, 'spread'=>0.12],
];

$bloodGroups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
$genders     = ['male','female','other'];
// Password@123 hash
$hash = '$2y$12$PFbZjfO.rnYrWAaRfLTG4OKxsR5TL3c.MCJvZ3pDdz.Boc9bvPqYS';

$firstNames = ['Aarav','Arjun','Kiran','Priya','Rahul','Ananya','Rohit','Sneha','Vijay','Divya',
               'Manoj','Pooja','Suresh','Lakshmi','Ganesh','Revathi','Siva','Nithya','Bala','Kavya',
               'Naveen','Deepa','Prakash','Yamini','Senthil','Padma','Krishnan','Mythili','Gokul','Malathi',
               'Arun','Chandrika','Muthu','Suganya','Rajesh','Uma','Praveen','Shanthi','Tamil','Vasantha'];
$lastNames  = ['Kumar','Sharma','Patel','Nair','Iyer','Reddy','Singh','Gupta','Das','Pillai',
               'Rao','Krishnan','Murugan','Venkat','Subramanian','Raj','Babu','Devi','Menon','Srinivas'];

$count      = 10000;
$batchSize  = 200;
$startId    = 1000; // start after existing seed users (1–46)

echo "-- LifeLink Scale Seed: {$count} synthetic donors across 6 Indian cities\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Password for all: Password@123\n\n";
echo "USE lifelink;\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

// Users batch
for ($batch = 0; $batch < ceil($count / $batchSize); $batch++) {
    $userRows  = [];
    $donorRows = [];
    $start     = $batch * $batchSize;
    $end       = min($start + $batchSize, $count);

    for ($i = $start; $i < $end; $i++) {
        $id      = $startId + $i;
        $city    = $cities[$i % count($cities)];
        $fname   = $firstNames[$i % count($firstNames)];
        $lname   = $lastNames[($i + 3) % count($lastNames)];
        $name    = $fname . ' ' . $lname;
        $email   = 'donor' . $id . '@synthetic.test';
        $phone   = '9' . str_pad((string)(800000000 + $id), 9, '0', STR_PAD_LEFT);

        // Jitter coordinates within city spread
        $lat  = round($city['lat'] + (($i % 31 - 15) * $city['spread'] / 15), 6);
        $lng  = round($city['lng'] + (($i % 29 - 14) * $city['spread'] / 14), 6);

        $bg   = $bloodGroups[$i % count($bloodGroups)];
        $gen  = $genders[$i % 3];
        $dob  = date('Y-m-d', mktime(0,0,0, ($i%12)+1, ($i%28)+1, 1975 + ($i%30)));
        $lastDon = ($i % 4 === 0) ? 'NULL' : "'" . date('Y-m-d', mktime(0,0,0, ($i%12)+1, 1, 2025 + (($i%12)>6?1:0))) . "'";
        $avail   = ($i % 10 === 0) ? 0 : 1;

        $userRows[] = "({$id}, '" . addslashes($name) . "', '{$email}', '{$phone}', '{$hash}', 'donor', 'active', "
                    . "NULL, NULL, {$lat}, {$lng}, '" . addslashes($city['city']) . "', '" . addslashes($city['state']) . "', 'India', "
                    . "'geocoded', NOW(), 1, 0, 1, NOW())";

        $donorRows[] = "({$id}, '{$bg}', '{$gen}', '{$dob}', NULL, "
                     . ($lastDon === 'NULL' ? 'NULL' : $lastDon) . ", {$avail}, 1, NULL, {$lat}, {$lng}, 1)";
    }

    echo "INSERT INTO users (id, name, email, phone, password_hash, role, status,\n"
       . "  email_token, email_token_expires, lat, lng, city, state, country,\n"
       . "  location_source, location_updated_at, location_consent, location_incomplete, notify_email, created_at)\nVALUES\n"
       . implode(",\n", $userRows) . "\nON DUPLICATE KEY UPDATE email=VALUES(email);\n\n";

    echo "INSERT INTO donors (user_id, blood_group, gender, dob, area_id,\n"
       . "  last_donation_date, is_available, verified, weight, lat, lng, location_consent)\nVALUES\n"
       . implode(",\n", $donorRows) . "\nON DUPLICATE KEY UPDATE blood_group=VALUES(blood_group);\n\n";
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "SELECT CONCAT('Scale seed complete: ', COUNT(*), ' synthetic donors') AS result\n";
echo "FROM users WHERE email LIKE '%@synthetic.test';\n";

