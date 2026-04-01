<?php
/**
 * Rotas de Alunos - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../models/Aluno.php';

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
    
    // Listar alunos
    case 'listar':
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $filtros = [
            'turma_id' => $_GET['turma_id'] ?? null,
            'ativo' => $_GET['ativo'] ?? 1,
            'busca' => $_GET['busca'] ?? null
        ];
        $filtros = array_filter($filtros);
        
        $alunos = Aluno::listar($filtros);
        
        jsonSuccess(['alunos' => $alunos]);
        break;
    
    // Buscar aluno por ID
    case 'buscar':
        $id = $_GET['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        // Aluno só pode ver seus próprios dados
        if ($usuario['tipo'] === 'aluno' && $usuario['id'] != $id) {
            jsonError('Acesso negado', 403);
        }
        
        $aluno = Aluno::buscarPorId($id);
        
        if (!$aluno) {
            jsonError('Aluno não encontrado', 404);
        }
        
        jsonSuccess($aluno);
        break;
    
    // Criar aluno
    case 'criar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $dados = [
            'nome' => $input['nome'] ?? '',
            'data_nascimento' => $input['data_nascimento'] ?? null,
            'turma_id' => $input['turma_id'] ?? null,
            'email' => $input['email'] ?? null,
            'telefone' => $input['telefone'] ?? null,
            'endereco' => $input['endereco'] ?? null,
            'responsaveis' => $input['responsaveis'] ?? null,
            'pin' => $input['pin'] ?? null
        ];
        
        if (empty($dados['nome'])) {
            jsonError('Nome é obrigatório');
        }
        
        $resultado = Aluno::criar($dados);
        
        // Gerar propinas do ano
        if ($dados['turma_id']) {
            require_once __DIR__ . '/../helpers/FinanceiroHelper.php';
            $config = db()->getConfig();
            $valor = $input['valor_propina'] ?? 5000;
            FinanceiroHelper::gerarPropinasAno($resultado['id'], $config['ano_letivo_atual'], $valor);
        }
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($usuario['tipo'], $usuario['id'], $usuario['nome'], 'aluno_criado', 'aluno', $resultado['id']);
        
        jsonSuccess($resultado, 'Aluno criado com sucesso');
        break;
    
    // Atualizar aluno
    case 'atualizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        $dados = [
            'nome' => $input['nome'] ?? null,
            'data_nascimento' => $input['data_nascimento'] ?? null,
            'turma_id' => $input['turma_id'] ?? null,
            'email' => $input['email'] ?? null,
            'telefone' => $input['telefone'] ?? null,
            'endereco' => $input['endereco'] ?? null,
            'responsaveis' => $input['responsaveis'] ?? null,
            'ativo' => isset($input['ativo']) ? $input['ativo'] : null
        ];
        
        $dados = array_filter($dados, function($v) { return $v !== null; });
        
        Aluno::atualizar($id, $dados);
        
        jsonSuccess(null, 'Aluno atualizado com sucesso');
        break;
    
    // Dashboard do aluno
    case 'dashboard':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $dashboard = Aluno::getDashboard($usuario['id']);
        
        jsonSuccess($dashboard);
        break;
    
    // Boletim do aluno
    case 'boletim':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $ano = $_GET['ano'] ?? null;
        $boletim = Aluno::getBoletim($usuario['id'], $ano);
        
        jsonSuccess($boletim);
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
