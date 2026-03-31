<?php
/**
 * Configuração do Sistema - Instituto Politécnico Sumayya
 * Arquivo de configurações globais
 */

// Prevenir acesso direto
if (!defined('SUMAYYA_ROOT')) {
    define('SUMAYYA_ROOT', dirname(__DIR__));
}

// Configurações do banco de dados
define('DB_PATH', SUMAYYA_ROOT . '/database/sumayya.db');
define('DB_SCHEMA', SUMAYYA_ROOT . '/database/schema.sql');

// Configurações da aplicação
define('APP_NAME', 'Instituto Politécnico Sumayya');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://sumayya.edu.mz');

// Configurações de contato
define('INSTITUICAO_NOME', 'Instituto Politécnico Sumayya');
define('INSTITUICAO_ENDERECO', 'Avenida 25 de Setembro, Matola, Maputo, 1114');
define('INSTITUICAO_WHATSAPP', '258874163000');
define('INSTITUICAO_TELEFONE', '87 416 3000');
define('INSTITUICAO_EMAIL', 'secretaria@sumayya.edu.mz');

// Configurações de sessão
define('SESSION_LIFETIME', 900); // 15 minutos em segundos
define('SESSION_REFRESH', 300);  // Renovar a cada 5 minutos

// Configurações de regras de negócio
define('HORAS_EDICAO_LIVRE', 24);
define('HORAS_EDICAO_JUSTIFICADA', 48);
define('DIAS_BLOQUEIO_PROPINA', 30);
define('TOLERANCIA_INICIO_ANO', 45);

// Configurações de upload
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('UPLOAD_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'application/pdf']);
define('UPLOAD_PATH', SUMAYYA_ROOT . '/public/uploads/');

// Configurações de backup
define('BACKUP_PATH', SUMAYYA_ROOT . '/backups/');
define('BACKUP_AUTO', true);
define('BACKUP_HORA', '02:00');

// Configurações de timezone
date_default_timezone_set('Africa/Maputo');

// Configurações de erro (desativar em produção)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Headers CORS para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Tratar requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Função utilitária para resposta JSON
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Função para erro
function jsonError($message, $statusCode = 400, $details = null) {
    $response = ['success' => false, 'error' => $message];
    if ($details) {
        $response['details'] = $details;
    }
    jsonResponse($response, $statusCode);
}

// Função para sucesso
function jsonSuccess($data = null, $message = 'Operação realizada com sucesso') {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    jsonResponse($response);
}
