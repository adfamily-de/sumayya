<?php
/**
 * Model Professor - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';

class Professor {
    
    /**
     * Listar todos os professores
     */
    public static function listar($filtros = []) {
        $db = db();
        
        $sql = "SELECT p.*, 
                (SELECT COUNT(*) FROM turma_disciplina_professor WHERE professor_id = p.id) as total_turmas
                FROM professores p WHERE 1=1";
        $params = [];
        
        if (!empty($filtros['cargo'])) {
            $sql .= " AND p.cargo = :cargo";
            $params[':cargo'] = $filtros['cargo'];
        }
        
        if (!empty($filtros['ativo'])) {
            $sql .= " AND p.ativo = :ativo";
            $params[':ativo'] = $filtros['ativo'];
        }
        
        if (!empty($filtros['busca'])) {
            $sql .= " AND (p.nome LIKE :busca OR p.login LIKE :busca OR p.email LIKE :busca)";
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }
        
        $sql .= " ORDER BY p.nome";
        
        return $db->fetchAll($sql, $params);
    }
    
    /**
     * Buscar professor por ID
     */
    public static function buscarPorId($id) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT * FROM professores WHERE id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Buscar professor por login
     */
    public static function buscarPorLogin($login) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT * FROM professores WHERE login = :login",
            [':login' => $login]
        );
    }
    
    /**
     * Criar novo professor
     */
    public static function criar($dados) {
        $db = db();
        
        // Verificar login único
        $existe = self::buscarPorLogin($dados['login']);
        if ($existe) {
            return ['erro' => 'Login já existe'];
        }
        
        // Hash da senha
        if (isset($dados['senha'])) {
            $dados['senha_hash'] = AuthHelper::hashPassword($dados['senha']);
            unset($dados['senha']);
        }
        
        // Processar permissões
        if (isset($dados['permissoes']) && is_array($dados['permissoes'])) {
            $dados['permissoes'] = json_encode($dados['permissoes']);
        }
        
        $id = $db->insert('professores', $dados);
        
        return ['id' => $id];
    }
    
    /**
     * Atualizar professor
     */
    public static function atualizar($id, $dados) {
        $db = db();
        
        // Se estiver alterando senha
        if (isset($dados['senha']) && !empty($dados['senha'])) {
            $dados['senha_hash'] = AuthHelper::hashPassword($dados['senha']);
            unset($dados['senha']);
        }
        
        // Processar permissões
        if (isset($dados['permissoes']) && is_array($dados['permissoes'])) {
            $dados['permissoes'] = json_encode($dados['permissoes']);
        }
        
        $dados['updated_at'] = date('Y-m-d H:i:s');
        
        return $db->update('professores', $dados, 'id = :id', [':id' => $id]);
    }
    
    /**
     * Suspender professor
     */
    public static function suspender($id, $dias, $motivo = null) {
        $db = db();
        
        $suspensoAte = date('Y-m-d', strtotime("+{$dias} days"));
        
        return $db->update('professores', [
            'suspenso_ate' => $suspensoAte,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $id]);
    }
    
    /**
     * Desativar professor
     */
    public static function desativar($id) {
        $db = db();
        
        return $db->update('professores', [
            'ativo' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $id]);
    }
    
    /**
     * Obter turmas do professor
     */
    public static function getTurmas($id, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        
        return $db->fetchAll(
            "SELECT t.*, d.nome as disciplina_nome, d.id as disciplina_id
             FROM turma_disciplina_professor tdp
             JOIN turmas t ON tdp.turma_id = t.id
             JOIN disciplinas d ON tdp.disciplina_id = d.id
             WHERE tdp.professor_id = :professor_id AND tdp.ano_letivo = :ano
             ORDER BY t.nome, d.nome",
            [':professor_id' => $id, ':ano' => $ano]
        );
    }
    
    /**
     * Obter dashboard do professor
     */
    public static function getDashboard($id) {
        $db = db();
        
        $professor = self::buscarPorId($id);
        if (!$professor) {
            return null;
        }
        
        $config = $db->getConfig();
        
        // Total de turmas
        $turmas = $db->fetchOne(
            "SELECT COUNT(DISTINCT turma_id) as total FROM turma_disciplina_professor 
             WHERE professor_id = :id AND ano_letivo = :ano",
            [':id' => $id, ':ano' => $config['ano_letivo_atual']]
        );
        
        // Total de alunos
        $alunos = $db->fetchOne(
            "SELECT COUNT(DISTINCT a.id) as total 
             FROM alunos a
             JOIN turma_disciplina_professor tdp ON a.turma_id = tdp.turma_id
             WHERE tdp.professor_id = :id AND tdp.ano_letivo = :ano AND a.ativo = 1",
            [':id' => $id, ':ano' => $config['ano_letivo_atual']]
        );
        
        // Notas lançadas no bimestre atual
        $notasLancadas = $db->fetchOne(
            "SELECT COUNT(*) as total FROM notas 
             WHERE professor_id = :id AND bimestre = :bimestre AND ano_letivo = :ano",
            [
                ':id' => $id,
                ':bimestre' => $config['bimestre_atual'],
                ':ano' => $config['ano_letivo_atual']
            ]
        );
        
        // Total de notas a lançar (alunos * disciplinas)
        $totalNotas = $db->fetchOne(
            "SELECT COUNT(*) as total 
             FROM alunos a
             JOIN turma_disciplina_professor tdp ON a.turma_id = tdp.turma_id
             WHERE tdp.professor_id = :id AND tdp.ano_letivo = :ano AND a.ativo = 1",
            [':id' => $id, ':ano' => $config['ano_letivo_atual']]
        );
        
        // Solicitações de correção pendentes
        $solicitacoes = $db->fetchOne(
            "SELECT COUNT(*) as total FROM solicitacoes_correcao 
             WHERE professor_id = :id AND status = 'pendente'",
            [':id' => $id]
        );
        
        return [
            'professor' => $professor,
            'estatisticas' => [
                'turmas' => $turmas['total'],
                'alunos' => $alunos['total'],
                'notas_lancadas' => $notasLancadas['total'],
                'notas_pendentes' => $totalNotas['total'] - $notasLancadas['total'],
                'solicitacoes_pendentes' => $solicitacoes['total']
            ],
            'config' => $config
        ];
    }
    
    /**
     * Obter alunos para lançamento de notas
     */
    public static function getAlunosParaNotas($professorId, $turmaId, $disciplinaId, $bimestre = null, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        if (!$bimestre) {
            $config = $db->getConfig();
            $bimestre = $config['bimestre_atual'];
        }
        
        // Verificar se professor leciona essa disciplina para essa turma
        $leciona = $db->fetchOne(
            "SELECT * FROM turma_disciplina_professor 
             WHERE professor_id = :professor_id AND turma_id = :turma_id 
             AND disciplina_id = :disciplina_id AND ano_letivo = :ano",
            [
                ':professor_id' => $professorId,
                ':turma_id' => $turmaId,
                ':disciplina_id' => $disciplinaId,
                ':ano' => $ano
            ]
        );
        
        if (!$leciona) {
            return ['erro' => 'Professor não leciona esta disciplina para esta turma'];
        }
        
        // Buscar alunos e suas notas
        return $db->fetchAll(
            "SELECT a.id, a.nome, a.codigo_acesso, 
                    n.id as nota_id, n.nota, n.faltas, n.observacoes, 
                    n.data_lancamento, n.data_ultima_edicao, n.bloqueada
             FROM alunos a
             LEFT JOIN notas n ON a.id = n.aluno_id 
                AND n.disciplina_id = :disciplina_id 
                AND n.bimestre = :bimestre 
                AND n.ano_letivo = :ano
             WHERE a.turma_id = :turma_id AND a.ativo = 1
             ORDER BY a.nome",
            [
                ':turma_id' => $turmaId,
                ':disciplina_id' => $disciplinaId,
                ':bimestre' => $bimestre,
                ':ano' => $ano
            ]
        );
    }
}
