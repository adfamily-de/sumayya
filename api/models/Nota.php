<?php
/**
 * Model Nota - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';

class Nota {
    
    /**
     * Buscar nota por ID
     */
    public static function buscarPorId($id) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT n.*, a.nome as aluno_nome, d.nome as disciplina_nome
             FROM notas n
             JOIN alunos a ON n.aluno_id = a.id
             JOIN disciplinas d ON n.disciplina_id = d.id
             WHERE n.id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Lançar nota
     */
    public static function lancar($dados) {
        $db = db();
        
        // Verificar se já existe nota para este aluno/disciplina/bimestre/ano
        $existe = $db->fetchOne(
            "SELECT id FROM notas 
             WHERE aluno_id = :aluno_id AND disciplina_id = :disciplina_id 
             AND bimestre = :bimestre AND ano_letivo = :ano",
            [
                ':aluno_id' => $dados['aluno_id'],
                ':disciplina_id' => $dados['disciplina_id'],
                ':bimestre' => $dados['bimestre'],
                ':ano' => $dados['ano_letivo']
            ]
        );
        
        if ($existe) {
            // Atualizar nota existente
            return self::atualizar($existe['id'], $dados);
        }
        
        // Inserir nova nota
        $dados['data_lancamento'] = date('Y-m-d H:i:s');
        
        $id = $db->insert('notas', $dados);
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria(
            'professor', 
            $dados['professor_id'], 
            null, 
            'nota_lancada',
            'nota',
            $id
        );
        
        return ['id' => $id, 'acao' => 'criada'];
    }
    
    /**
     * Atualizar nota
     */
    public static function atualizar($id, $dados) {
        $db = db();
        
        $nota = self::buscarPorId($id);
        if (!$nota) {
            return ['erro' => 'Nota não encontrada'];
        }
        
        // Verificar permissão de edição
        $usuario = [
            'id' => $dados['editado_por'] ?? $dados['professor_id'],
            'tipo' => $dados['usuario_tipo'] ?? 'professor'
        ];
        
        $podeEditar = AuthHelper::podeEditarNota($usuario, $nota);
        
        if (!$podeEditar['pode']) {
            return ['erro' => $podeEditar['motivo'], 'solicitar_correcao' => $podeEditar['solicitar_correcao'] ?? false];
        }
        
        // Preparar dados
        $updateData = [
            'nota' => $dados['nota'],
            'faltas' => $dados['faltas'] ?? 0,
            'observacoes' => $dados['observacoes'] ?? null,
            'data_ultima_edicao' => date('Y-m-d H:i:s'),
            'editado_por' => $dados['editado_por'] ?? $dados['professor_id']
        ];
        
        // Se edição após 48h, marcar e registrar justificativa
        $dataLancamento = strtotime($nota['data_lancamento']);
        $horasDecorridas = (time() - $dataLancamento) / 3600;
        
        if ($horasDecorridas > HORAS_EDICAO_JUSTIFICADA) {
            $updateData['editado_apos_48h'] = 1;
            if (!empty($dados['justificativa'])) {
                $updateData['justificativa_edicao'] = $dados['justificativa'];
            }
        } elseif ($horasDecorridas > HORAS_EDICAO_LIVRE) {
            if (!empty($dados['justificativa'])) {
                $updateData['justificativa_edicao'] = $dados['justificativa'];
            }
        }
        
        $db->update('notas', $updateData, 'id = :id', [':id' => $id]);
        
        // Registrar auditoria
        AuthHelper::registrarAuditoria(
            $usuario['tipo'], 
            $usuario['id'], 
            null, 
            'nota_editada',
            'nota',
            $id,
            null,
            ['horas_decorridas' => round($horasDecorridas, 2)]
        );
        
        return ['id' => $id, 'acao' => 'atualizada', 'tipo_edicao' => $podeEditar['tipo']];
    }
    
    /**
     * Bloquear nota (apenas admin/master)
     */
    public static function bloquear($id, $bloquear = true) {
        $db = db();
        
        $db->update('notas', [
            'bloqueada' => $bloquear ? 1 : 0
        ], 'id = :id', [':id' => $id]);
        
        return true;
    }
    
    /**
     * Obter notas por turma e disciplina
     */
    public static function getPorTurmaDisciplina($turmaId, $disciplinaId, $bimestre = null, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        if (!$bimestre) {
            $config = $db->getConfig();
            $bimestre = $config['bimestre_atual'];
        }
        
        return $db->fetchAll(
            "SELECT n.*, a.nome as aluno_nome, a.codigo_acesso, p.nome as professor_nome
             FROM notas n
             JOIN alunos a ON n.aluno_id = a.id
             LEFT JOIN professores p ON n.professor_id = p.id
             WHERE a.turma_id = :turma_id AND n.disciplina_id = :disciplina_id
             AND n.bimestre = :bimestre AND n.ano_letivo = :ano
             ORDER BY a.nome",
            [
                ':turma_id' => $turmaId,
                ':disciplina_id' => $disciplinaId,
                ':bimestre' => $bimestre,
                ':ano' => $ano
            ]
        );
    }
    
    /**
     * Obter notas por aluno
     */
    public static function getPorAluno($alunoId, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        
        return $db->fetchAll(
            "SELECT n.*, d.nome as disciplina_nome, d.codigo as disciplina_codigo
             FROM notas n
             JOIN disciplinas d ON n.disciplina_id = d.id
             WHERE n.aluno_id = :aluno_id AND n.ano_letivo = :ano
             ORDER BY d.nome, n.bimestre",
            [':aluno_id' => $alunoId, ':ano' => $ano]
        );
    }
    
    /**
     * Calcular média do aluno
     */
    public static function calcularMedia($alunoId, $disciplinaId = null, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        
        $sql = "SELECT AVG(nota) as media FROM notas 
                WHERE aluno_id = :aluno_id AND ano_letivo = :ano";
        $params = [':aluno_id' => $alunoId, ':ano' => $ano];
        
        if ($disciplinaId) {
            $sql .= " AND disciplina_id = :disciplina_id";
            $params[':disciplina_id'] = $disciplinaId;
        }
        
        $result = $db->fetchOne($sql, $params);
        
        return $result['media'] ? round($result['media'], 1) : 0;
    }
    
    /**
     * Criar solicitação de correção
     */
    public static function solicitarCorrecao($notaId, $professorId, $motivo) {
        $db = db();
        
        $id = $db->insert('solicitacoes_correcao', [
            'nota_id' => $notaId,
            'professor_id' => $professorId,
            'motivo' => $motivo,
            'status' => 'pendente'
        ]);
        
        return ['id' => $id];
    }
    
    /**
     * Analisar solicitação de correção
     */
    public static function analisarSolicitacao($solicitacaoId, $status, $analisadoPor, $resposta = null) {
        $db = db();
        
        $db->update('solicitacoes_correcao', [
            'status' => $status,
            'analisado_por' => $analisadoPor,
            'resposta' => $resposta,
            'analisado_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $solicitacaoId]);
        
        // Se aprovado, desbloquear a nota
        if ($status === 'aprovado') {
            $solicitacao = $db->fetchOne(
                "SELECT nota_id FROM solicitacoes_correcao WHERE id = :id",
                [':id' => $solicitacaoId]
            );
            if ($solicitacao) {
                self::bloquear($solicitacao['nota_id'], false);
            }
        }
        
        return true;
    }
    
    /**
     * Obter estatísticas de notas
     */
    public static function getEstatisticas($turmaId = null, $disciplinaId = null, $bimestre = null, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        if (!$bimestre) {
            $config = $db->getConfig();
            $bimestre = $config['bimestre_atual'];
        }
        
        $sql = "SELECT 
                    COUNT(*) as total,
                    AVG(nota) as media_geral,
                    MIN(nota) as nota_minima,
                    MAX(nota) as nota_maxima,
                    SUM(CASE WHEN nota >= 10 THEN 1 ELSE 0 END) as aprovados,
                    SUM(CASE WHEN nota < 10 THEN 1 ELSE 0 END) as reprovados
                FROM notas n
                JOIN alunos a ON n.aluno_id = a.id
                WHERE n.bimestre = :bimestre AND n.ano_letivo = :ano";
        
        $params = [':bimestre' => $bimestre, ':ano' => $ano];
        
        if ($turmaId) {
            $sql .= " AND a.turma_id = :turma_id";
            $params[':turma_id'] = $turmaId;
        }
        
        if ($disciplinaId) {
            $sql .= " AND n.disciplina_id = :disciplina_id";
            $params[':disciplina_id'] = $disciplinaId;
        }
        
        return $db->fetchOne($sql, $params);
    }
}
