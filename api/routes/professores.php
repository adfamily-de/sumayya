<?php
/**
 * Rotas de Professores - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../models/Professor.php';

// Verificar autenticação
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
$acao = $_GET['acao'] ?? '';

switch ($acao) {
    
    // Listar professores
    case 'listar':
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $filtros = [
            'cargo' => $_GET['cargo'] ?? null,
            'ativo' => $_GET['ativo'] ?? 1,
            'busca' => $_GET['busca'] ?? null
        ];
        $filtros = array_filter($filtros);
        
        $professores = Professor::listar($filtros);
        
        jsonSuccess(['professores' => $professores]);
        break;
    
    // Buscar professor
    case 'buscar':
        $id = $_GET['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        // Professor só pode ver seus próprios dados
        if (in_array($usuario['tipo'], ['professor', 'coordenador']) && $usuario['id'] != $id) {
            jsonError('Acesso negado', 403);
        }
        
        $professor = Professor::buscarPorId($id);
        
        if (!$professor) {
            jsonError('Professor não encontrado', 404);
        }
        
        jsonSuccess($professor);
        break;
    
    // Criar professor
    case 'criar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $dados = [
            'login' => $input['login'] ?? '',
            'senha' => $input['senha'] ?? '',
            'nome' => $input['nome'] ?? '',
            'email' => $input['email'] ?? null,
            'telefone' => $input['telefone'] ?? null,
            'cargo' => $input['cargo'] ?? 'professor',
            'permissoes' => $input['permissoes'] ?? null,
            'departamento' => $input['departamento'] ?? null
        ];
        
        if (empty($dados['login']) || empty($dados['senha']) || empty($dados['nome'])) {
            jsonError('Login, senha e nome são obrigatórios');
        }
        
        $resultado = Professor::criar($dados);
        
        if (isset($resultado['erro'])) {
            jsonError($resultado['erro']);
        }
        
        jsonSuccess($resultado, 'Professor criado com sucesso');
        break;
    
    // Atualizar professor
    case 'atualizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        // Não permitir alterar o próprio usuário para inativo
        if ($id == $usuario['id'] && isset($input['ativo']) && $input['ativo'] == 0) {
            jsonError('Não pode desativar sua própria conta');
        }
        
        $dados = [
            'nome' => $input['nome'] ?? null,
            'email' => $input['email'] ?? null,
            'telefone' => $input['telefone'] ?? null,
            'cargo' => $input['cargo'] ?? null,
            'permissoes' => $input['permissoes'] ?? null,
            'departamento' => $input['departamento'] ?? null,
            'ativo' => isset($input['ativo']) ? $input['ativo'] : null
        ];
        
        if (!empty($input['senha'])) {
            $dados['senha'] = $input['senha'];
        }
        
        $dados = array_filter($dados, function($v) { return $v !== null; });
        
        Professor::atualizar($id, $dados);
        
        jsonSuccess(null, 'Professor atualizado com sucesso');
        break;
    
    // Dashboard do professor
    case 'dashboard':
        if (!in_array($usuario['tipo'], ['professor', 'coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        // Se for professor, mostrar próprio dashboard
        $id = $usuario['tipo'] === 'professor' ? $usuario['id'] : ($_GET['id'] ?? $usuario['id']);
        
        $dashboard = Professor::getDashboard($id);
        
        jsonSuccess($dashboard);
        break;
    
    // Turmas do professor
    case 'turmas':
        if (!in_array($usuario['tipo'], ['professor', 'coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $id = $usuario['tipo'] === 'professor' ? $usuario['id'] : ($_GET['id'] ?? $usuario['id']);
        $ano = $_GET['ano'] ?? null;
        
        $turmas = Professor::getTurmas($id, $ano);
        
        jsonSuccess($turmas);
        break;
    
    // Alunos para lançamento de notas
    case 'alunos-notas':
        if (!in_array($usuario['tipo'], ['professor', 'coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $turmaId = $_GET['turma_id'] ?? '';
        $disciplinaId = $_GET['disciplina_id'] ?? '';
        $bimestre = $_GET['bimestre'] ?? null;
        $ano = $_GET['ano'] ?? null;
        
        if (empty($turmaId) || empty($disciplinaId)) {
            jsonError('Turma e disciplina são obrigatórios');
        }
        
        $alunos = Professor::getAlunosParaNotas($usuario['id'], $turmaId, $disciplinaId, $bimestre, $ano);
        
        if (isset($alunos['erro'])) {
            jsonError($alunos['erro'], 403);
        }
        
        jsonSuccess(['alunos' => $alunos]);
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
