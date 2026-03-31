<?php
/**
 * Database Handler - Instituto Politécnico Sumayya
 * Gerenciamento de conexão e operações com SQLite
 */

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        try {
            // Criar diretório do banco se não existir
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            
            // Conectar ao banco
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
            // Habilitar foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            
            // Criar tabelas se não existirem
            $this->initDatabase();
            
        } catch (PDOException $e) {
            error_log('Erro de conexão com banco: ' . $e->getMessage());
            throw new Exception('Erro ao conectar ao banco de dados');
        }
    }
    
    /**
     * Obter instância única (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obter conexão PDO
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Inicializar banco de dados com schema
     */
    private function initDatabase() {
        if (!file_exists(DB_SCHEMA)) {
            return;
        }
        
        // Verificar se já temos tabelas
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tables)) {
            // Executar schema
            $schema = file_get_contents(DB_SCHEMA);
            $this->pdo->exec($schema);
        }
    }
    
    /**
     * Executar query com prepared statement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('Erro na query: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new Exception('Erro ao executar operação no banco de dados');
        }
    }
    
    /**
     * Buscar todos os registros
     */
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Buscar um registro
     */
    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    /**
     * Inserir registro
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Atualizar registro
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $set);
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $params = array_merge($data, $whereParams);
        
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Deletar registro
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Iniciar transação
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Verificar se está em transação
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Obter configuração do sistema
     */
    public function getConfig($key = null) {
        if ($key) {
            $result = $this->fetchOne("SELECT {$key} FROM config_sistema WHERE id = 1");
            return $result ? $result[$key] : null;
        }
        return $this->fetchOne("SELECT * FROM config_sistema WHERE id = 1");
    }
    
    /**
     * Atualizar configuração do sistema
     */
    public function updateConfig($data, $userId = null) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        if ($userId) {
            $data['updated_by'] = $userId;
        }
        return $this->update('config_sistema', $data, 'id = 1');
    }
}

// Função helper para acessar o banco
function db() {
    return Database::getInstance();
}
