<?php
$host = 'localhost';
$username = 'root';
$password = ''; // Par défaut sur XAMPP le mot de passe est vide
$dbname = 'tickets_analysis';

// 1. Connexion au serveur MySQL (sans sélectionner de base de données d'abord)
$conn = new mysqli($host, $username, $password);

// Vérification de la connexion
if ($conn->connect_error) {
    die("Échec de la connexion au serveur MySQL : " . $conn->connect_error);
}

// 2. Création de la base de données si elle n'existe pas
$sql = "CREATE DATABASE IF NOT EXISTS `$dbname`";
if ($conn->query($sql) !== TRUE) {
    die("Erreur lors de la création de la base de données : " . $conn->error);
}

// 3. Sélection de la base de données pour pouvoir l'utiliser
if (!$conn->select_db($dbname)) {
    die("Erreur lors de la sélection de la base de données : " . $conn->error);
}

// Optionnel : décommenter pour tester si la connexion marche
// echo "Connexion réussie !";
?>
