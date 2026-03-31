<?php
/**
 * Model Aluno - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';

class Aluno {
    
    /**
     * Listar todos os alunos
     */
    public static function listar($filtros = []) {
        $db = db();
        
        $sql = "SELECT a.*, t.nome as turma_nome, t.ano_letivo 
                FROM alunos a 
                LEFT JOIN turmas t ON a.turma_id = t.id 
                WHERE 1=1";
        $params = [];
        
        if (!empty($filtros['turma_id'])) {
            $sql .= " AND a.turma_id = :turma_id";
            $params[':turma_id'] = $filtros['turma_id'];
        }
        
        if (!empty($filtros['ativo'])) {
            $sql .= " AND a.ativo = :ativo";
            $params[':ativo'] = $filtros['ativo'];
        }
        
        if (!empty($filtros['busca'])) {
            $sql .= " AND (a.nome LIKE :busca OR a.codigo_acesso LIKE :busca)";
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }
        
        $sql .= " ORDER BY a.nome";
        
        return $db->fetchAll($sql, $params);
    }
    
    /**
     * Buscar aluno por ID
     */
    public static function buscarPorId($id) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT a.*, t.nome as turma_nome, t.ano_letivo 
             FROM alunos a 
             LEFT JOIN turmas t ON a.turma_id = t.id 
             WHERE a.id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Buscar aluno por código de acesso
     */
    public static function buscarPorCodigo($codigo) {
        $db = db();
        $codigoHash = AuthHelper::hashCodigo($codigo);
        
        return $db->fetchOne(
            "SELECT a.*, t.nome as turma_nome 
             FROM alunos a 
             LEFT JOIN turmas t ON a.turma_id = t.id 
             WHERE a.codigo_hash = :hash",
            [':hash' => $codigoHash]
        );
    }
    
    /**
     * Criar novo aluno
     */
    public static function criar($dados) {
        $db = db();
        
        // Gerar código de acesso único
        do {
            $codigo = AuthHelper::generateCodigoAcesso(6);
            $existe = $db->fetchOne(
                "SELECT id FROM alunos WHERE codigo_acesso = :codigo",
                [':codigo' => $codigo]
            );
        } while ($existe);
        
        $dados['codigo_acesso'] = $codigo;
        $dados['codigo_hash'] = AuthHelper::hashCodigo($codigo);
        
        // Processar JSONs
        if (isset($dados['documentos']) && is_array($dados['documentos'])) {
            $dados['documentos'] = json_encode($dados['documentos']);
        }
        if (isset($dados['responsaveis']) && is_array($dados['responsaveis'])) {
            $dados['responsaveis'] = json_encode($dados['responsaveis']);
        }
        
        $id = $db->insert('alunos', $dados);
        
        return ['id' => $id, 'codigo_acesso' => $codigo];
    }
    
    /**
     * Atualizar aluno
     */
    public static function atualizar($id, $dados) {
        $db = db();
        
        // Processar JSONs
        if (isset($dados['documentos']) && is_array($dados['documentos'])) {
            $dados['documentos'] = json_encode($dados['documentos']);
        }
        if (isset($dados['responsaveis']) && is_array($dados['responsaveis'])) {
            $dados['responsaveis'] = json_encode($dados['responsaveis']);
        }
        
        $dados['updated_at'] = date('Y-m-d H:i:s');
        
        return $db->update('alunos', $dados, 'id = :id', [':id' => $id]);
    }
    
    /**
     * Alterar código de acesso
     */
    public static function alterarCodigo($id, $novoCodigo, $alteradoPor, $motivo = null) {
        $db = db();
        
        $aluno = self::buscarPorId($id);
        if (!$aluno) {
            return false;
        }
        
        // Registrar histórico
        $db->insert('historico_codigos', [
            'aluno_id' => $id,
            'codigo_antigo' => $aluno['codigo_acesso'],
            'codigo_novo' => $novoCodigo,
            'alterado_por' => $alteradoPor,
            'motivo' => $motivo
        ]);
        
        // Atualizar código
        $db->update('alunos', [
            'codigo_acesso' => $novoCodigo,
            'codigo_hash' => AuthHelper::hashCodigo($novoCodigo),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $id]);
        
        return true;
    }
    
    /**
     * Desativar aluno
     */
    public static function desativar($id) {
        $db = db();
        
        return $db->update('alunos', [
            'ativo' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $id]);
    }
    
    /**
     * Obter dashboard do aluno
     */
    public static function getDashboard($id) {
        $db = db();
        
        $aluno = self::buscarPorId($id);
        if (!$aluno) {
            return null;
        }
        
        $config = $db->getConfig();
        
        // Notas do bimestre atual
        $notas = $db->fetchAll(
            "SELECT n.*, d.nome as disciplina_nome 
             FROM notas n 
             JOIN disciplinas d ON n.disciplina_id = d.id 
             WHERE n.aluno_id = :aluno_id 
             AND n.bimestre = :bimestre 
             AND n.ano_letivo = :ano
             ORDER BY d.nome",
            [
                ':aluno_id' => $id,
                ':bimestre' => $config['bimestre_atual'],
                ':ano' => $config['ano_letivo_atual']
            ]
        );
        
        // Calcular média
        $media = 0;
        if (count($notas) > 0) {
            $soma = array_sum(array_column($notas, 'nota'));
            $media = $soma / count($notas);
        }
        
        // Mensagens não lidas
        $mensagens = $db->fetchAll(
            "SELECT * FROM mensagens 
             WHERE (destinatario_tipo = 'aluno' AND destinatario_id = :id) 
             OR (destinatario_tipo = 'turma' AND destinatario_id = :turma_id)
             OR destinatario_tipo = 'todos'
             ORDER BY created_at DESC LIMIT 5",
            [':id' => $id, ':turma_id' => $aluno['turma_id']]
        );
        
        // Verificar bloqueio
        require_once __DIR__ . '/../helpers/FinanceiroHelper.php';
        $bloqueio = FinanceiroHelper::estaBloqueado($id);
        
        return [
            'aluno' => $aluno,
            'notas' => $notas,
            'media' => round($media, 1),
            'mensagens' => $mensagens,
            'bloqueio' => $bloqueio,
            'config' => $config
        ];
    }
    
    /**
     * Obter boletim completo
     */
    public static function getBoletim($id, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        
        $aluno = self::buscarPorId($id);
        if (!$aluno) {
            return null;
        }
        
        // Notas de todos os bimestres
        $notas = $db->fetchAll(
            "SELECT n.*, d.nome as disciplina_nome, d.codigo as disciplina_codigo
             FROM notas n 
             JOIN disciplinas d ON n.disciplina_id = d.id 
             WHERE n.aluno_id = :aluno_id AND n.ano_letivo = :ano
             ORDER BY d.nome, n.bimestre",
            [':aluno_id' => $id, ':ano' => $ano]
        );
        
        // Organizar por disciplina
        $disciplinas = [];
        foreach ($notas as $nota) {
            $discId = $nota['disciplina_id'];
            if (!isset($disciplinas[$discId])) {
                $disciplinas[$discId] = [
                    'nome' => $nota['disciplina_nome'],
                    'codigo' => $nota['disciplina_codigo'],
                    'notas' => [1 => null, 2 => null, 3 => null, 4 => null],
                    'total_faltas' => 0
                ];
            }
            $disciplinas[$discId]['notas'][$nota['bimestre']] = $nota;
            $disciplinas[$discId]['total_faltas'] += $nota['faltas'];
        }
        
        // Calcular médias
        foreach ($disciplinas as &$disc) {
            $soma = 0;
            $count = 0;
            foreach ($disc['notas'] as $nota) {
                if ($nota) {
                    $soma += $nota['nota'];
                    $count++;
                }
            }
            $disc['media'] = $count > 0 ? round($soma / $count, 1) : 0;
        }
        
        return [
            'aluno' => $aluno,
            'ano' => $ano,
            'disciplinas' => $disciplinas
        ];
    }
}
