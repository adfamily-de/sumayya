<?php
/**
 * Rotas Administrativas - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../helpers/PdfHelper.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Professor.php';
require_once __DIR__ . '/../models/Auditoria.php';

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

// Verificar permissões de admin
if (!in_array($usuario['tipo'], ['admin', 'master'])) {
    jsonError('Acesso negado - Apenas administradores', 403);
}

switch ($acao) {
    
    // ===== DASHBOARD MASTER =====
    case 'dashboard':
        $db = db();
        
        // Estatísticas gerais
        $totalAlunos = $db->fetchOne("SELECT COUNT(*) as total FROM alunos WHERE ativo = 1");
        $totalProfessores = $db->fetchOne("SELECT COUNT(*) as total FROM professores WHERE ativo = 1");
        $totalTurmas = $db->fetchOne("SELECT COUNT(*) as total FROM turmas WHERE ativo = 1");
        
        // Propinas do mês
        $mesAtual = date('n');
        $anoAtual = date('Y');
        $propinasMes = $db->fetchOne(
            "SELECT COUNT(*) as total, COALESCE(SUM(valor), 0) as valor FROM propinas WHERE mes_ref = :mes AND ano_ref = :ano AND status = 'pago'",
            [':mes' => $mesAtual, ':ano' => $anoAtual]
        );
        
        // Propinas pendentes/atrasadas
        $propinasPendentes = $db->fetchOne(
            "SELECT COUNT(*) as total, COALESCE(SUM(valor), 0) as valor FROM propinas WHERE status IN ('pendente', 'atrasado')"
        );
        
        // Notas lançadas no bimestre atual
        $config = $db->getConfig();
        $notasLancadas = $db->fetchOne(
            "SELECT COUNT(*) as total FROM notas WHERE bimestre = :bimestre AND ano_letivo = :ano",
            [':bimestre' => $config['bimestre_atual'], ':ano' => $config['ano_letivo_atual']]
        );
        
        // Auditoria - últimos acessos
        $auditoria = AuditoriaModel::getEstatisticas(7);
        
        // Alertas
        $alertas = [];
        
        // Propinas atrasadas
        if ($propinasPendentes['total'] > 0) {
            $alertas[] = [
                'tipo' => 'propinas_atrasadas',
                'gravidade' => 'alta',
                'mensagem' => $propinasPendentes['total'] . ' propinas pendentes/atrasadas',
                'valor' => $propinasPendentes['valor']
            ];
        }
        
        // Falhas de login
        if ($auditoria['falhas_login'] > 0) {
            $alertas[] = [
                'tipo' => 'falhas_login',
                'gravidade' => 'media',
                'mensagem' => $auditoria['falhas_login'] . ' tentativas de login falhas na última semana'
            ];
        }
        
        // Edições pós-48h
        if (count($auditoria['edicoes_pos_48h']) > 0) {
            $alertas[] = [
                'tipo' => 'edicoes_pos_48h',
                'gravidade' => 'media',
                'mensagem' => count($auditoria['edicoes_pos_48h']) . ' notas editadas após 48h'
            ];
        }
        
        jsonSuccess([
            'estatisticas' => [
                'alunos' => $totalAlunos['total'],
                'professores' => $totalProfessores['total'],
                'turmas' => $totalTurmas['total'],
                'propinas_recebidas' => $propinasMes,
                'propinas_pendentes' => $propinasPendentes,
                'notas_lancadas' => $notasLancadas['total']
            ],
            'config' => $config,
            'auditoria' => $auditoria,
            'alertas' => $alertas
        ]);
        break;
    
    // ===== GESTÃO DE ALUNOS =====
    case 'listar-alunos':
        $filtros = [
            'turma_id' => $_GET['turma_id'] ?? null,
            'ativo' => $_GET['ativo'] ?? 1,
            'busca' => $_GET['busca'] ?? null
        ];
        $filtros = array_filter($filtros);
        
        $alunos = Aluno::listar($filtros);
        
        jsonSuccess(['alunos' => $alunos]);
        break;
    
    case 'criar-aluno':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
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
        
        // Gerar propinas do ano se turma informada
        if ($dados['turma_id']) {
            require_once __DIR__ . '/../helpers/FinanceiroHelper.php';
            $config = db()->getConfig();
            FinanceiroHelper::gerarPropinasAno($resultado['id'], $config['ano_letivo_atual'], 5000); // Valor padrão
        }
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria($usuario['tipo'], $usuario['id'], $usuario['nome'], 'aluno_criado', 'aluno', $resultado['id']);
        
        jsonSuccess($resultado, 'Aluno criado com sucesso');
        break;
    
    case 'atualizar-aluno':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
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
    
    case 'alterar-codigo-aluno':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $novoCodigo = $input['novo_codigo'] ?? '';
        $motivo = $input['motivo'] ?? '';
        
        if (empty($id) || empty($novoCodigo)) {
            jsonError('ID e novo código são obrigatórios');
        }
        
        Aluno::alterarCodigo($id, $novoCodigo, $usuario['id'], $motivo);
        
        jsonSuccess(null, 'Código alterado com sucesso');
        break;
    
    // ===== GESTÃO DE PROFESSORES =====
    case 'listar-professores':
        $filtros = [
            'cargo' => $_GET['cargo'] ?? null,
            'ativo' => $_GET['ativo'] ?? 1,
            'busca' => $_GET['busca'] ?? null
        ];
        $filtros = array_filter($filtros);
        
        $professores = Professor::listar($filtros);
        
        jsonSuccess(['professores' => $professores]);
        break;
    
    case 'criar-professor':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
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
    
    case 'atualizar-professor':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
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
    
    case 'suspender-professor':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $dias = $input['dias'] ?? 7;
        $motivo = $input['motivo'] ?? '';
        
        if (empty($id)) {
            jsonError('ID é obrigatório');
        }
        
        // Não permitir suspender a si mesmo
        if ($id == $usuario['id']) {
            jsonError('Não pode suspender sua própria conta');
        }
        
        Professor::suspender($id, $dias, $motivo);
        
        jsonSuccess(null, 'Professor suspenso por ' . $dias . ' dias');
        break;
    
    // ===== AUDITORIA =====
    case 'auditoria':
        // Apenas master pode ver auditoria completa
        if ($usuario['tipo'] !== 'master') {
            jsonError('Acesso negado - Apenas MASTER', 403);
        }
        
        $filtros = [
            'usuario_tipo' => $_GET['usuario_tipo'] ?? null,
            'usuario_id' => $_GET['usuario_id'] ?? null,
            'acao' => $_GET['acao'] ?? null,
            'alvo_tipo' => $_GET['alvo_tipo'] ?? null,
            'data_inicio' => $_GET['data_inicio'] ?? null,
            'data_fim' => $_GET['data_fim'] ?? null,
            'ip_address' => $_GET['ip_address'] ?? null
        ];
        $filtros = array_filter($filtros);
        
        $limite = $_GET['limite'] ?? 100;
        
        $registros = AuditoriaModel::listar($filtros, $limite);
        
        jsonSuccess(['auditoria' => $registros]);
        break;
    
    case 'auditoria-estatisticas':
        if ($usuario['tipo'] !== 'master') {
            jsonError('Acesso negado - Apenas MASTER', 403);
        }
        
        $dias = $_GET['dias'] ?? 7;
        $estatisticas = AuditoriaModel::getEstatisticas($dias);
        
        jsonSuccess($estatisticas);
        break;
    
    case 'exportar-auditoria':
        if ($usuario['tipo'] !== 'master') {
            jsonError('Acesso negado - Apenas MASTER', 403);
        }
        
        $filtros = [
            'data_inicio' => $_GET['data_inicio'] ?? null,
            'data_fim' => $_GET['data_fim'] ?? null
        ];
        $filtros = array_filter($filtros);
        
        $csv = AuditoriaModel::exportarCSV($filtros);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="auditoria_' . date('Y-m-d') . '.csv"');
        echo $csv;
        exit;
    
    // ===== CONFIGURAÇÕES =====
    case 'configuracoes':
        $db = db();
        $config = $db->getConfig();
        
        jsonSuccess($config);
        break;
    
    case 'atualizar-configuracoes':
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Método não permitido', 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $dados = [
            'dias_atraso_bloqueio' => $input['dias_atraso_bloqueio'] ?? null,
            'tolerancia_inicio_ano' => $input['tolerancia_inicio_ano'] ?? null,
            'msg_bloqueio_ativo' => $input['msg_bloqueio_ativo'] ?? null,
            'msg_alerta_preventivo' => $input['msg_alerta_preventivo'] ?? null,
            'ano_letivo_atual' => $input['ano_letivo_atual'] ?? null,
            'bimestre_atual' => $input['bimestre_atual'] ?? null,
            'manutencao_mode' => isset($input['manutencao_mode']) ? $input['manutencao_mode'] : null,
            'backup_auto' => isset($input['backup_auto']) ? $input['backup_auto'] : null
        ];
        
        $dados = array_filter($dados, function($v) { return $v !== null; });
        
        $db = db();
        $db->updateConfig($dados, $usuario['id']);
        
        jsonSuccess(null, 'Configurações atualizadas');
        break;
    
    // ===== DOCUMENTOS =====
    case 'gerar-declaracao':
        $alunoId = $_GET['aluno_id'] ?? '';
        
        if (empty($alunoId)) {
            jsonError('ID do aluno é obrigatório');
        }
        
        $documento = PdfHelper::gerarDeclaracaoMatricula($alunoId, $usuario['id']);
        
        jsonSuccess($documento);
        break;
    
    case 'gerar-boletim-pdf':
        $alunoId = $_GET['aluno_id'] ?? '';
        $bimestre = $_GET['bimestre'] ?? null;
        $ano = $_GET['ano'] ?? null;
        
        if (empty($alunoId)) {
            jsonError('ID do aluno é obrigatório');
        }
        
        $documento = PdfHelper::gerarBoletim($alunoId, $bimestre, $ano);
        
        jsonSuccess($documento);
        break;
    
    // ===== BACKUP =====
    case 'backup':
        if ($usuario['tipo'] !== 'master') {
            jsonError('Acesso negado - Apenas MASTER', 403);
        }
        
        // Criar backup
        $backupFile = 'backup_' . date('Y-m-d_H-i-s') . '.db';
        $backupPath = BACKUP_PATH . $backupFile;
        
        if (!is_dir(BACKUP_PATH)) {
            mkdir(BACKUP_PATH, 0755, true);
        }
        
        copy(DB_PATH, $backupPath);
        
        // Registrar
        $db = db();
        $db->insert('backups', [
            'arquivo' => $backupFile,
            'tamanho' => filesize($backupPath),
            'tipo' => 'manual'
        ]);
        
        jsonSuccess(['arquivo' => $backupFile], 'Backup criado com sucesso');
        break;
    
    case 'listar-backups':
        if ($usuario['tipo'] !== 'master') {
            jsonError('Acesso negado - Apenas MASTER', 403);
        }
        
        $db = db();
        $backups = $db->fetchAll("SELECT * FROM backups ORDER BY created_at DESC LIMIT 50");
        
        jsonSuccess(['backups' => $backups]);
        break;
    
    default:
        jsonError('Ação não encontrada', 404);
}
