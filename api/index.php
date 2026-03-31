<?php
/**
 * API Index - Instituto Politécnico Sumayya
 * Ponto de entrada para todas as requisições da API
 */

require_once __DIR__ . '/config.php';

// Obter a rota da URL
$rota = $_GET['rota'] ?? '';

// Limpar sessões expiradas periodicamente
if (rand(1, 100) === 1) {
    require_once __DIR__ . '/helpers/AuthHelper.php';
    AuthHelper::limparSessoesExpiradas();
}

// Roteamento
switch ($rota) {
    case 'auth':
        require_once __DIR__ . '/routes/auth.php';
        break;
    
    case 'notas':
        require_once __DIR__ . '/routes/notas.php';
        break;
    
    case 'propinas':
        require_once __DIR__ . '/routes/propinas.php';
        break;
    
    case 'admin':
        require_once __DIR__ . '/routes/admin.php';
        break;
    
    case 'alunos':
        require_once __DIR__ . '/routes/alunos.php';
        break;
    
    case 'professores':
        require_once __DIR__ . '/routes/professores.php';
        break;
    
    case 'turmas':
        require_once __DIR__ . '/routes/turmas.php';
        break;
    
    // Status da API
    case 'status':
    case '':
        jsonSuccess([
            'nome' => APP_NAME,
            'versao' => APP_VERSION,
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ]);
        break;
    
    default:
        jsonError('Rota não encontrada: ' . $rota, 404);
}
