<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch current authenticated user from DB to guarantee it always reflects whoever logged in
$user_stmt = $conn->prepare("SELECT full_name, role, team_code FROM users WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_row = $user_stmt->get_result()->fetch_assoc();

if ($user_row) {
    $current_user_name = $user_row['full_name'];
    $current_user_role = $user_row['role'];
    $current_user_team = $user_row['team_code'];
    $_SESSION['full_name'] = $current_user_name;
    $_SESSION['role']      = $current_user_role;
    $_SESSION['team_code'] = $current_user_team;
} else {
    $current_user_name = $_SESSION['full_name'] ?? 'User';
    $current_user_role = $_SESSION['role'] ?? 'Standard Employee';
    $current_user_team = $_SESSION['team_code'] ?? '';
}

// Check if user is restricted
$is_restricted = in_array($current_user_role, ['Standard Employee', 'Technician', 'Dispatcher']);

// Verify table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'tickets'")->num_rows > 0;
if (!$table_exists) {
    die("The database tables do not exist. Please run <a href='seed.php'>seed.php</a> to set up and seed the database first.");
}

// 1. Fetch filter choices dynamically
$years_res = $conn->query("SELECT DISTINCT YEAR(created_at) AS yr FROM tickets ORDER BY yr DESC");
$years_list = [];
while ($row = $years_res->fetch_assoc()) {
    $years_list[] = (int)$row['yr'];
}

// REGION/NAME Options
$regions_res = $conn->query("SELECT DISTINCT region FROM teams WHERE region IS NOT NULL AND region != '' ORDER BY region");
$regions_list = [];
while ($row = $regions_res->fetch_assoc()) {
    $regions_list[] = $row['region'];
}

// COUNTRY Options
$countries_res = $conn->query("SELECT DISTINCT country FROM teams WHERE country IS NOT NULL AND country != '' ORDER BY country");
$countries_list = [];
while ($row = $countries_res->fetch_assoc()) {
    $countries_list[] = $row['country'];
}

// CATEGORY Options
$categories_res = $conn->query("SELECT name FROM categories ORDER BY name");
$categories_list = [];
while ($row = $categories_res->fetch_assoc()) {
    $categories_list[] = $row['name'];
}

// ASSIGNED USER / IT TEAM Options
$teams_res = $conn->query("SELECT team_code FROM teams ORDER BY team_code");
$teams_list = [];
while ($row = $teams_res->fetch_assoc()) {
    $teams_list[] = $row['team_code'];
}

$techs_res = $conn->query("SELECT full_name FROM users WHERE role='Technician' ORDER BY full_name");

// Fetch weeks mapping per year and month for the tree filter
$weeks_res = $conn->query("SELECT DISTINCT YEAR(created_at) AS yr, MONTH(created_at) AS mo, week_of_year FROM tickets ORDER BY yr DESC, mo ASC, week_of_year ASC");
$tree_weeks = [];
while ($row = $weeks_res->fetch_assoc()) {
    $y = (int)$row['yr'];
    $m = (int)$row['mo'];
    $w = (int)$row['week_of_year'];
    $tree_weeks[$y][$m][] = $w;
}

// 2. Parse GET parameters for filters
$selected_years = isset($_GET['years']) && is_array($_GET['years']) ? array_map('intval', $_GET['years']) : [];
$selected_months = isset($_GET['months']) && is_array($_GET['months']) ? $_GET['months'] : [];
$selected_weeks = isset($_GET['weeks']) && is_array($_GET['weeks']) ? $_GET['weeks'] : [];

$selected_region = $_GET['region'] ?? '';
$selected_country = $_GET['country'] ?? '';
$selected_cat = $_GET['category'] ?? '';
$selected_team = $_GET['team'] ?? '';
$selected_tech = $_GET['tech'] ?? '';

// Build dynamic WHERE clause
$where_clauses = ["1=1"];

if ($is_restricted) {
    $esc_name = $conn->real_escape_string($current_user_name);
    $esc_team = $conn->real_escape_string($current_user_team);
    $where_clauses[] = "(assigned_technician = '$esc_name' OR team_code = '$esc_team')";
}

// Handle Date checkboxes tree (Years, Months, and Weeks) - Untouched
$date_clauses = [];
if (!empty($selected_years)) {
    foreach ($selected_years as $yr) {
        $months_for_year = array_filter($selected_months, function($m) use ($yr) {
            return strpos($m, "$yr-") === 0;
        });
        if (!empty($months_for_year)) {
            $month_conditions = [];
            foreach ($months_for_year as $m_val) {
                $m_num = (int)substr($m_val, 5, 2);
                $weeks_for_month = array_filter($selected_weeks, function($w) use ($m_val) {
                    return strpos($w, "$m_val-") === 0;
                });
                if (!empty($weeks_for_month)) {
                    $week_nums = [];
                    foreach ($weeks_for_month as $w_val) {
                        $parts = explode('-', $w_val);
                        if (isset($parts[2])) {
                            $week_nums[] = (int)$parts[2];
                        }
                    }
                    if (!empty($week_nums)) {
                        $month_conditions[] = "(MONTH(created_at) = $m_num AND week_of_year IN (" . implode(',', $week_nums) . "))";
                    } else {
                        $month_conditions[] = "MONTH(created_at) = $m_num";
                    }
                } else {
                    $month_conditions[] = "MONTH(created_at) = $m_num";
                }
            }
            $date_clauses[] = "(YEAR(created_at) = $yr AND (" . implode(' OR ', $month_conditions) . "))";
        } else {
            $date_clauses[] = "YEAR(created_at) = $yr";
        }
    }
} elseif (!empty($selected_weeks)) {
    $week_conditions = [];
    foreach ($selected_weeks as $w_val) {
        $parts = explode('-', $w_val);
        if (count($parts) === 3) {
            $y = (int)$parts[0];
            $m = (int)$parts[1];
            $w = (int)$parts[2];
            $week_conditions[] = "(YEAR(created_at) = $y AND MONTH(created_at) = $m AND week_of_year = $w)";
        }
    }
    if (!empty($week_conditions)) {
        $date_clauses[] = "(" . implode(' OR ', $week_conditions) . ")";
    }
} elseif (!empty($selected_months)) {
    $month_conditions = [];
    foreach ($selected_months as $m_val) {
        $parts = explode('-', $m_val);
        if (count($parts) === 2) {
            $y = (int)$parts[0];
            $m = (int)$parts[1];
            $month_conditions[] = "(YEAR(created_at) = $y AND MONTH(created_at) = $m)";
        }
    }
    if (!empty($month_conditions)) {
        $date_clauses[] = "(" . implode(' OR ', $month_conditions) . ")";
    }
}

if (!empty($date_clauses)) {
    $where_clauses[] = "(" . implode(' OR ', $date_clauses) . ")";
}

if ($selected_region !== '') {
    $r_esc = $conn->real_escape_string($selected_region);
    $where_clauses[] = "team_code IN (SELECT team_code FROM teams WHERE region = '$r_esc')";
}

if ($selected_country !== '') {
    $c_esc = $conn->real_escape_string($selected_country);
    $where_clauses[] = "team_code IN (SELECT team_code FROM teams WHERE country = '$c_esc')";
}

if ($selected_cat !== '') {
    $cat_esc = $conn->real_escape_string($selected_cat);
    $where_clauses[] = "category_name = '$cat_esc'";
}

if ($selected_team !== '') {
    $t_esc = $conn->real_escape_string($selected_team);
    $where_clauses[] = "team_code = '$t_esc'";
}

if ($selected_tech !== '') {
    $tech_esc = $conn->real_escape_string($selected_tech);
    $where_clauses[] = "assigned_technician = '$tech_esc'";
}

$where_sql = implode(' AND ', $where_clauses);

// 3. Query KPI stats
$total_tickets = $conn->query("SELECT COUNT(*) as count FROM tickets WHERE $where_sql")->fetch_assoc()['count'];

$avg_workload_row = $conn->query("SELECT AVG(workload_minutes) as avg FROM tickets WHERE $where_sql")->fetch_assoc();
$avg_workload_min = $avg_workload_row['avg'] ?? 0;
$avg_assign_min = round($avg_workload_min / 8);
$assign_hours = floor($avg_assign_min / 60);
$assign_mins = $avg_assign_min % 60;
$avg_assign_str = $assign_hours > 0 ? "{$assign_hours}h {$assign_mins}min" : "{$assign_mins}min";

$avg_resolve_row = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg FROM tickets WHERE status IN ('Resolved', 'Closed') AND $where_sql")->fetch_assoc();
$avg_resolve_min = round($avg_resolve_row['avg'] ?? 0);
$resolve_hours = floor($avg_resolve_min / 60);
$resolve_mins = $avg_resolve_min % 60;
$avg_resolve_str = $resolve_hours > 0 ? "{$resolve_hours}h {$resolve_mins}min" : "{$resolve_mins}min";

$type_counts = $conn->query("
    SELECT 
        SUM(CASE WHEN category_name IN ('Software', 'Hardware', 'Database', 'Telephony') THEN 1 ELSE 0 END) AS incidents,
        SUM(CASE WHEN category_name IN ('Access / Security', 'Business Application', 'On/Off boarding') THEN 1 ELSE 0 END) AS service_requests
    FROM tickets 
    WHERE $where_sql
")->fetch_assoc();
$incidents_count = (int)($type_counts['incidents'] ?? 0);
$requests_count = (int)($type_counts['service_requests'] ?? 0);

// --- 4. Chart Querying ---
$sql_assigned = "
    SELECT YEAR(created_at) AS yr, week_of_year, COUNT(*) AS count
    FROM tickets
    WHERE $where_sql
    GROUP BY YEAR(created_at), week_of_year
    ORDER BY yr ASC, week_of_year ASC
";
$assigned_res = $conn->query($sql_assigned);
$assigned_labels = [];
$assigned_data = [];
while ($row = $assigned_res->fetch_assoc()) {
    $assigned_labels[] = "W" . $row['week_of_year'] . " " . $row['yr'];
    $assigned_data[] = (int)$row['count'];
}
if (count($assigned_data) > 25) {
    $assigned_labels = array_slice($assigned_labels, -25);
    $assigned_data = array_slice($assigned_data, -25);
}

$sql_team = "
    SELECT team_code, COUNT(*) AS count
    FROM tickets
    WHERE $where_sql
    GROUP BY team_code
    ORDER BY count DESC
";
$team_res = $conn->query($sql_team);
$team_labels = [];
$team_data = [];
while ($row = $team_res->fetch_assoc()) {
    $team_labels[] = $row['team_code'];
    $team_data[] = (int)$row['count'];
}

$sql_category = "
    SELECT category_name, COUNT(*) AS count
    FROM tickets
    WHERE $where_sql
    GROUP BY category_name
    ORDER BY count DESC
";
$category_res = $conn->query($sql_category);
$category_labels = [];
$category_data = [];
while ($row = $category_res->fetch_assoc()) {
    $category_labels[] = $row['category_name'];
    $category_data[] = (int)$row['count'];
}

$sql_resolved_user = "
    SELECT assigned_technician,
           SUM(CASE WHEN status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved,
           SUM(CASE WHEN status NOT IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS unresolved
    FROM tickets
    WHERE $where_sql AND assigned_technician != ''
    GROUP BY assigned_technician
    ORDER BY COUNT(*) DESC
    LIMIT 10
";
$resolved_user_res = $conn->query($sql_resolved_user);
$resolved_user_labels = [];
$resolved_user_resolved = [];
$resolved_user_unresolved = [];
while ($row = $resolved_user_res->fetch_assoc()) {
    $resolved_user_labels[] = $row['assigned_technician'];
    $resolved_user_resolved[] = (int)$row['resolved'];
    $resolved_user_unresolved[] = (int)$row['unresolved'];
}

$sql_weeks = "
    SELECT DISTINCT YEAR(created_at) AS yr, week_of_year 
    FROM tickets 
    WHERE $where_sql 
    ORDER BY yr DESC, week_of_year DESC 
    LIMIT 6
";
$weeks_res = $conn->query($sql_weeks);
$disp_weeks = [];
while ($row = $weeks_res->fetch_assoc()) {
    $disp_weeks[] = [
        'yr' => (int)$row['yr'],
        'week' => (int)$row['week_of_year'],
        'label' => "W" . $row['week_of_year'] . " " . $row['yr']
    ];
}
$disp_weeks = array_reverse($disp_weeks);

$sql_disp = "
    SELECT week_of_year, YEAR(created_at) AS yr, dispatcher_name, COUNT(*) as count
    FROM tickets
    WHERE $where_sql AND dispatcher_name != ''
    GROUP BY yr, week_of_year, dispatcher_name
    ORDER BY yr ASC, week_of_year ASC, count DESC
";
$disp_res = $conn->query($sql_disp);
$dispatcher_raw_data = [];
$dispatchers_list = [];
while ($row = $disp_res->fetch_assoc()) {
    $key = "W" . $row['week_of_year'] . " " . $row['yr'];
    $dispatcher_raw_data[$key][$row['dispatcher_name']] = (int)$row['count'];
    if (!in_array($row['dispatcher_name'], $dispatchers_list)) {
        $dispatchers_list[] = $row['dispatcher_name'];
    }
}

$dispatcher_labels = array_column($disp_weeks, 'label');
$dispatcher_datasets = [];
$dispatcher_colors = ['#ea580c', '#0c1938', '#2b7de9', '#c5cdd6', '#7c5cbf', '#4db6ac', '#f59e0b'];

foreach ($dispatchers_list as $index => $disp_name) {
    $data_pts = [];
    foreach ($dispatcher_labels as $w_label) {
        $data_pts[] = $dispatcher_raw_data[$w_label][$disp_name] ?? 0;
    }
    $dispatcher_datasets[] = [
        'label' => $disp_name,
        'data' => $data_pts,
        'backgroundColor' => $dispatcher_colors[$index % count($dispatcher_colors)]
    ];
}

$sql_sla = "
    SELECT assigned_technician,
           SUM(CASE WHEN sla_is_broken = 0 THEN 1 ELSE 0 END) AS compliant,
           SUM(CASE WHEN sla_is_broken = 1 THEN 1 ELSE 0 END) AS non_compliant
    FROM tickets
    WHERE $where_sql AND assigned_technician != ''
    GROUP BY assigned_technician
    ORDER BY COUNT(*) DESC
    LIMIT 10
";
$sla_res = $conn->query($sql_sla);
$sla_labels = [];
$sla_compliant = [];
$sla_non_compliant = [];
while ($row = $sla_res->fetch_assoc()) {
    $sla_labels[] = $row['assigned_technician'];
    $sla_compliant[] = (int)$row['compliant'];
    $sla_non_compliant[] = (int)$row['non_compliant'];
}

$sql_workload = "
    SELECT assigned_technician AS staff_name,
           COUNT(*) AS ticket_count,
           SUM(workload_minutes) AS total_workload_min,
           AVG(workload_minutes) AS avg_workload_min
    FROM tickets
    WHERE $where_sql AND assigned_technician != ''
    GROUP BY assigned_technician
    ORDER BY ticket_count DESC
";
$workload_res = $conn->query($sql_workload);
$workload_rows = [];
while ($row = $workload_res->fetch_assoc()) {
    $workload_rows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leyton IT Support Analytics - Tickets Analysis</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
</head>
<body>

    <!-- 1. PORTAL HEADER BAR -->
    <div class="portal-header">
        <div class="portal-header-left">
            <div class="brand-logo-portal">
                <span class="ley">LEY</span><span class="ton">T<span class="o-circle">O</span>N</span>
            </div>
            <div class="portal-divider"></div>
            <span class="portal-breadcrumb" style="font-weight: 600; color: #e2e8f0;">Support IT Dashboard - Tickets Analysis</span>
        </div>
        <div class="portal-header-right">
            <div class="diagram-search-box">
                <span class="search-icon">&#128269;</span>
                <input type="text" id="diagramSearch" class="portal-search" placeholder="Search diagrams..." autocomplete="off">
                <span id="searchClearBtn" class="search-clear-btn" onclick="clearDiagramSearch()" title="Clear search">&times;</span>
                <div id="diagramSuggestions" class="diagram-suggestions-dropdown"></div>
            </div>
            <div class="portal-user" title="<?= htmlspecialchars($current_user_role . ($current_user_team ? ' — Team: ' . $current_user_team : '')) ?>">
                <span><?= htmlspecialchars($current_user_name) ?></span>
                <div class="portal-avatar">
                    <?php
                        $name_parts = preg_split('/\s+/', trim($current_user_name));
                        $initials = '';
                        if (count($name_parts) >= 2) {
                            $initials = strtoupper(mb_substr($name_parts[0], 0, 1) . mb_substr(end($name_parts), 0, 1));
                        } else {
                            $initials = strtoupper(mb_substr(trim($current_user_name), 0, 2));
                        }
                        echo htmlspecialchars($initials);
                    ?>
                </div>
            </div>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <!-- 2. PORTAL SUB-HEADER BAR -->
    <div class="portal-sub-header">
        <div class="sub-header-left">
            <a href="generatereport.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="sub-header-item" style="text-decoration:none;color:inherit;cursor:pointer;"><span class="icon">&#128202;</span><span>Generate Report</span></a>
            <div class="sub-header-item" onclick="window.location.reload()" style="cursor:pointer;"><span class="icon">&#8635;</span><span>Actualiser</span></div>
        </div>
    </div>

    <!-- 3. MAIN WORKSPACE WITH SIDEBAR & DASHBOARD CANVAS -->
    <div class="portal-workspace">
        
        <!-- SIDEBAR FILTERS -->
        <div class="sidebar">
            <div class="sidebar-logo-sub">
                <span class="ley">LEY</span><span class="ton">T<span class="o-circle">O</span>N</span>
            </div>

            <form id="filterForm" method="GET" action="index.php">
                <?php if (!$is_restricted): ?>
                
                <!-- UNTOUCHED: Filtred Date Section -->
                <div class="filter-section">
                    <div class="filter-title">Filtred Date</div>
                    <ul class="year-tree">
                        <?php foreach ($years_list as $yr): 
                            $is_year_checked = in_array($yr, $selected_years);
                            $is_expanded = ($yr === 2026 || count(array_filter($selected_months, function($m) use ($yr) { return strpos($m, "$yr-") === 0; })) > 0);
                        ?>
                            <li class="year-item">
                                <div class="year-header">
                                    <span class="year-toggle-btn" onclick="toggleYear(<?= $yr ?>, event)"><?= $is_expanded ? '▼' : '▶' ?></span>
                                    <input type="checkbox" name="years[]" value="<?= $yr ?>" class="year-checkbox" 
                                           <?= $is_year_checked ? 'checked' : '' ?>
                                           onchange="this.form.submit()">
                                    <span><?= $yr ?></span>
                                </div>
                                <ul class="month-list" id="months-<?= $yr ?>" style="display: <?= $is_expanded ? 'block' : 'none' ?>;">
                                    <?php 
                                    $months = [
                                        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
                                    ];
                                    foreach ($months as $m_num => $m_name): 
                                        $m_val = sprintf("%d-%02d", $yr, $m_num);
                                        $is_month_checked = in_array($m_val, $selected_months);
                                    ?>
                                        <li class="month-item">
                                            <input type="checkbox" name="months[]" value="<?= $m_val ?>" class="year-checkbox"
                                                   <?= $is_month_checked ? 'checked' : '' ?>
                                                   onchange="onMonthCheck(<?= $yr ?>, this)">
                                            <span><?= $m_name ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- STANDARD SELECT DROPDOWNS MATCHING PIC 2 & PIC 3 EXACTLY -->
                <div class="filter-section">
                    <div class="filter-title">REGION/NAME</div>
                    <select name="region" class="form-select">
                        <option value="">Tout</option>
                        <?php foreach ($regions_list as $reg): ?>
                            <option value="<?= htmlspecialchars($reg) ?>" <?= $selected_region === $reg ? 'selected' : '' ?>>
                                <?= htmlspecialchars($reg) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-section">
                    <div class="filter-title">COUNTRY</div>
                    <select name="country" class="form-select">
                        <option value="">Tout</option>
                        <?php foreach ($countries_list as $cnt): ?>
                            <option value="<?= htmlspecialchars($cnt) ?>" <?= $selected_country === $cnt ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cnt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-section">
                    <div class="filter-title">CATEGORY</div>
                    <select name="category" class="form-select">
                        <option value="">Tout</option>
                        <?php foreach ($categories_list as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $selected_cat === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-section">
                    <div class="filter-title">ASSIGNED USER/IT TEAM</div>
                    <select name="team" class="form-select">
                        <option value="">Tout</option>
                        <?php foreach ($teams_list as $t_code): ?>
                            <option value="<?= htmlspecialchars($t_code) ?>" <?= $selected_team === $t_code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t_code) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn-submit-filters" style="margin-top: 15px;">Apply Filters</button>
                <a href="index.php" class="btn-reset-filters">Reset Filters</a>
                <?php else: ?>
                <div class="empty-state" style="padding: 10px; color: #475569; text-align: center; border: 1px dashed #c5ccd6; margin-top: 20px;">
                    Filters are locked to your team's context.
                </div>
                <?php endif; ?>
            </form>

            <div class="refresh-date">
                Last refresh date :<br>
                06/07/2026 21.01
            </div>
        </div>

        <!-- DASHBOARD CONTAINER -->
        <div class="main-content">
            <!-- TOP DARK NAV BANNER -->
            <div class="stats-banner" data-diagram="kpi" data-title="KPI Summary Total tickets Average Time to Assign Resolve Incidents Service Requests">
                <div class="kpi-container">
                    <div class="kpi-item">
                        <span class="kpi-label">Total tickets</span>
                        <span class="kpi-value"><?= number_format($total_tickets, 0, '.', ' ') ?></span>
                    </div>
                    <div class="kpi-item">
                        <span class="kpi-label">Average Time to Assign</span>
                        <span class="kpi-value orange"><?= $avg_assign_str ?></span>
                    </div>
                    <div class="kpi-item">
                        <span class="kpi-label">Average Time to Resolve</span>
                        <span class="kpi-value orange"><?= $avg_resolve_str ?></span>
                    </div>
                </div>

                <div class="banner-right">
                    <div class="mini-donut-container">
                        <div class="mini-donut-wrap">
                            <canvas id="miniDonut" class="mini-donut-canvas" width="128" height="128"></canvas>
                            <div class="mini-donut-center"><?= number_format($incidents_count + $requests_count) ?></div>
                        </div>
                        <div class="donut-legend">
                            <div class="legend-item">
                                <span class="bullet incident"></span>
                                <span>incident (<?= number_format($incidents_count) ?>)</span>
                            </div>
                            <div class="legend-item">
                                <span class="bullet request"></span>
                                <span>service_requests (<?= number_format($requests_count) ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DASHBOARD GRAPH GRID -->
            <div class="widgets-grid">
                <!-- 1. Assigned Tickets -->
                <div class="widget-card" data-diagram="assigned" data-title="Assigned Tickets Weekly Volume Trend">
                    <div class="widget-header">
                        <span class="widget-title">Assigned Tickets</span>
                    </div>
                    <div class="widget-body">
                        <div class="chart-wrapper">
                            <?php if (empty($assigned_data)): ?>
                                <div class="empty-state">No data matches filters</div>
                            <?php else: ?>
                                <canvas id="assignedChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 3. Tickets / Category -->
                <div class="widget-card" data-diagram="category" data-title="Tickets / Category Pie Chart">
                    <div class="widget-header">
                        <span class="widget-title">Tickets / Category</span>
                    </div>
                    <div class="widget-body">
                        <div class="chart-wrapper">
                            <?php if (empty($category_data)): ?>
                                <div class="empty-state">No data matches filters</div>
                            <?php else: ?>
                                <canvas id="categoryChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2. Assigned Tickets / Team -->
                <div class="widget-card" data-diagram="team" data-title="Assigned Tickets / Team Donut Chart">
                    <div class="widget-header">
                        <span class="widget-title">assigned Tickets / Team</span>
                    </div>
                    <div class="widget-body">
                        <div class="chart-wrapper">
                            <?php if (empty($team_data)): ?>
                                <div class="empty-state">No data matches filters</div>
                            <?php else: ?>
                                <canvas id="teamChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 4. Resolved Tickets / Users -->
                <div class="widget-card" data-diagram="resolved" data-title="Resolved tickets / User Technician Stacked Bar">
                    <div class="widget-header">
                        <span class="widget-title">Resolved tickets / User</span>
                    </div>
                    <div class="widget-body">
                        <div class="chart-wrapper">
                            <?php if (empty($resolved_user_labels)): ?>
                                <div class="empty-state">No data matches filters</div>
                            <?php else: ?>
                                <canvas id="resolvedUserChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 5. Tickets / Dispatcher -->
                <div class="widget-card" data-diagram="dispatcher" data-title="Tickets / Dispatcher Grouped Columns">
                    <div class="widget-header">
                        <span class="widget-title">Tickets / Dispatcher</span>
                    </div>
                    <div class="widget-body">
                        <div class="chart-wrapper">
                            <?php if (empty($dispatcher_labels)): ?>
                                <div class="empty-state">No data matches filters</div>
                            <?php else: ?>
                                <canvas id="dispatcherChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 6. Tickets / Sla Resolve Is Broken -->
                <div class="widget-card" data-diagram="sla" data-title="Tickets / Sla Resolve Is Broken SLA Compliance">
                    <div class="widget-header">
                        <span class="widget-title">Tickets / Sla Resolve Is Broken</span>
                    </div>
                    <div class="widget-body">
                        <div class="chart-wrapper">
                            <?php if (empty($sla_labels)): ?>
                                <div class="empty-state">No data matches filters</div>
                            <?php else: ?>
                                <canvas id="slaChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 7. Workload time Table -->
                <div class="widget-card full-width" data-diagram="workload" data-title="Tableau Workload Time Staff Workload Table">
                    <div class="widget-header">
                        <span class="widget-title">Tableau Workload Time</span>
                    </div>
                    <div class="widget-body" style="display: block; min-height: auto;">
                        <?php if (empty($workload_rows)): ?>
                            <div class="empty-state">No workload data matches filters</div>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="workload-table">
                                    <thead>
                                        <tr>
                                            <th>Staff Name</th>
                                            <th class="number-cell" style="width: 150px;">Number of tickets</th>
                                            <th style="width: 180px;">Total Workload</th>
                                            <th style="width: 180px;">Average Workload</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($workload_rows as $row): 
                                            $t_min = (int)$row['total_workload_min'];
                                            $t_hrs = floor($t_min / 60);
                                            $t_mins = $t_min % 60;
                                            $total_str = $t_hrs > 0 ? "{$t_hrs}h {$t_mins}min" : "{$t_mins}min";

                                            $a_min = round($row['avg_workload_min']);
                                            $a_hrs = floor($a_min / 60);
                                            $a_mins = $a_min % 60;
                                            $avg_str = $a_hrs > 0 ? "{$a_hrs}h {$a_mins}min" : "{$a_mins}min";
                                        ?>
                                            <tr>
                                                <td class="tech-cell"><?= htmlspecialchars($row['staff_name']) ?></td>
                                                <td class="number-cell"><?= number_format($row['ticket_count']) ?></td>
                                                <td><?= $total_str ?></td>
                                                <td><?= $avg_str ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
    Chart.register(ChartDataLabels);

    function toggleYear(yr, e) {
        if (e) e.preventDefault();
        const el = document.getElementById('months-' + yr);
        const btn = e ? e.target : null;
        if (el) {
            if (el.style.display === 'none') {
                el.style.display = 'block';
                if(btn) btn.textContent = '▼';
            } else {
                el.style.display = 'none';
                if(btn) btn.textContent = '▶';
            }
        }
    }

    function onYearCheck(yr, chk) {
        if (!chk.checked) {
            const yrContainer = chk.closest('.year-item');
            if (yrContainer) {
                yrContainer.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = false);
            }
        }
        chk.form.submit();
    }

    function onMonthCheck(yr, chk) {
        if (chk.checked) {
            const yrChk = chk.closest('.year-item').querySelector('.year-header input[name="years[]"]');
            if (yrChk && !yrChk.checked) {
                yrChk.checked = true;
            }
        }
        chk.form.submit();
    }

    // Charts JS Rendering
    const ctxMini = document.getElementById('miniDonut').getContext('2d');
    new Chart(ctxMini, {
        type: 'doughnut',
        data: {
            labels: ['incident', 'service_requests'],
            datasets: [{
                data: [<?= $incidents_count ?>, <?= $requests_count ?>],
                backgroundColor: ['#ea580c', '#d6dee8'],
                borderWidth: 2,
                borderColor: '#0c1938',
                hoverOffset: 2
            }]
        },
        options: {
            responsive: false,
            maintainAspectRatio: true,
            aspectRatio: 1,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true },
                datalabels: { display: false }
            },
            layout: { padding: 0 }
        }
    });

    <?php if (!empty($assigned_data)): ?>
    const ctxAssigned = document.getElementById('assignedChart').getContext('2d');
    new Chart(ctxAssigned, {
        type: 'line',
        data: {
            labels: <?= json_encode($assigned_labels) ?>,
            datasets: [{
                label: 'Number of assigned tickets',
                data: <?= json_encode($assigned_data) ?>,
                borderColor: '#0c1938',
                backgroundColor: 'rgba(12, 25, 56, 0.06)',
                fill: true,
                tension: 0.2,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#0c1938',
                pointBorderColor: '#0c1938'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                datalabels: {
                    align: 'top',
                    font: { size: 9, weight: 'bold', family: 'Inter' },
                    color: '#1e293b',
                    formatter: (v) => v
                }
            },
            scales: {
                y: { grid: { borderDash: [2, 4], color: '#cbd5e1' }, ticks: { color: '#475569', font: { size: 10, family: 'Inter' } } },
                x: { grid: { display: false }, ticks: { color: '#475569', font: { size: 9, family: 'Inter' } } }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($team_data)): ?>
    const ctxTeam = document.getElementById('teamChart').getContext('2d');
    new Chart(ctxTeam, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($team_labels) ?>,
            datasets: [{
                data: <?= json_encode($team_data) ?>,
                backgroundColor: ['#0c1938', '#2b7de9', '#ea580c', '#10b981', '#7c5cbf', '#4db6ac', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4', '#64748b'],
                borderWidth: 1.5,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '48%',
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 8, font: { size: 10, family: 'Inter' }, color: '#1e293b' } },
                datalabels: {
                    color: '#ffffff',
                    font: { size: 9, weight: 'bold', family: 'Inter' },
                    formatter: (value, ctx) => {
                        let sum = 0;
                        let dataArr = ctx.chart.data.datasets[0].data;
                        dataArr.map(data => { sum += data; });
                        return (value*100 / sum).toFixed(0)+"%";
                    }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($category_data)): ?>
    const ctxCategory = document.getElementById('categoryChart').getContext('2d');
    new Chart(ctxCategory, {
        type: 'pie',
        data: {
            labels: <?= json_encode($category_labels) ?>,
            datasets: [{
                data: <?= json_encode($category_data) ?>,
                backgroundColor: ['#0c1938', '#2b7de9', '#ea580c', '#10b981', '#7c5cbf', '#f59e0b', '#06b6d4', '#ec4899'],
                borderWidth: 1.5,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 8, font: { size: 10, family: 'Inter' }, color: '#1e293b' } },
                datalabels: {
                    color: '#ffffff',
                    font: { size: 9, weight: 'bold', family: 'Inter' },
                    formatter: (value, ctx) => {
                        let sum = 0;
                        let dataArr = ctx.chart.data.datasets[0].data;
                        dataArr.map(data => { sum += data; });
                        return (value*100 / sum).toFixed(0)+"%";
                    }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($resolved_user_labels)): ?>
    const ctxResUser = document.getElementById('resolvedUserChart').getContext('2d');
    new Chart(ctxResUser, {
        type: 'bar',
        data: {
            labels: <?= json_encode($resolved_user_labels) ?>,
            datasets: [
                { label: 'True', data: <?= json_encode($resolved_user_resolved) ?>, backgroundColor: '#4db6ac' },
                { label: 'False', data: <?= json_encode($resolved_user_unresolved) ?>, backgroundColor: '#ea580c' }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10, family: 'Inter' }, color: '#1e293b' } },
                datalabels: { display: false }
            },
            scales: {
                x: { stacked: true, grid: { borderDash: [2, 4], color: '#cbd5e1' }, ticks: { font: { size: 9, family: 'Inter' }, color: '#475569' } },
                y: { stacked: true, grid: { display: false }, ticks: { font: { size: 9, family: 'Inter' }, color: '#1e293b' } }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($dispatcher_labels)): ?>
    const ctxDispatcher = document.getElementById('dispatcherChart').getContext('2d');
    new Chart(ctxDispatcher, {
        type: 'bar',
        data: {
            labels: <?= json_encode($dispatcher_labels) ?>,
            datasets: <?= json_encode($dispatcher_datasets) ?>
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 9, family: 'Inter' }, color: '#1e293b' } },
                datalabels: { display: false }
            },
            scales: {
                y: { grid: { borderDash: [2, 4], color: '#cbd5e1' }, ticks: { font: { size: 9, family: 'Inter' }, color: '#475569' } },
                x: { grid: { display: false }, ticks: { font: { size: 9, family: 'Inter' }, color: '#475569' } }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($sla_labels)): ?>
    const ctxSla = document.getElementById('slaChart').getContext('2d');
    new Chart(ctxSla, {
        type: 'bar',
        data: {
            labels: <?= json_encode($sla_labels) ?>,
            datasets: [
                { label: 'compliant', data: <?= json_encode($sla_compliant) ?>, backgroundColor: '#ea580c' },
                { label: 'Non compliant', data: <?= json_encode($sla_non_compliant) ?>, backgroundColor: '#0c1938' }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10, family: 'Inter' }, color: '#1e293b' } },
                datalabels: { display: false }
            },
            scales: {
                x: { stacked: true, grid: { borderDash: [2, 4], color: '#cbd5e1' }, ticks: { font: { size: 9, family: 'Inter' }, color: '#475569' } },
                y: { stacked: true, grid: { display: false }, ticks: { font: { size: 9, family: 'Inter' }, color: '#1e293b' } }
            }
        }
    });
    <?php endif; ?>

    // Interactive Diagram Search System
    const diagramCatalog = [
        { id: 'assigned', name: 'Assigned Tickets', icon: '📈', desc: 'Weekly volume trend' },
        { id: 'team', name: 'Assigned Tickets / Team', icon: '🍩', desc: 'Team distribution' },
        { id: 'category', name: 'Tickets / Category', icon: '🥧', desc: 'Category breakdown' },
        { id: 'resolved', name: 'Resolved Tickets / User', icon: '📊', desc: 'Technician resolution' },
        { id: 'dispatcher', name: 'Tickets / Dispatcher', icon: '📊', desc: 'Weekly dispatcher volume' },
        { id: 'sla', name: 'Tickets / SLA Broken', icon: '⚖️', desc: 'SLA compliance metrics' },
        { id: 'workload', name: 'Tableau Workload Time', icon: '📋', desc: 'Staff workload table' },
        { id: 'kpi', name: 'KPI Summary', icon: '🔢', desc: 'Overview & metrics' }
    ];

    const searchInput = document.getElementById('diagramSearch');
    const clearBtn = document.getElementById('searchClearBtn');
    const dropdown = document.getElementById('diagramSuggestions');

    function renderDiagramSuggestions(query = '') {
        const q = query.toLowerCase().trim();
        const matches = diagramCatalog.filter(d => 
            !q || d.name.toLowerCase().includes(q) || d.desc.toLowerCase().includes(q) || d.id.includes(q)
        );

        if (matches.length === 0) {
            dropdown.innerHTML = '<div style="padding: 10px 14px; font-size: 12px; color: #94a3b8;">No matching diagram found</div>';
            dropdown.style.display = 'block';
            return;
        }

        dropdown.innerHTML = matches.map(d => `
            <div class="diagram-suggestion-item" onclick="selectDiagram('${d.id}', '${d.name.replace(/'/g, "\\'")}')">
                <span class="item-icon">${d.icon}</span>
                <span style="font-weight: 500;">${d.name}</span>
                <span class="item-desc">${d.desc}</span>
            </div>
        `).join('');
        dropdown.style.display = 'block';
    }

    function filterDiagrams(query = '') {
        const q = query.toLowerCase().trim();
        const cards = document.querySelectorAll('.widget-card');
        const banner = document.querySelector('.stats-banner');

        if (!q) {
            cards.forEach(c => c.style.display = '');
            if (banner) banner.style.display = '';
            return;
        }

        let foundAny = false;
        cards.forEach(card => {
            const title = (card.getAttribute('data-title') || '').toLowerCase();
            if (title.includes(q)) {
                card.style.display = '';
                foundAny = true;
            } else {
                card.style.display = 'none';
            }
        });

        if (banner) {
            const bannerTitle = (banner.getAttribute('data-title') || '').toLowerCase();
            banner.style.display = bannerTitle.includes(q) || !foundAny ? '' : 'none';
        }
    }

    function selectDiagram(id, name) {
        if (!searchInput) return;
        searchInput.value = name;
        if (clearBtn) clearBtn.style.display = 'block';
        if (dropdown) dropdown.style.display = 'none';

        document.querySelectorAll('.widget-card, .stats-banner').forEach(c => c.style.display = '');

        const target = document.querySelector(`[data-diagram="${id}"]`);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.remove('highlight-pulse');
            void target.offsetWidth;
            target.classList.add('highlight-pulse');
        }
    }

    function clearDiagramSearch() {
        if (!searchInput) return;
        searchInput.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
        if (dropdown) dropdown.style.display = 'none';
        filterDiagrams('');
        searchInput.focus();
    }

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const val = e.target.value;
            if (clearBtn) clearBtn.style.display = val ? 'block' : 'none';
            renderDiagramSuggestions(val);
            filterDiagrams(val);
        });

        searchInput.addEventListener('focus', () => {
            renderDiagramSuggestions(searchInput.value);
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.diagram-search-box') && dropdown) {
                dropdown.style.display = 'none';
            }
        });
    }
    </script>
</body>
</html>