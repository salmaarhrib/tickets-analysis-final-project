<?php
require 'db.php';

// Turn off foreign key checks for clean truncation
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("TRUNCATE TABLE tickets");
$conn->query("TRUNCATE TABLE users");
$conn->query("TRUNCATE TABLE teams");
$conn->query("TRUNCATE TABLE categories");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "Tables truncated.<br>";

// 1. Insert Categories
$categories = [
    ['Database', 'Database related support tickets'],
    ['Software', 'Software installation and licensing'],
    ['Business Application', 'Core business apps and ERP integrations'],
    ['Access / Security', 'User accounts, permissions, and security policies'],
    ['Hardware', 'Laptop, desktop, mouse, screens, and printer fixes'],
    ['Telephony', 'VoIP, hardware phones, Softphone and call forwarding issues'],
    ['On/Off boarding', 'Employee arrivals, departures, provisioning and hardware recovery']
];

$stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
foreach ($categories as $cat) {
    $stmt->bind_param("ss", $cat[0], $cat[1]);
    $stmt->execute();
}
echo "Categories inserted.<br>";

// 2. Insert Teams
$teams = [
    ['team_casa', 'Casablanca IT Support Team', 'Morocco', 'Morocco', 1],
    ['team_france', 'France IT Support Team', 'Europe', 'France', 0],
    ['salesforce_team', 'Global Salesforce Team', 'Global', 'Global', 0],
    ['team_spain', 'Spain IT Support Team', 'Europe', 'Spain', 0],
    ['team_poland', 'Poland IT Support Team', 'Europe', 'Poland', 0],
    ['admin_casa', 'Casablanca IT Administration', 'Morocco', 'Morocco', 1],
    ['team_uk', 'UK IT Support Team', 'Europe', 'UK', 0],
    ['team_usa', 'USA IT Support Team', 'North America', 'USA', 0],
    ['team_germany', 'Germany IT Support Team', 'Europe', 'Germany', 0],
    ['team_netherlands', 'Netherlands IT Support Team', 'Europe', 'Netherlands', 0]
];

$stmt = $conn->prepare("INSERT INTO teams (team_code, team_name, region, country, is_morocco) VALUES (?, ?, ?, ?, ?)");
foreach ($teams as $t) {
    $stmt->bind_param("ssssi", $t[0], $t[1], $t[2], $t[3], $t[4]);
    $stmt->execute();
}
echo "Teams inserted.<br>";

// 3. Insert Users (Technicians and Dispatchers)
$users = [
    // Dispatchers
    ['Ahmed AMINE', 'ahmed.amine@leyton.com', 'Dispatcher', 'team_casa', 1],
    ['Zineb JLALI', 'zineb.jlali@leyton.com', 'Dispatcher', 'team_casa', 1],
    ['Ayoub BELOUDI', 'ayoub.beloudi@leyton.com', 'Dispatcher', 'team_casa', 1],
    ['Abdelali El Harnaf', 'abdelali.elharnaf@leyton.com', 'Dispatcher', 'team_casa', 1],
    ['HAMADA LEMSSADDEK', 'hamada.lemssaddek@leyton.com', 'Dispatcher', 'team_casa', 1],
    ['OdooBot', 'odoobot@leyton.com', 'Dispatcher', 'team_casa', 1],
    ['Othmane FARHANI', 'othmane.farhani@leyton.com', 'Dispatcher', 'team_casa', 1],
    
    // Technicians
    ['Fatima Zahra ELKIHEL', 'fatimazahra.elkihel@leyton.com', 'Technician', 'team_casa', 1],
    ['Michal KOWALSKI', 'michal.kowalski@leyton.com', 'Technician', 'team_poland', 0],
    ['Aymen EL IBRAHIMI', 'aymen.elibrahimi@leyton.com', 'Technician', 'team_casa', 1],
    ['Mohcine RACHIDI', 'mohcine.rachidi@leyton.com', 'Technician', 'team_casa', 1],
    ['Hind HABCY', 'hind.habcy@leyton.com', 'Technician', 'team_casa', 1],
    ['Marius BRINARU', 'marius.brinaru@leyton.com', 'Technician', 'team_poland', 0],
    ['Ali BIARI', 'ali.biari@leyton.com', 'Technician', 'team_casa', 1],
    ['Yvon ABALE', 'yvon.abale@leyton.com', 'Technician', 'team_france', 0],
    ['Ali ALAOUI MRANI', 'ali.alaoui@leyton.com', 'Technician', 'team_casa', 1],
    ['Dounia BENNOUNE', 'dounia.bennoune@leyton.com', 'Technician', 'team_casa', 1],
    ['Alexandre LAUMOND', 'alexandre.laumond@leyton.com', 'Technician', 'team_france', 0],
    ['Imane OUNASER', 'imane.ounaser@leyton.com', 'Technician', 'team_casa', 1],
    ['Nouhaila ABIDI', 'nouhaila.abidi@leyton.com', 'Technician', 'team_casa', 1],
    ['Aymane CHTOUKI', 'aymane.chtouki@leyton.com', 'Technician', 'team_casa', 1],
    ['Mathieu SANCHEZ AMAYA', 'mathieu.sanchez@leyton.com', 'Technician', 'team_france', 0]
];

$stmt = $conn->prepare("INSERT INTO users (full_name, email, role, team_code, is_morocco) VALUES (?, ?, ?, ?, ?)");
foreach ($users as $u) {
    $stmt->bind_param("ssssi", $u[0], $u[1], $u[2], $u[3], $u[4]);
    $stmt->execute();
}
echo "Users inserted.<br>";

// 4. Generate tickets
echo "Generating tickets... (this may take a couple of seconds)<br>";

$techs = array_filter($users, function($u) { return $u[2] === 'Technician'; });
$dispatchers = array_filter($users, function($u) { return $u[2] === 'Dispatcher'; });
$cat_names = array_column($categories, 0);

$priorities = ['Low', 'Medium', 'High', 'Critical'];
$statuses = ['New', 'In Progress', 'Resolved', 'Closed'];

// We want to generate ~9000 tickets distributed from 2022 to 2026
$total_to_generate = 9000;
$conn->begin_transaction();

$sql = "INSERT INTO tickets (
    ticket_number, title, category_name, priority, status, 
    assigned_technician, dispatcher_name, team_code, is_morocco, 
    week_of_year, workload_minutes, sla_limit_hours, sla_is_broken, 
    created_at, resolved_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

$ticket_id_counter = 100000;
for ($i = 0; $i < $total_to_generate; $i++) {
    $ticket_id_counter++;
    $ticket_number = "TKT-" . $ticket_id_counter;
    
    // Choose category
    $cat = $cat_names[array_rand($cat_names)];
    $title = "Request for " . $cat . " help";
    
    // Weighted priority
    $rand_p = rand(1, 100);
    if ($rand_p <= 50) $priority = 'Medium';
    elseif ($rand_p <= 80) $priority = 'Low';
    elseif ($rand_p <= 95) $priority = 'High';
    else $priority = 'Critical';
    
    // Weighted status (mostly Resolved/Closed to reflect screenshots)
    $rand_s = rand(1, 100);
    if ($rand_s <= 90) $status = 'Closed';
    elseif ($rand_s <= 97) $status = 'Resolved';
    elseif ($rand_s <= 99) $status = 'In Progress';
    else $status = 'New';
    
    // Choose tech and match team/is_morocco
    $tech_val = $techs[array_rand($techs)];
    $assigned_technician = $tech_val[0];
    $team_code = $tech_val[3];
    $is_morocco = $tech_val[4];
    
    // Choose dispatcher
    $disp_val = $dispatchers[array_rand($dispatchers)];
    $dispatcher_name = $disp_val[0];
    
    // Generate dates: 2022 to 2026
    $year = rand(2022, 2026);
    // If 2026, limit up to August (month 8)
    if ($year === 2026) {
        $month = rand(1, 8);
        $day = rand(1, ($month == 8) ? 24 : 28);
    } else {
        $month = rand(1, 12);
        $day = rand(1, 28);
    }
    $hour = rand(8, 18);
    $minute = rand(0, 59);
    $second = rand(0, 59);
    
    $created_timestamp = mktime($hour, $minute, $second, $month, $day, $year);
    $created_at = date('Y-m-d H:i:s', $created_timestamp);
    
    // ISO week number
    $week_of_year = (int)date('W', $created_timestamp);
    
    // Workload minutes (average 1h to 3h)
    $workload_minutes = rand(15, 240);
    
    $sla_limit_hours = 16;
    
    // SLA status
    // If status is Resolved/Closed, it might exceed SLA.
    // Let's make it broken in roughly 8% of cases
    $sla_is_broken = (rand(1, 100) <= 8) ? 1 : 0;
    
    // Resolved date
    $resolved_at = null;
    if ($status === 'Resolved' || $status === 'Closed') {
        // Resolve time: if SLA is broken, add 17-60 hours. If not, add 1-15 hours.
        if ($sla_is_broken) {
            $resolve_delay_secs = rand(17 * 3600, 60 * 3600);
        } else {
            $resolve_delay_secs = rand(1 * 3600, 15 * 3600);
        }
        $resolved_timestamp = $created_timestamp + $resolve_delay_secs;
        $resolved_at = date('Y-m-d H:i:s', $resolved_timestamp);
    } else {
        // If unresolved, SLA shouldn't be marked broken yet unless it's old,
        // but for simplicity we keep it compliant
        $sla_is_broken = 0;
    }
    
    $stmt->bind_param(
        "sssssssssiiiiss",
        $ticket_number, $title, $cat, $priority, $status,
        $assigned_technician, $dispatcher_name, $team_code, $is_morocco,
        $week_of_year, $workload_minutes, $sla_limit_hours, $sla_is_broken,
        $created_at, $resolved_at
    );
    $stmt->execute();
}

$conn->commit();
echo "9000 tickets generated successfully!<br>";
echo "Seeding completed.<br>";
?>
