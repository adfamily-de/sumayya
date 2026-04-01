<?php
/**
 * Rotas de Notas - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../models/Nota.php';
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
    
    // Listar notas do aluno
    case 'minhas-notas':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        $ano = $_GET['ano'] ?? null;
        $notas = Nota::getPorAluno($usuario['id'], $ano);
        
        jsonSuccess(['notas' => $notas]);
        break;
    
    // Obter boletim
    case 'boletim':
        if ($usuario['tipo'] !== 'aluno') {
            jsonError('Acesso negado', 403);
        }
        
        // Verificar bloqueio por propina
        require_once __DIR__ . '/../helpers/FinanceiroHelper.php';
        $bloqueio = FinanceiroHelper::estaBloqueado($usuario['id']);
        
        if ($bloqueio['bloqueado']) {
            jsonError($bloqueio['mensagem'], 403, ['bloqueio' => $bloqueio]);
        }
        
        $ano = $_GET['ano'] ?? null;
        $boletim = Aluno::getBoletim($usuario['id'], $ano);
        
        jsonSuccess($boletim);
        break;
    
    // Listar notas por turma e disciplina (professor)
    case 'listar':
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
        
        // Professor só pode ver suas turmas
        if ($usuario['tipo'] === 'professor') {
            $db = db();
            $leciona = $db->fetchOne(
                "SELECT * FROM turma_disciplina_professor 
                 WHERE professor_id = :prof_id AND turma_id = :turma_id AND disciplina_id = :disc_id",
                [':prof_id' => $usuario['id'], ':turma_id' => $turmaId, ':disc_id' => $disciplinaId]
            );
            
            if (!$leciona) {
                jsonError('Você não leciona esta disciplina para esta turma', 403);
            }
        }
        
        $notas = Nota::getPorTurmaDisciplina($turmaId, $disciplinaId, $bimestre, $ano);
        
        jsonSuccess(['notas' => $notas]);
        break;
    
    // Lançar nota
    case 'lancar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['professor', 'coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $dados = [
            'aluno_id' => $input['aluno_id'] ?? '',
            'disciplina_id' => $input['disciplina_id'] ?? '',
            'bimestre' => $input['bimestre'] ?? '',
            'ano_letivo' => $input['ano_letivo'] ?? '',
            'nota' => $input['nota'] ?? '',
            'faltas' => $input['faltas'] ?? 0,
            'observacoes' => $input['observacoes'] ?? null,
            'professor_id' => $usuario['id']
        ];
        
        // Validações
        if (empty($dados['aluno_id']) || empty($dados['disciplina_id']) || 
            empty($dados['bimestre']) || empty($dados['ano_letivo']) || 
            !is_numeric($dados['nota'])) {
            jsonError('Todos os campos são obrigatórios');
        }
        
        if ($dados['nota'] < 0 || $dados['nota'] > 20) {
            jsonError('Nota deve estar entre 0 e 20');
        }
        
        // Professor só pode lançar para suas turmas
        if ($usuario['tipo'] === 'professor') {
            $db = db();
            $leciona = $db->fetchOne(
                "SELECT * FROM turma_disciplina_professor tdp
                 JOIN alunos a ON a.turma_id = tdp.turma_id
                 WHERE tdp.professor_id = :prof_id AND a.id = :aluno_id 
                 AND tdp.disciplina_id = :disc_id",
                [':prof_id' => $usuario['id'], ':aluno_id' => $dados['aluno_id'], ':disc_id' => $dados['disciplina_id']]
            );
            
            if (!$leciona) {
                jsonError('Você não pode lançar notas para este aluno', 403);
            }
        }
        
        $resultado = Nota::lancar($dados);
        
        if (isset($resultado['erro'])) {
            jsonError($resultado['erro']);
        }
        
        jsonSuccess($resultado, 'Nota lançada com sucesso');
        break;
    
    // Atualizar nota
    case 'atualizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['professor', 'coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $notaId = $input['nota_id'] ?? '';
        
        if (empty($notaId)) {
            jsonError('ID da nota é obrigatório');
        }
        
        $dados = [
            'nota' => $input['nota'] ?? '',
            'faltas' => $input['faltas'] ?? 0,
            'observacoes' => $input['observacoes'] ?? null,
            'editado_por' => $usuario['id'],
            'usuario_tipo' => $usuario['tipo'],
            'justificativa' => $input['justificativa'] ?? null
        ];
        
        if (!is_numeric($dados['nota']) || $dados['nota'] < 0 || $dados['nota'] > 20) {
            jsonError('Nota deve estar entre 0 e 20');
        }
        
        $resultado = Nota::atualizar($notaId, $dados);
        
        if (isset($resultado['erro'])) {
            jsonError($resultado['erro'], 403, ['solicitar_correcao' => $resultado['solicitar_correcao'] ?? false]);
        }
        
        jsonSuccess($resultado, 'Nota atualizada com sucesso');
        break;
    
    // Verificar permissão de edição
    case 'pode-editar':
        if (!in_array($usuario['tipo'], ['professor', 'coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $notaId = $_GET['nota_id'] ?? '';
        
        if (empty($notaId)) {
            jsonError('ID da nota é obrigatório');
        }
        
        $nota = Nota::buscarPorId($notaId);
        
        if (!$nota) {
            jsonError('Nota não encontrada', 404);
        }
        
        $podeEditar = AuthHelper::podeEditarNota($usuario, $nota);
        
        jsonSuccess($podeEditar);
        break;
    
    // Solicitar correção de nota
    case 'solicitar-correcao':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if ($usuario['tipo'] !== 'professor') {
            jsonError('Apenas professores podem solicitar correção', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $notaId = $input['nota_id'] ?? '';
        $motivo = $input['motivo'] ?? '';
        
        if (empty($notaId) || empty($motivo)) {
            jsonError('Nota e motivo são obrigatórios');
        }
        
        $resultado = Nota::solicitarCorrecao($notaId, $usuario['id'], $motivo);
        
        jsonSuccess($resultado, 'Solicitação enviada com sucesso');
        break;
    
    // Analisar solicitação de correção (admin/master)
    case 'analisar-solicitacao':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $solicitacaoId = $input['solicitacao_id'] ?? '';
        $status = $input['status'] ?? ''; // aprovado ou rejeitado
        $resposta = $input['resposta'] ?? null;
        
        if (empty($solicitacaoId) || empty($status)) {
            jsonError('Solicitação e status são obrigatórios');
        }
        
        if (!in_array($status, ['aprovado', 'rejeitado'])) {
            jsonError('Status deve ser aprovado ou rejeitado');
        }
        
        Nota::analisarSolicitacao($solicitacaoId, $status, $usuario['id'], $resposta);
        
        jsonSuccess(null, 'Solicitação analisada com sucesso');
        break;
    
    // Bloquear/desbloquear nota (admin/master)
    case 'bloquear':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $notaId = $input['nota_id'] ?? '';
        $bloquear = $input['bloquear'] ?? true;
        
        if (empty($notaId)) {
            jsonError('ID da nota é obrigatório');
        }
        
        Nota::bloquear($notaId, $bloquear);
        
        $msg = $bloquear ? 'Nota bloqueada' : 'Nota desbloqueada';
        jsonSuccess(null, $msg . ' com sucesso');
        break;
    
    // Estatísticas de notas
    case 'estatisticas':
        if (!in_array($usuario['tipo'], ['coordenador', 'admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $turmaId = $_GET['turma_id'] ?? null;
        $disciplinaId = $_GET['disciplina_id'] ?? null;
        $bimestre = $_GET['bimestre'] ?? null;
        $ano = $_GET['ano'] ?? null;
        
        $estatisticas = Nota::getEstatisticas($turmaId, $disciplinaId, $bimestre, $ano);
        
        jsonSuccess($estatisticas);
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
