<?php
require_once 'config.php';

class Database {
    private $connection;
    private static $instance = null;
    
    private function __construct() {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($this->connection->connect_error) {
                throw new Exception('Database connection failed: ' . $this->connection->connect_error);
            }
            
            // Set charset to UTF-8
            $this->connection->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function prepare($query) {
        return $this->connection->prepare($query);
    }
    
    public function query($query) {
        return $this->connection->query($query);
    }
    
    public function escape_string($string) {
        return $this->connection->real_escape_string($string);
    }
    
    public function insert_id() {
        return $this->connection->insert_id;
    }
    
    public function affected_rows() {
        return $this->connection->affected_rows;
    }
    
    public function begin_transaction() {
        return $this->connection->begin_transaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function error() {
        return $this->connection->error;
    }
    
    // Clean up expired stories (should be called periodically)
    public function cleanupExpiredStories() {
        $query = "DELETE FROM stories WHERE expires_at < NOW()";
        return $this->connection->query($query);
    }
    
    // Clean up expired vanish messages
    public function cleanupExpiredVanishMessages() {
        $query = "DELETE FROM messages WHERE message_type = 'vanish' AND expires_at < NOW()";
        return $this->connection->query($query);
    }
    
    // Clean up expired sessions
    public function cleanupExpiredSessions() {
        $query = "DELETE FROM user_sessions WHERE expires_at < NOW()";
        return $this->connection->query($query);
    }
    
    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

// Get database connection instance
function getDB() {
    return Database::getInstance();
}

// Get MySQL connection
function getConnection() {
    return Database::getInstance()->getConnection();
}
?>