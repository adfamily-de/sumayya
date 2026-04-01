<?php
/**
 * Rotas de Autenticação - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Professor.php';

// Obter ação da URL
$acao = $_GET['acao'] ?? '';

switch ($acao) {
    
    // Login do aluno (código + PIN/data nascimento)
    case 'login-aluno':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $codigo = $input['codigo'] ?? '';
        $pin = $input['pin'] ?? '';
        
        if (empty($codigo) || empty($pin)) {
            jsonError('Código e PIN são obrigatórios');
        }
        
        $resultado = AuthHelper::autenticarAluno($codigo, $pin);
        
        if (!$resultado) {
            jsonError('Código ou PIN incorretos', 401);
        }
        
        jsonSuccess($resultado, 'Login realizado com sucesso');
        break;
    
    // Login do professor/admin (login + senha)
    case 'login-professor':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $login = $input['login'] ?? '';
        $senha = $input['senha'] ?? '';
        
        if (empty($login) || empty($senha)) {
            jsonError('Login e senha são obrigatórios');
        }
        
        $resultado = AuthHelper::autenticarProfessor($login, $senha);
        
        if (!$resultado) {
            jsonError('Login ou senha incorretos', 401);
        }
        
        if (isset($resultado['erro']) && $resultado['erro'] === 'conta_suspensa') {
            jsonError('Conta suspensa até ' . date('d/m/Y', strtotime($resultado['ate'])), 403);
        }
        
        jsonSuccess($resultado, 'Login realizado com sucesso');
        break;
    
    // Verificar sessão
    case 'verificar-sessao':
        $token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token)) {
            jsonError('Token não fornecido', 401);
        }
        
        $sessao = AuthHelper::verificarSessao($token);
        
        if (!$sessao) {
            jsonError('Sessão inválida ou expirada', 401);
        }
        
        jsonSuccess($sessao);
        break;
    
    // Logout
    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';
        
        AuthHelper::logout($token);
        
        jsonSuccess(null, 'Logout realizado com sucesso');
        break;
    
    // Renovar sessão
    case 'renovar-sessao':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';
        
        if (empty($token)) {
            jsonError('Token não fornecido', 401);
        }
        
        $sessao = AuthHelper::verificarSessao($token);
        
        if (!$sessao) {
            jsonError('Sessão inválida', 401);
        }
        
        // Criar nova sessão
        $novoToken = AuthHelper::createSession($sessao['usuario']['tipo'], $sessao['usuario']['id']);
        
        // Remover sessão antiga
        AuthHelper::logout($token);
        
        jsonSuccess(['token' => $novoToken], 'Sessão renovada');
        break;
    
    // Obter dados do usuário logado
    case 'me':
        $token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token)) {
            jsonError('Token não fornecido', 401);
        }
        
        $sessao = AuthHelper::verificarSessao($token);
        
        if (!$sessao) {
            jsonError('Sessão inválida', 401);
        }
        
        $usuario = $sessao['usuario'];
        
        // Buscar dados completos
        if ($usuario['tipo'] === 'aluno') {
            $dados = Aluno::buscarPorId($usuario['id']);
        } else {
            $dados = Professor::buscarPorId($usuario['id']);
        }
        
        jsonSuccess(['usuario' => $dados, 'tipo' => $usuario['tipo']]);
        break;
    
    // Alterar senha
    case 'alterar-senha':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';
        $senhaAtual = $input['senha_atual'] ?? '';
        $novaSenha = $input['nova_senha'] ?? '';
        
        if (empty($token) || empty($senhaAtual) || empty($novaSenha)) {
            jsonError('Todos os campos são obrigatórios');
        }
        
        $sessao = AuthHelper::verificarSessao($token);
        
        if (!$sessao) {
            jsonError('Sessão inválida', 401);
        }
        
        // Verificar senha atual
        $usuario = Professor::buscarPorId($sessao['usuario']['id']);
        
        if (!AuthHelper::verifyPassword($senhaAtual, $usuario['senha_hash'])) {
            jsonError('Senha atual incorreta', 401);
        }
        
        // Atualizar senha
        Professor::atualizar($usuario['id'], ['senha' => $novaSenha]);
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($sessao['usuario']['tipo'], $usuario['id'], $usuario['nome'], 'senha_alterada');
        
        jsonSuccess(null, 'Senha alterada com sucesso');
        break;
    
    // Recuperar código de acesso (aluno)
    case 'recuperar-codigo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $nome = $input['nome'] ?? '';
        $dataNascimento = $input['data_nascimento'] ?? '';
        $turmaId = $input['turma_id'] ?? '';
        
        if (empty($nome) || empty($dataNascimento)) {
            jsonError('Nome e data de nascimento são obrigatórios');
        }
        
        $db = db();
        
        $sql = "SELECT codigo_acesso FROM alunos 
                WHERE nome LIKE :nome AND data_nascimento = :data_nasc AND ativo = 1";
        $params = [
            ':nome' => '%' . $nome . '%',
            ':data_nasc' => $dataNascimento
        ];
        
        if ($turmaId) {
            $sql .= " AND turma_id = :turma_id";
            $params[':turma_id'] = $turmaId;
        }
        
        $aluno = $db->fetchOne($sql, $params);
        
        if (!$aluno) {
            jsonError('Aluno não encontrado');
        }
        
        // Em produção, enviar por email/SMS
        jsonSuccess(['codigo' => $aluno['codigo_acesso']], 'Código recuperado com sucesso');
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
