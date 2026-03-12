<?php
// Database configuration for HostPinnacle/cPanel

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');      // From cPanel MySQL Database
define('DB_USER', 'your_database_user');      // From cPanel MySQL User
define('DB_PASS', 'your_database_password'); // From cPanel MySQL Password
define('DB_CHARSET', 'utf8mb4');

class Database {
    private static $instance = null;
    private $pdo;
    private $error;
    
    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            throw new Exception("Database connection failed: " . $this->error);
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

// Helper function
function getDB() {
    return Database::getInstance()->getConnection();
}
?>
