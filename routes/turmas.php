<?php
/**
 * Rotas de Turmas - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';

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
    
    // Listar turmas
    case 'listar':
        $db = db();
        
        $sql = "SELECT t.*, p.nome as coordenador_nome,
                (SELECT COUNT(*) FROM alunos WHERE turma_id = t.id AND ativo = 1) as total_alunos
                FROM turmas t
                LEFT JOIN professores p ON t.coordenador_id = p.id
                WHERE t.ativo = 1";
        $params = [];
        
        if (!empty($_GET['ano_letivo'])) {
            $sql .= " AND t.ano_letivo = :ano";
            $params[':ano'] = $_GET['ano_letivo'];
        }
        
        $sql .= " ORDER BY t.nome";
        
        $turmas = $db->fetchAll($sql, $params);
        
        jsonSuccess(['turmas' => $turmas]);
        break;
    
    // Buscar turma por ID
    case 'buscar':
        $id = $_GET['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        $db = db();
        
        $turma = $db->fetchOne(
            "SELECT t.*, p.nome as coordenador_nome
             FROM turmas t
             LEFT JOIN professores p ON t.coordenador_id = p.id
             WHERE t.id = :id",
            [':id' => $id]
        );
        
        if (!$turma) {
            jsonError('Turma não encontrada', 404);
        }
        
        // Buscar alunos da turma
        $alunos = $db->fetchAll(
            "SELECT id, nome, codigo_acesso, ativo FROM alunos WHERE turma_id = :id AND ativo = 1 ORDER BY nome",
            [':id' => $id]
        );
        
        $turma['alunos'] = $alunos;
        
        jsonSuccess($turma);
        break;
    
    // Criar turma
    case 'criar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $dados = [
            'nome' => $input['nome'] ?? '',
            'ano_letivo' => $input['ano_letivo'] ?? date('Y'),
            'serie' => $input['serie'] ?? null,
            'turno' => $input['turno'] ?? null,
            'sala' => $input['sala'] ?? null,
            'coordenador_id' => $input['coordenador_id'] ?? null,
            'capacidade' => $input['capacidade'] ?? 40
        ];
        
        if (empty($dados['nome'])) {
            jsonError('Nome é obrigatório');
        }
        
        $db = db();
        $id = $db->insert('turmas', $dados);
        
        jsonSuccess(['id' => $id], 'Turma criada com sucesso');
        break;
    
    // Atualizar turma
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
        
        $dados = [
            'nome' => $input['nome'] ?? null,
            'ano_letivo' => $input['ano_letivo'] ?? null,
            'serie' => $input['serie'] ?? null,
            'turno' => $input['turno'] ?? null,
            'sala' => $input['sala'] ?? null,
            'coordenador_id' => $input['coordenador_id'] ?? null,
            'capacidade' => $input['capacidade'] ?? null
        ];
        
        $dados = array_filter($dados, function($v) { return $v !== null; });
        
        $db = db();
        $db->update('turmas', $dados, 'id = :id', [':id' => $id]);
        
        jsonSuccess(null, 'Turma atualizada com sucesso');
        break;
    
    // Disciplinas da turma
    case 'disciplinas':
        $id = $_GET['id'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        $db = db();
        
        $disciplinas = $db->fetchAll(
            "SELECT d.*, p.nome as professor_nome, tdp.professor_id
             FROM turma_disciplina_professor tdp
             JOIN disciplinas d ON tdp.disciplina_id = d.id
             LEFT JOIN professores p ON tdp.professor_id = p.id
             WHERE tdp.turma_id = :id AND tdp.ano_letivo = :ano",
            [':id' => $id, ':ano' => $_GET['ano'] ?? date('Y')]
        );
        
        jsonSuccess(['disciplinas' => $disciplinas]);
        break;
    
    // Adicionar disciplina à turma
    case 'adicionar-disciplina':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        if (!in_array($usuario['tipo'], ['admin', 'master'])) {
            jsonError('Acesso negado', 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $turmaId = $input['turma_id'] ?? '';
        $disciplinaId = $input['disciplina_id'] ?? '';
        $professorId = $input['professor_id'] ?? '';
        $ano = $input['ano_letivo'] ?? date('Y');
        
        if (empty($turmaId) || empty($disciplinaId)) {
            jsonError('Turma e disciplina são obrigatórios');
        }
        
        $db = db();
        
        // Verificar se já existe
        $existe = $db->fetchOne(
            "SELECT * FROM turma_disciplina_professor 
             WHERE turma_id = :turma AND disciplina_id = :disc AND ano_letivo = :ano",
            [':turma' => $turmaId, ':disc' => $disciplinaId, ':ano' => $ano]
        );
        
        if ($existe) {
            jsonError('Disciplina já está atribuída a esta turma');
        }
        
        $db->insert('turma_disciplina_professor', [
            'turma_id' => $turmaId,
            'disciplina_id' => $disciplinaId,
            'professor_id' => $professorId,
            'ano_letivo' => $ano
        ]);
        
        jsonSuccess(null, 'Disciplina adicionada com sucesso');
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
