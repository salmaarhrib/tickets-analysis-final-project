<?php
require 'db.php';

$tables = ['users', 'tickets', 'teams', 'categories'];
foreach ($tables as $table) {
    echo "--- Table: $table ---\n";
    $res = $conn->query("SHOW CREATE TABLE `$table`");
    if ($res) {
        $row = $res->fetch_assoc();
        echo $row['Create Table'] . "\n\n";
    } else {
        echo "Error: " . $conn->error . "\n\n";
    }
}
?>
