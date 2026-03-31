<?php
/**
 * Rotas de Propinas - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../helpers/FinanceiroHelper.php';
require_once __DIR__ . '/../models/Propina.php';

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
    
    // Listar propinas do aluno
    case 'minhas-propinas':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $ano = $_GET['ano'] ?? null;
        $propinas = Propina::listarPorAluno($usuario['id'], $ano);
        
        // Obter resumo
        $resumo = Propina::getResumoAluno($usuario['id'], $ano);
        
        jsonSuccess(['propinas' => $propinas, 'resumo' => $resumo]);
        break;
    
    // Verificar status de bloqueio
    case 'verificar-bloqueio':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $bloqueio = FinanceiroHelper::estaBloqueado($usuario['id']);
        
        jsonSuccess($bloqueio);
        break;
    
    // Obter resumo financeiro
    case 'resumo':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $ano = $_GET['ano'] ?? null;
        $resumo = Propina::getResumoAluno($usuario['id'], $ano);
        
        jsonSuccess($resumo);
        break;
    
    // Listar todas as propinas (secretaria/admin)
    case 'listar':
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $filtros = [
            'aluno_id' => $_GET['aluno_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'ano' => $_GET['ano'] ?? null,
            'mes' => $_GET['mes'] ?? null,
            'vencidas' => isset($_GET['vencidas']) ? true : null
        ];
        
        // Remover filtros vazios
        $filtros = array_filter($filtros);
        
        $propinas = Propina::listar($filtros);
        
        jsonSuccess(['propinas' => $propinas]);
        break;
    
    // Buscar propina por ID
    case 'buscar':
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master', 'aluno'])) {
            jsonError('Acesso negado', 403);
        }
        
        $id = $_GET['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        $propina = Propina::buscarPorId($id);
        
        if (!$propina) {
            jsonError('Propina não encontrada', 404);
        }
        
        // Aluno só pode ver suas próprias propinas
        if ($usuario['tipo'] === 'aluno' && $propina['aluno_id'] != $usuario['id']) {
            jsonError('Acesso negado', 403);
        }
        
        jsonSuccess($propina);
        break;
    
    // Criar propina
    case 'criar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $dados = [
            'aluno_id' => $input['aluno_id'] ?? '',
            'mes_ref' => $input['mes_ref'] ?? '',
            'ano_ref' => $input['ano_ref'] ?? '',
            'valor' => $input['valor'] ?? '',
            'data_vencimento' => $input['data_vencimento'] ?? ''
        ];
        
        if (empty($dados['aluno_id']) || empty($dados['mes_ref']) || 
            empty($dados['ano_ref']) || empty($dados['valor'])) {
            jsonError('Todos os campos são obrigatórios');
        }
        
        $resultado = Propina::criar($dados);
        
        if (isset($resultado['erro'])) {
            jsonError($resultado['erro']);
        }
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($usuario['tipo'], $usuario['id'], $usuario['nome'], 'propina_criada', 'propina', $resultado['id']);
        
        jsonSuccess($resultado, 'Propina criada com sucesso');
        break;
    
    // Registrar pagamento
    case 'registrar-pagamento':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
            'data_pagamento' => $input['data_pagamento'] ?? date('Y-m-d'),
            'metodo_pagamento' => $input['metodo_pagamento'] ?? 'dinheiro',
            'registrado_por' => $usuario['id'],
            'comprovante_url' => $input['comprovante_url'] ?? null,
            'observacoes' => $input['observacoes'] ?? null
        ];
        
        $resultado = Propina::registrarPagamento($id, $dados);
        
        if (isset($resultado['erro'])) {
            jsonError($resultado['erro']);
        }
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($usuario['tipo'], $usuario['id'], $usuario['nome'], 'pagamento_registrado', 'propina', $id);
        
        jsonSuccess($resultado, 'Pagamento registrado com sucesso');
        break;
    
    // Atualizar status
    case 'atualizar-status':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $status = $input['status'] ?? '';
        $observacoes = $input['observacoes'] ?? null;
        
        if (empty($id) || empty($status)) {
            jsonError('ID e status são obrigatórios');
        }
        
        if (!in_array($status, ['pago', 'pendente', 'atrasado', 'isento', 'negociacao'])) {
            jsonError('Status inválido');
        }
        
        Propina::atualizarStatus($id, $status, $observacoes);
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($usuario['tipo'], $usuario['id'], $usuario['nome'], 'propina_status_alterado', 'propina', $id, null, ['novo_status' => $status]);
        
        jsonSuccess(null, 'Status atualizado com sucesso');
        break;
    
    // Negociar propina
    case 'negociar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
            'valor_negociado' => $input['valor_negociado'] ?? '',
            'parcelas' => $input['parcelas'] ?? 1,
            'data_inicio' => $input['data_inicio'] ?? date('Y-m-d'),
            'observacoes' => $input['observacoes'] ?? null
        ];
        
        Propina::negociar($id, $dados);
        
        jsonSuccess(null, 'Negociação registrada com sucesso');
        break;
    
    // Criar liberação temporária
    case 'liberar-temporariamente':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $alunoId = $input['aluno_id'] ?? '';
        $dias = $input['dias'] ?? 7;
        $motivo = $input['motivo'] ?? '';
        
        if (empty($alunoId) || empty($motivo)) {
            jsonError('Aluno e motivo são obrigatórios');
        }
        
        $resultado = FinanceiroHelper::criarLiberacao($alunoId, $dias, $motivo, $usuario['id']);
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($usuario['tipo'], $usuario['id'], $usuario['nome'], 'liberacao_temporaria', 'aluno', $alunoId, null, ['dias' => $dias, 'motivo' => $motivo]);
        
        jsonSuccess($resultado, 'Liberação criada com sucesso');
        break;
    
    // Gerar Pix (simulado)
    case 'gerar-pix':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $propinaId = $_GET['propina_id'] ?? '';
        
        if (empty($propinaId)) {
            jsonError('ID da propina é obrigatório');
        }
        
        // Verificar se a propina pertence ao aluno
        $propina = Propina::buscarPorId($propinaId);
        if (!$propina || $propina['aluno_id'] != $usuario['id']) {
            jsonError('Propina não encontrada', 404);
        }
        
        $pix = FinanceiroHelper::gerarPix($propinaId);
        
        jsonSuccess($pix);
        break;
    
    // Estatísticas financeiras
    case 'estatisticas':
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $ano = $_GET['ano'] ?? null;
        $mes = $_GET['mes'] ?? null;
        
        $estatisticas = Propina::getEstatisticas($ano, $mes);
        
        jsonSuccess($estatisticas);
        break;
    
    // Atualizar propinas vencidas
    case 'atualizar-vencidas':
        if (!in_array($usuario['tipo'], ['secretaria', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $atualizadas = FinanceiroHelper::atualizarPropinasVencidas();
        
        jsonSuccess(['atualizadas' => $atualizadas], 'Propinas atualizadas');
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
