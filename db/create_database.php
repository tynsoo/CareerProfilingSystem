<?php
try {
    $pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', 'postgres', 'postgres_dev_pw');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'profilepath'")->fetchColumn();
    if ($exists) {
        echo "Database 'profilepath' already exists.\n";
    } else {
        $pdo->exec("CREATE DATABASE profilepath");
        echo "Database 'profilepath' created.\n";
    }
} catch (PDOException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
