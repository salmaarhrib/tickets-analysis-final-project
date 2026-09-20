<?php
// Fallback redirect to generatereport.php preserving query parameters
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: generatereport.php" . $query);
exit();
