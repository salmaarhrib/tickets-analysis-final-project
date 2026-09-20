<?php
session_start();
require 'db.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch authenticated user info
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

$is_restricted = in_array($current_user_role, ['Standard Employee', 'Technician', 'Dispatcher']);

// --- AJAX API Endpoint for Report Data ---
if (isset($_GET['action']) && $_GET['action'] === 'get_report_data') {
    header('Content-Type: application/json');

    $filter_team     = $_POST['team'] ?? $_GET['team'] ?? '';
    $filter_category = $_POST['category'] ?? $_GET['category'] ?? '';
    $filter_metric   = $_POST['metric'] ?? $_GET['metric'] ?? 'all';
    $filter_tech     = $_POST['tech'] ?? $_GET['tech'] ?? '';
    $filter_years    = isset($_POST['years']) && is_array($_POST['years']) ? array_map('intval', $_POST['years']) : 
                       (isset($_GET['years']) && is_array($_GET['years']) ? array_map('intval', $_GET['years']) : []);
    $filter_months   = isset($_POST['months']) && is_array($_POST['months']) ? $_POST['months'] : 
                       (isset($_GET['months']) && is_array($_GET['months']) ? $_GET['months'] : []);
    $filter_weeks    = isset($_POST['weeks']) && is_array($_POST['weeks']) ? $_POST['weeks'] : 
                       (isset($_GET['weeks']) && is_array($_GET['weeks']) ? $_GET['weeks'] : []);

    $filter_teams = isset($_POST['teams']) && is_array($_POST['teams']) ? $_POST['teams'] : 
                    (isset($_GET['teams']) && is_array($_GET['teams']) ? $_GET['teams'] : 
                    (!empty($_POST['team']) && $_POST['team'] !== 'all' ? [$_POST['team']] : 
                    (!empty($_GET['team']) && $_GET['team'] !== 'all' ? [$_GET['team']] : [])));

    $filter_categories = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : 
                         (isset($_GET['categories']) && is_array($_GET['categories']) ? $_GET['categories'] : 
                         (!empty($_POST['category']) && $_POST['category'] !== 'all' ? [$_POST['category']] : 
                         (!empty($_GET['category']) && $_GET['category'] !== 'all' ? [$_GET['category']] : [])));

    $filter_regions = isset($_POST['regions']) && is_array($_POST['regions']) ? $_POST['regions'] : 
                      (isset($_GET['regions']) && is_array($_GET['regions']) ? $_GET['regions'] : 
                      (!empty($_POST['region']) ? [$_POST['region']] : 
                      (!empty($_GET['region']) ? [$_GET['region']] : [])));

    $filter_countries = isset($_POST['countries']) && is_array($_POST['countries']) ? $_POST['countries'] : 
                        (isset($_GET['countries']) && is_array($_GET['countries']) ? $_GET['countries'] : 
                        (!empty($_POST['country']) ? [$_POST['country']] : 
                        (!empty($_GET['country']) ? [$_GET['country']] : [])));

    $where_clauses = ["1=1"];

    if ($is_restricted) {
        $esc_name = $conn->real_escape_string($current_user_name);
        $esc_team = $conn->real_escape_string($current_user_team);
        $where_clauses[] = "(assigned_technician = '$esc_name' OR team_code = '$esc_team')";
    }

    // Date filtering (Years, Months, Weeks)
    $date_clauses = [];
    if (!empty($filter_years)) {
        foreach ($filter_years as $yr) {
            $months_for_year = array_filter($filter_months, function($m) use ($yr) {
                return strpos($m, "$yr-") === 0;
            });
            if (!empty($months_for_year)) {
                $month_conditions = [];
                foreach ($months_for_year as $m_val) {
                    $m_num = (int)substr($m_val, 5, 2);
                    $weeks_for_month = array_filter($filter_weeks, function($w) use ($m_val) {
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
    } elseif (!empty($filter_weeks)) {
        $week_conditions = [];
        foreach ($filter_weeks as $w_val) {
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
    } elseif (!empty($filter_months)) {
        $month_conditions = [];
        foreach ($filter_months as $m_val) {
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

    if (!empty($filter_regions)) {
        $esc_regions = array_map(function($r) use ($conn) {
            return "'" . $conn->real_escape_string($r) . "'";
        }, $filter_regions);
        $where_clauses[] = "team_code IN (SELECT team_code FROM teams WHERE region IN (" . implode(',', $esc_regions) . "))";
    }

    if (!empty($filter_countries)) {
        $esc_countries = array_map(function($c) use ($conn) {
            return "'" . $conn->real_escape_string($c) . "'";
        }, $filter_countries);
        $where_clauses[] = "team_code IN (SELECT team_code FROM teams WHERE country IN (" . implode(',', $esc_countries) . "))";
    }

    if (!empty($filter_teams)) {
        $esc_teams = array_map(function($t) use ($conn) {
            return "'" . $conn->real_escape_string($t) . "'";
        }, $filter_teams);
        $where_clauses[] = "team_code IN (" . implode(',', $esc_teams) . ")";
    }

    if (!empty($filter_categories)) {
        $esc_cats = array_map(function($c) use ($conn) {
            return "'" . $conn->real_escape_string($c) . "'";
        }, $filter_categories);
        $where_clauses[] = "category_name IN (" . implode(',', $esc_cats) . ")";
    }

    if ($filter_tech !== '') {
        $tech_esc = $conn->real_escape_string($filter_tech);
        $where_clauses[] = "assigned_technician = '$tech_esc'";
    }

    $where_sql = implode(' AND ', $where_clauses);

    // 1. KPI Aggregates
    $tot_row = $conn->query("SELECT COUNT(*) as c FROM tickets WHERE $where_sql")->fetch_assoc();
    $total_tickets = (int)($tot_row['c'] ?? 0);

    // SLA stats
    $sla_row = $conn->query("
        SELECT 
            SUM(CASE WHEN sla_is_broken = 0 THEN 1 ELSE 0 END) AS compliant,
            SUM(CASE WHEN sla_is_broken = 1 THEN 1 ELSE 0 END) AS broken
        FROM tickets 
        WHERE $where_sql
    ")->fetch_assoc();
    $sla_compliant = (int)($sla_row['compliant'] ?? 0);
    $sla_broken = (int)($sla_row['broken'] ?? 0);
    $sla_rate = $total_tickets > 0 ? round(($sla_compliant / $total_tickets) * 100, 1) : 100;

    // Average assign time & resolve time
    $avg_workload_row = $conn->query("SELECT AVG(workload_minutes) as avg FROM tickets WHERE $where_sql")->fetch_assoc();
    $avg_workload_min = round($avg_workload_row['avg'] ?? 0);
    $avg_assign_min = round($avg_workload_min / 8);
    $assign_hours = floor($avg_assign_min / 60);
    $assign_mins = $avg_assign_min % 60;
    $avg_assign_str = $assign_hours > 0 ? "{$assign_hours}h {$assign_mins}min" : "{$assign_mins}min";

    $avg_res_row = $conn->query("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg 
        FROM tickets 
        WHERE status IN ('Resolved', 'Closed') AND $where_sql
    ")->fetch_assoc();
    $avg_res_min = round($avg_res_row['avg'] ?? 0);
    $res_hours = floor($avg_res_min / 60);
    $res_mins = $avg_res_min % 60;
    $avg_resolve_str = $res_hours > 0 ? "{$res_hours}h {$res_mins}min" : "{$res_mins}min";

    // Incidents vs Requests
    $type_row = $conn->query("
        SELECT 
            SUM(CASE WHEN category_name IN ('Software', 'Hardware', 'Database') THEN 1 ELSE 0 END) AS incidents,
            SUM(CASE WHEN category_name IN ('Access / Security', 'Business Application') THEN 1 ELSE 0 END) AS requests
        FROM tickets 
        WHERE $where_sql
    ")->fetch_assoc();
    $incidents_count = (int)($type_row['incidents'] ?? 0);
    $requests_count = (int)($type_row['requests'] ?? 0);

    // 2. Categories Breakdown
    $cat_res = $conn->query("
        SELECT category_name, COUNT(*) as count,
               SUM(CASE WHEN sla_is_broken = 1 THEN 1 ELSE 0 END) as broken_count
        FROM tickets 
        WHERE $where_sql 
        GROUP BY category_name 
        ORDER BY count DESC
    ");
    $categories_data = [];
    while ($r = $cat_res->fetch_assoc()) {
        $c_count = (int)$r['count'];
        $categories_data[] = [
            'name' => $r['category_name'],
            'count' => $c_count,
            'percent' => $total_tickets > 0 ? round(($c_count / $total_tickets) * 100, 1) : 0,
            'sla_broken' => (int)$r['broken_count']
        ];
    }

    // 3. Teams Breakdown
    $team_res = $conn->query("
        SELECT team_code, COUNT(*) as count,
               SUM(CASE WHEN sla_is_broken = 0 THEN 1 ELSE 0 END) as compliant,
               SUM(CASE WHEN sla_is_broken = 1 THEN 1 ELSE 0 END) as broken
        FROM tickets 
        WHERE $where_sql 
        GROUP BY team_code 
        ORDER BY count DESC
    ");
    $teams_data = [];
    while ($r = $team_res->fetch_assoc()) {
        $t_count = (int)$r['count'];
        $comp = (int)$r['compliant'];
        $teams_data[] = [
            'team_code' => $r['team_code'],
            'count' => $t_count,
            'sla_rate' => $t_count > 0 ? round(($comp / $t_count) * 100, 1) : 100
        ];
    }

    // 4. Top Technicians
    $tech_res = $conn->query("
        SELECT assigned_technician, COUNT(*) as count,
               SUM(CASE WHEN status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) as resolved,
               SUM(CASE WHEN sla_is_broken = 0 THEN 1 ELSE 0 END) as compliant,
               AVG(workload_minutes) as avg_workload
        FROM tickets 
        WHERE $where_sql AND assigned_technician != ''
        GROUP BY assigned_technician 
        ORDER BY count DESC 
        LIMIT 6
    ");
    $technicians_data = [];
    while ($r = $tech_res->fetch_assoc()) {
        $tc = (int)$r['count'];
        $comp = (int)$r['compliant'];
        $technicians_data[] = [
            'name' => $r['assigned_technician'],
            'count' => $tc,
            'resolved' => (int)$r['resolved'],
            'sla_rate' => $tc > 0 ? round(($comp / $tc) * 100, 1) : 100,
            'avg_workload' => round($r['avg_workload'] ?? 0)
        ];
    }

    // Built-in intelligent executive summary synthesis & conclusion
    $top_cat = !empty($categories_data) ? $categories_data[0]['name'] : 'N/A';
    $top_cat_pct = !empty($categories_data) ? $categories_data[0]['percent'] : 0;
    $sla_status_text = $sla_rate >= 85 ? "satisfactory operational performance" : "needs improvement";

    $summary_paragraphs = [
        "During the selected evaluation period, the IT Support department managed a total volume of <strong>" . number_format($total_tickets) . " tickets</strong> across all active service queues. The overall Service Level Agreement (SLA) compliance reached <strong>{$sla_rate}%</strong> ({$sla_compliant} tickets resolved within SLA limit).",
        "Resolution velocity maintained an average time of <strong>{$avg_resolve_str}</strong> per resolved incident, while initial assignment latency averaged <strong>{$avg_assign_str}</strong>. Operational volume is composed of <strong>" . number_format($incidents_count) . " technical incidents</strong> and <strong>" . number_format($requests_count) . " service requests</strong>.",
        "Primary workload driver is dominated by the <strong>{$top_cat}</strong> domain, contributing to <strong>{$top_cat_pct}%</strong> of total recorded demand."
    ];

    $conclusion_text = "During the analyzed period, a total of <strong>" . number_format($total_tickets) . " tickets</strong> were processed. SLA compliance achieved <strong>{$sla_rate}%</strong>, with average resolution time of <strong>{$avg_resolve_str}</strong>. The most prevalent category was <strong>{$top_cat}</strong> ({$top_cat_pct}% of total volume). Performance metrics indicate <strong>{$sla_status_text}</strong> within established SLA targets.";

    echo json_encode([
        'success' => true,
        'kpi' => [
            'total_tickets' => $total_tickets,
            'sla_rate' => $sla_rate,
            'sla_compliant' => $sla_compliant,
            'sla_broken' => $sla_broken,
            'avg_assign_str' => $avg_assign_str,
            'avg_resolve_str' => $avg_resolve_str,
            'incidents_count' => $incidents_count,
            'requests_count' => $requests_count
        ],
        'categories' => $categories_data,
        'teams' => $teams_data,
        'technicians' => $technicians_data,
        'smart_summary' => implode("<br><br>", $summary_paragraphs),
        'conclusion' => $conclusion_text
    ]);
    exit();
}

// Fetch filter choices for form
$teams_list_res = $conn->query("SELECT team_code, team_name, region FROM teams ORDER BY team_code");
$teams_list = [];
while ($row = $teams_list_res->fetch_assoc()) {
    $teams_list[] = $row;
}

$categories_list_res = $conn->query("SELECT name FROM categories ORDER BY name");
$categories_list = [];
while ($row = $categories_list_res->fetch_assoc()) {
    $categories_list[] = $row['name'];
}

// Extract GET params passed from Dashboard
$incoming_years   = isset($_GET['years']) && is_array($_GET['years']) ? array_map('intval', $_GET['years']) : [];
$incoming_months  = isset($_GET['months']) && is_array($_GET['months']) ? $_GET['months'] : [];
$incoming_region  = $_GET['region'] ?? '';
$incoming_country = $_GET['country'] ?? '';
$incoming_cat     = $_GET['category'] ?? '';
$incoming_team    = $_GET['team'] ?? '';
$incoming_tech    = $_GET['tech'] ?? '';

// Build dynamic period label
$period_text = 'Jan 2026 - Dec 2026';
if (!empty($incoming_months)) {
    $formatted_months = array_map(function($m) {
        $ts = strtotime($m . "-01");
        return date('M Y', $ts);
    }, $incoming_months);
    $period_text = implode(', ', $formatted_months);
} elseif (!empty($incoming_years)) {
    if (count($incoming_years) === 1) {
        $period_text = "Jan " . $incoming_years[0] . " - Dec " . $incoming_years[0];
    } else {
        $min_y = min($incoming_years);
        $max_y = max($incoming_years);
        $period_text = "Jan {$min_y} - Dec {$max_y}";
    }
} else {
    $period_text = "Jan 2026 - Dec 2026";
}

// Build dynamic region/team label
$region_team_parts = [];
if (!empty($incoming_region)) {
    $region_team_parts[] = "Region: " . htmlspecialchars($incoming_region);
}
if (!empty($incoming_country)) {
    $region_team_parts[] = "Country: " . htmlspecialchars($incoming_country);
}
if (!empty($incoming_team)) {
    $region_team_parts[] = "Team: " . htmlspecialchars($incoming_team);
}
if (!empty($incoming_cat)) {
    $region_team_parts[] = "Category: " . htmlspecialchars($incoming_cat);
}
if (!empty($incoming_tech)) {
    $region_team_parts[] = "Tech: " . htmlspecialchars($incoming_tech);
}
$region_team_text = !empty($region_team_parts) ? implode(' | ', $region_team_parts) : "[User's current filters: All Teams & Regions]";

// Compute User Initials
$name_parts = preg_split('/\s+/', trim($current_user_name));
$initials = '';
if (count($name_parts) >= 2) {
    $initials = strtoupper(mb_substr($name_parts[0], 0, 1) . mb_substr(end($name_parts), 0, 1));
} else {
    $initials = strtoupper(mb_substr(trim($current_user_name), 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leyton - Generate Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        html, body {
            height: auto;
            min-height: 100vh;
            overflow-y: auto !important;
            overflow-x: hidden;
            background-color: #dfe3e8;
        }

        .report-page-container {
            width: 100%;
            max-width: 1220px;
            margin: 0 auto;
            padding: 24px 28px 60px 28px;
        }

        .report-page-header {
            margin-bottom: 20px;
        }

        .report-page-title {
            font-size: 26px;
            font-weight: 800;
            color: #0c1938;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 20px rgba(12, 25, 56, 0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .report-context-header {
            background: #f1f5f9;
            padding: 18px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .report-context-title {
            font-size: 15px;
            font-weight: 700;
            color: #0c1938;
            margin-bottom: 6px;
        }

        .report-context-text {
            font-size: 13px;
            color: #334155;
            font-weight: 400;
            line-height: 1.5;
        }

        .report-context-highlight {
            font-weight: 600;
            color: #0c1938;
        }

        .report-form-body {
            padding: 28px 28px;
        }

        .form-group-custom {
            margin-bottom: 22px;
        }

        .report-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .select-custom-navy {
            width: 100%;
            background-color: #0c1938;
            color: #ffffff;
            border: 1px solid #1c3c6e;
            border-radius: 6px;
            padding: 13px 18px;
            font-size: 14px;
            font-weight: 500;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            background-size: 14px;
        }

        .select-custom-navy:focus {
            border-color: #f05a28;
            box-shadow: 0 0 0 3px rgba(240, 90, 40, 0.25);
        }

        .select-custom-navy option {
            background-color: #0c1938;
            color: #ffffff;
            padding: 10px;
        }

        .toggle-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 26px 0 22px 0;
            user-select: none;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .25s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .25s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        input:checked + .toggle-slider {
            background-color: #0c1938;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(20px);
            background-color: #ffffff;
        }

        .toggle-label-text {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        .ai-key-input {
            width: 100%;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 9px 12px;
            font-size: 13px;
            margin-top: 10px;
            color: #0c1938;
            outline: none;
        }

        .ai-key-input:focus {
            border-color: #f05a28;
            box-shadow: 0 0 0 2px rgba(240, 90, 40, 0.2);
        }

        .report-progress-area {
            margin-bottom: 24px;
            display: none;
        }

        .report-progress-label {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }

        .report-progress-track {
            width: 100%;
            height: 6px;
            background-color: #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .report-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #f05a28 0%, #ea580c 100%);
            border-radius: 6px;
            transition: width 0.3s ease;
        }

        .report-btn-row {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            margin-top: 10px;
        }

        /* Form Main Button: Generate Report */
        .btn-generate-report {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background-color: #ea580c;
            color: #ffffff;
            font-size: 18px;
            font-weight: 700;
            padding: 14px 28px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(234, 88, 12, 0.38);
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-generate-report:hover {
            background-color: #c2410c;
            box-shadow: 0 6px 18px rgba(234, 88, 12, 0.5);
            transform: translateY(-1px);
        }

        .btn-generate-report:active {
            transform: translateY(0);
        }

        .report-help-note {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
            text-align: right;
        }

        /* Printable / PDF Report Layout Styling */
        #pdfReportContent {
            display: none;
            background: #ffffff;
            color: #0f172a;
            padding: 30px;
            font-family: 'Inter', sans-serif;
        }

        .pdf-brand-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #0c1938;
            padding-bottom: 16px;
            margin-bottom: 22px;
        }

        .pdf-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin: 20px 0;
        }

        .pdf-kpi-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            border-left: 4px solid #0c1938;
        }

        .pdf-kpi-box.orange {
            border-left-color: #ea580c;
        }

        .pdf-kpi-val {
            font-size: 22px;
            font-weight: 800;
            color: #0c1938;
        }

        .pdf-kpi-lbl {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .pdf-section-title {
            font-size: 15px;
            font-weight: 700;
            color: #0c1938;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
            margin: 22px 0 12px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .pdf-summary-text {
            font-size: 12px;
            line-height: 1.65;
            color: #334155;
            background: #f1f5f9;
            padding: 14px 18px;
            border-radius: 8px;
            border-left: 4px solid #ea580c;
        }

        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 10px;
        }

        .pdf-table th {
            background-color: #0c1938;
            color: #ffffff;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
        }

        .pdf-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }

        .pdf-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .badge-sla {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
        }
        .badge-sla-ok { background: #dcfce7; color: #15803d; }
        .badge-sla-fail { background: #fee2e2; color: #b91c1c; }

        /* Report Conclusion styling matching Pic 3 */
        .conclusion-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 16px 20px;
            border-left: 4px solid #ea580c;
            margin-top: 12px;
        }

        .conclusion-header {
            font-size: 13px;
            font-weight: 700;
            color: #0c1938;
            margin-bottom: 6px;
        }

        .conclusion-body {
            font-size: 12px;
            line-height: 1.6;
            color: #334155;
        }

        /* On-Screen Preview Modal / Card */
        #reportPreviewCard {
            display: none;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 8px 30px rgba(12, 25, 56, 0.12);
            padding: 28px;
            margin-top: 30px;
        }

        /* Download PDF button inside Preview (Pic 2) */
        .btn-download-pdf-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #ea580c;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            padding: 9px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.35);
            transition: all 0.2s ease;
        }

        .btn-download-pdf-secondary:hover {
            background-color: #c2410c;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

    <!-- 1. PORTAL HEADER BAR -->
    <div class="portal-header">
        <div class="portal-header-left">
            <div class="brand-logo-portal">
                <span class="ley">LEY</span><span class="ton">T<span class="o-circle">O</span>N</span>
            </div>
            <div class="portal-divider"></div>
            <span class="portal-breadcrumb">
                <a href="index.php" style="color: #cbd5e1; text-decoration: none;">Support IT Dashboard</a> 
                <span style="color: #64748b; margin: 0 4px;">&rsaquo;</span> 
                <span style="color: #ffffff; font-weight: 600;">Custom Report Generation</span>
            </span>
        </div>
        <div class="portal-header-right">
            <div class="diagram-search-box">
                <span class="search-icon">&#128269;</span>
                <input type="text" id="diagramSearch" class="portal-search" placeholder="Search diagrams..." autocomplete="off" onkeydown="if(event.key==='Enter'){ window.location='index.php'; }">
            </div>
            <div class="portal-user" title="<?= htmlspecialchars($current_user_role . ($current_user_team ? ' — Team: ' . $current_user_team : '')) ?>">
                <span><?= htmlspecialchars($current_user_name) ?></span>
                <div class="portal-avatar">
                    <?= htmlspecialchars($initials) ?>
                </div>
            </div>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <!-- 2. PORTAL SUB-HEADER BAR -->
    <div class="portal-sub-header">
        <div class="sub-header-left">
            <a href="index.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="sub-header-item" style="text-decoration:none; color:inherit;">
                <span class="icon">&#8592;</span><span>Back to Dashboard</span>
            </a>
            <div class="sub-header-item" style="background:#ffffff; border:1px solid #cbd5e1; font-weight:600; color:#0c1938; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                <span class="icon">&#128202;</span><span>Generate Report</span>
            </div>
            <div class="sub-header-item" onclick="window.location.reload()" style="cursor:pointer;">
                <span class="icon">&#8635;</span><span>Actualiser</span>
            </div>
        </div>
        <div class="sub-header-right">
            <span>&#128172; Commentaires</span>
        </div>
    </div>

    <!-- 3. MAIN WORKSPACE -->
    <div class="report-page-container">
        
        <div class="report-page-header">
            <h1 class="report-page-title">Generate Report - Custom Analysis</h1>
        </div>

        <div class="report-card">
            
            <div class="report-context-header">
                <div class="report-context-title">Current Report Context:</div>
                <div class="report-context-text">
                    Report Type: <span class="report-context-highlight">Support IT Tickets Analysis</span> | 
                    Selected Periods: <span class="report-context-highlight" id="lblSelectedPeriod"><?= htmlspecialchars($period_text) ?></span> | 
                    Selected Region/Team: <span class="report-context-highlight" id="lblSelectedRegionTeam"><?= htmlspecialchars($region_team_text) ?></span>
                </div>
            </div>

            <div class="report-form-body">
                
                <!-- 1. Select Team(s) -->
                <div class="form-group-custom">
                    <label class="report-label" for="reportTeamSelect">Select Team(s)</label>
                    <select id="reportTeamSelect" class="select-custom-navy" onchange="updateContextBanner()">
                        <option value="">Select Team(s) - All Teams</option>
                        <?php foreach ($teams_list as $t): ?>
                            <option value="<?= htmlspecialchars($t['team_code']) ?>" <?= ($incoming_team === $t['team_code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['team_code']) ?> &mdash; <?= htmlspecialchars($t['team_name']) ?> (<?= htmlspecialchars($t['region']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Select Category(ies) -->
                <div class="form-group-custom">
                    <label class="report-label" for="reportCategorySelect">Select Category(ies)</label>
                    <select id="reportCategorySelect" class="select-custom-navy" onchange="updateContextBanner()">
                        <option value="">Select Category(ies) - All Categories</option>
                        <?php foreach ($categories_list as $cat_name): ?>
                            <option value="<?= htmlspecialchars($cat_name) ?>" <?= ($incoming_cat === $cat_name) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat_name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 3. Add Performance Metrics -->
                <div class="form-group-custom">
                    <label class="report-label" for="reportMetricSelect">Add Performance Metrics (e.g., SLA compliance, average resolution time, top 5 issues)</label>
                    <select id="reportMetricSelect" class="select-custom-navy">
                        <option value="all">Add Performance Metrics - All (SLA compliance, avg resolution, top 5 issues, workloads)</option>
                        <option value="sla">SLA Compliance & Breach Analysis Only</option>
                        <option value="resolution">Resolution Velocity & Response Latency</option>
                        <option value="issues">Top 5 Issues & Category Distribution</option>
                        <option value="workload">Technician Workload & Productivity</option>
                    </select>
                </div>

                <!-- 4. Include Detailed Data Tables Toggle -->
                <div class="toggle-row">
                    <label class="toggle-switch">
                        <input type="checkbox" id="chkIncludeTables" checked>
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="toggle-label-text">Include Detailed Data Tables</span>
                </div>

                <div id="aiKeyContainer" style="display: none;">
                    <input type="password" id="geminiApiKey" class="ai-key-input" placeholder="Enter Gemini API Key">
                </div>

                <!-- 5. Progress Bar Area -->
                <div class="report-progress-area" id="reportProgressArea">
                    <div class="report-progress-label">
                        <span id="progressStatusText">Report is generating...</span>
                        <span id="progressPercentText" style="color: #ea580c; font-weight: 700;">0%</span>
                    </div>
                    <div class="report-progress-track">
                        <div class="report-progress-fill" id="reportProgressFill"></div>
                    </div>
                </div>

                <!-- 6. Form Main Button: Generate Report -->
                <div class="report-btn-row">
                    <button type="button" id="btnGenerateReport" class="btn-generate-report" onclick="startReportGeneration()">
                        <span>Generate Report</span>
                    </button>
                    <p class="report-help-note">This will generate a detailed summary based on your selections and data from the selected period.</p>
                </div>

            </div>

        </div>

        <!-- 7. ON-SCREEN LIVE PREVIEW CARD (Visible after generation) -->
        <div id="reportPreviewCard">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h2 style="font-size: 20px; font-weight: 800; color: #0c1938;">Generated Report Preview</h2>
                <!-- Secondary Download PDF Button (Pic 2) -->
                <button type="button" class="btn-download-pdf-secondary" onclick="triggerPdfDownloadOnly()">
                    <span>Download PDF</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                </button>
            </div>
            <div id="reportPreviewContent"></div>
        </div>

    </div>

    <!-- HIDDEN CONTAINER FOR PDF RENDERING -->
    <div id="pdfReportContent"></div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const incomingYears = <?= json_encode($incoming_years) ?>;
        const incomingMonths = <?= json_encode($incoming_months) ?>;
        const incomingRegion = <?= json_encode($incoming_region) ?>;
        const incomingCountry = <?= json_encode($incoming_country) ?>;
        const incomingTech = <?= json_encode($incoming_tech) ?>;
        const currentUserName = <?= json_encode($current_user_name) ?>;
        const currentUserRole = <?= json_encode($current_user_role) ?>;

        let generatedReportData = null;

        function updateContextBanner() {
            const team = document.getElementById("reportTeamSelect").value;
            const cat = document.getElementById("reportCategorySelect").value;
            let parts = [];
            if (incomingRegion) parts.push("Region: " + incomingRegion);
            if (incomingCountry) parts.push("Country: " + incomingCountry);
            if (team) parts.push("Team: " + team);
            if (cat) parts.push("Category: " + cat);
            if (incomingTech) parts.push("Tech: " + incomingTech);

            const text = parts.length > 0 ? parts.join(" | ") : "[User's current filters: All Teams & Regions]";
            document.getElementById("lblSelectedRegionTeam").textContent = text;
        }

        function animateProgress(targetPercent, statusMessage, callback) {
            const area = document.getElementById("reportProgressArea");
            const fill = document.getElementById("reportProgressFill");
            const label = document.getElementById("progressStatusText");
            const percentLbl = document.getElementById("progressPercentText");

            area.style.display = "block";
            if (statusMessage) label.textContent = statusMessage;

            fill.style.width = targetPercent + "%";
            percentLbl.textContent = targetPercent + "%";

            setTimeout(() => {
                if (callback) callback();
            }, 350);
        }

        async function startReportGeneration() {
            const btn = document.getElementById("btnGenerateReport");
            btn.disabled = true;
            btn.style.opacity = "0.8";

            const selectedTeam = document.getElementById("reportTeamSelect").value;
            const selectedCat = document.getElementById("reportCategorySelect").value;
            const selectedMetric = document.getElementById("reportMetricSelect").value;
            const includeTables = document.getElementById("chkIncludeTables").checked;

            animateProgress(20, "Connecting to ticket analytics database...");

            const formData = new FormData();
            formData.append("team", selectedTeam);
            formData.append("category", selectedCat);
            formData.append("metric", selectedMetric);
            formData.append("region", incomingRegion);
            formData.append("country", incomingCountry);
            formData.append("tech", incomingTech);
            incomingYears.forEach(y => formData.append("years[]", y));
            incomingMonths.forEach(m => formData.append("months[]", m));

            try {
                animateProgress(50, "Querying ticket metrics & SLA compliance...");
                const res = await fetch("generatereport.php?action=get_report_data", {
                    method: "POST",
                    body: formData
                });
                const data = await res.json();
                generatedReportData = data;

                animateProgress(80, "Synthesizing executive performance analysis...");
                const reportHtml = buildReportHtml(data, data.smart_summary, data.conclusion);

                document.getElementById("pdfReportContent").innerHTML = reportHtml;
                document.getElementById("reportPreviewContent").innerHTML = reportHtml;
                document.getElementById("reportPreviewCard").style.display = "block";

                animateProgress(100, "Report generated successfully!", () => {
                    btn.disabled = false;
                    btn.style.opacity = "1";
                    document.getElementById("reportPreviewCard").scrollIntoView({ behavior: 'smooth' });
                });

            } catch (error) {
                console.error("Report generation error:", error);
                alert("An error occurred while generating the report. Please try again.");
                btn.disabled = false;
                btn.style.opacity = "1";
                document.getElementById("reportProgressArea").style.display = "none";
            }
        }

        function triggerPdfDownloadOnly(onComplete) {
            const element = document.getElementById("pdfReportContent");
            element.style.display = "block";

            const opt = {
                margin:       [10, 12, 12, 12],
                filename:     `Leyton_IT_Support_Performance_Report_${new Date().toISOString().slice(0,10)}.pdf`,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, logging: false },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            html2pdf().set(opt).from(element).save().then(() => {
                element.style.display = "none";
                if (onComplete) onComplete();
            });
        }

        function buildReportHtml(data, summaryText, conclusionText) {
            const periodText = document.getElementById("lblSelectedPeriod").textContent;
            const contextText = document.getElementById("lblSelectedRegionTeam").textContent;
            const nowStr = new Date().toLocaleString();

            const k = data.kpi;

            let catRows = '';
            data.categories.forEach(c => {
                catRows += `
                    <tr>
                        <td style="font-weight: 600;">${c.name}</td>
                        <td>${c.count}</td>
                        <td>${c.percent}%</td>
                        <td><span class="badge-sla ${c.sla_broken > 0 ? 'badge-sla-fail' : 'badge-sla-ok'}">${c.sla_broken} breached</span></td>
                    </tr>
                `;
            });

            let teamRows = '';
            data.teams.forEach(t => {
                teamRows += `
                    <tr>
                        <td style="font-weight: 600;">${t.team_code}</td>
                        <td>${t.count}</td>
                        <td><span class="badge-sla ${t.sla_rate >= 85 ? 'badge-sla-ok' : 'badge-sla-fail'}">${t.sla_rate}%</span></td>
                    </tr>
                `;
            });

            return `
                <div style="font-family: 'Inter', sans-serif; color: #0f172a; line-height: 1.4;">
                    
                    <!-- BRAND HEADER -->
                    <div class="pdf-brand-bar">
                        <div>
                            <div style="font-size: 22px; font-weight: 800; color: #0c1938; letter-spacing: 0.5px;">
                                <span style="color: #0c1938;">LEY</span><span style="color: #0c1938;">T</span><span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;background:#ea580c;border-radius:50%;color:#ffffff;font-size:11px;margin:0 1px;">O</span><span style="color:#0c1938;">N</span>
                            </div>
                            <div style="font-size: 13px; font-weight: 700; color: #ea580c; margin-top: 3px;">SUPPORT IT PERFORMANCE ANALYSIS REPORT</div>
                        </div>
                        <div style="text-align: right; font-size: 11px; color: #64748b;">
                            <div><strong>Date:</strong> ${nowStr}</div>
                            <div><strong>Generated by:</strong> ${currentUserName} (${currentUserRole})</div>
                            <div><strong>Report Scope:</strong> ${periodText}</div>
                        </div>
                    </div>

                    <!-- CONTEXT STRIP -->
                    <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-size: 11px; color: #334155; margin-bottom: 16px;">
                        <strong>Filters Applied:</strong> ${contextText}
                    </div>

                    <!-- EXECUTIVE KPI GRID -->
                    <div class="pdf-kpi-grid">
                        <div class="pdf-kpi-box orange">
                            <div class="pdf-kpi-val">${k.total_tickets.toLocaleString()}</div>
                            <div class="pdf-kpi-lbl">Total Tickets</div>
                        </div>
                        <div class="pdf-kpi-box">
                            <div class="pdf-kpi-val">${k.sla_rate}%</div>
                            <div class="pdf-kpi-lbl">SLA Compliance Rate</div>
                        </div>
                        <div class="pdf-kpi-box">
                            <div class="pdf-kpi-val">${k.avg_resolve_str}</div>
                            <div class="pdf-kpi-lbl">Avg Resolution Time</div>
                        </div>
                        <div class="pdf-kpi-box">
                            <div class="pdf-kpi-val">${k.incidents_count} / ${k.requests_count}</div>
                            <div class="pdf-kpi-lbl">Incidents / Requests</div>
                        </div>
                    </div>

                    <!-- EXECUTIVE SUMMARY -->
                    <div class="pdf-section-title">Executive Performance Summary</div>
                    <div class="pdf-summary-text">
                        ${summaryText}
                    </div>

                    <!-- CATEGORY & TEAM BREAKDOWN TABLES -->
                    <div style="display: flex; gap: 16px; margin-top: 14px;">
                        <div style="flex: 1.2;">
                            <div class="pdf-section-title" style="margin-top: 10px;">Category Distribution</div>
                            <table class="pdf-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Tickets</th>
                                        <th>Share</th>
                                        <th>SLA Breaches</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${catRows}
                                </tbody>
                            </table>
                        </div>
                        <div style="flex: 0.8;">
                            <div class="pdf-section-title" style="margin-top: 10px;">Team Performance</div>
                            <table class="pdf-table">
                                <thead>
                                    <tr>
                                        <th>Team</th>
                                        <th>Volume</th>
                                        <th>SLA Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${teamRows}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- REPORT CONCLUSION (REPLACING DETAILED TICKETS SAMPLE TABLE) -->
                    <div class="pdf-section-title" style="margin-top: 24px;">REPORT CONCLUSION</div>
                    <div class="conclusion-box">
                        <div class="conclusion-header">Key Findings:</div>
                        <div class="conclusion-body">
                            ${conclusionText}
                        </div>
                    </div>

                    <!-- OFFICIAL FOOTER -->
                    <div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 10px; color: #94a3b8;">
                        <span>Leyton IT Support Analytics &mdash; Confidential Internal Report</span>
                        <span>Generated via Leyton Web Portal</span>
                    </div>

                </div>
            `;
        }
    </script>
</body>
</html>