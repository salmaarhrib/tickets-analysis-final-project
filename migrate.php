<?php
require 'db.php';

echo "Starting database migration...\n";

// 1. Alter users table role enum
$sqlAlterRole = "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('Technician', 'Dispatcher', 'Admin', 'Manager', 'Team Leader', 'Standard Employee') NOT NULL DEFAULT 'Standard Employee'";
if ($conn->query($sqlAlterRole)) {
    echo "Successfully updated 'role' column enum.\n";
} else {
    echo "Error updating 'role' column: " . $conn->error . "\n";
}

// 2. Add password column
// Check if password column exists first to make it idempotent
$checkCol = $conn->query("SHOW COLUMNS FROM `users` LIKE 'password'");
if ($checkCol->num_rows == 0) {
    $sqlAddPass = "ALTER TABLE `users` ADD COLUMN `password` VARCHAR(255) NULL AFTER `email`";
    if ($conn->query($sqlAddPass)) {
        echo "Successfully added 'password' column.\n";
    } else {
        echo "Error adding 'password' column: " . $conn->error . "\n";
    }
} else {
    echo "'password' column already exists.\n";
}

// 3. Set default password for existing users
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$sqlUpdatePass = "UPDATE `users` SET `password` = '$defaultHash' WHERE `password` IS NULL OR `password` = ''";
if ($conn->query($sqlUpdatePass)) {
    echo "Successfully set default password for " . $conn->affected_rows . " existing users.\n";
} else {
    echo "Error setting default passwords: " . $conn->error . "\n";
}

echo "Migration complete.\n";
?>
