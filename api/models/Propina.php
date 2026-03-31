<?php
/**
 * Model Propina - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../database.php';

class Propina {
    
    /**
     * Buscar propina por ID
     */
    public static function buscarPorId($id) {
        $db = db();
        
        return $db->fetchOne(
            "SELECT p.*, a.nome as aluno_nome, a.codigo_acesso
             FROM propinas p
             JOIN alunos a ON p.aluno_id = a.id
             WHERE p.id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Listar propinas do aluno
     */
    public static function listarPorAluno($alunoId, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $ano = date('Y');
        }
        
        return $db->fetchAll(
            "SELECT * FROM propinas 
             WHERE aluno_id = :aluno_id AND ano_ref = :ano
             ORDER BY mes_ref",
            [':aluno_id' => $alunoId, ':ano' => $ano]
        );
    }
    
    /**
     * Criar propina
     */
    public static function criar($dados) {
        $db = db();
        
        // Verificar se já existe
        $existe = $db->fetchOne(
            "SELECT id FROM propinas 
             WHERE aluno_id = :aluno_id AND mes_ref = :mes AND ano_ref = :ano",
            [
                ':aluno_id' => $dados['aluno_id'],
                ':mes' => $dados['mes_ref'],
                ':ano' => $dados['ano_ref']
            ]
        );
        
        if ($existe) {
            return ['erro' => 'Propina já existe para este mês/ano'];
        }
        
        $id = $db->insert('propinas', $dados);
        
        return ['id' => $id];
    }
    
    /**
     * Registrar pagamento
     */
    public static function registrarPagamento($id, $dados) {
        $db = db();
        
        $propina = self::buscarPorId($id);
        if (!$propina) {
            return ['erro' => 'Propina não encontrada'];
        }
        
        $updateData = [
            'status' => 'pago',
            'data_pagamento' => $dados['data_pagamento'] ?? date('Y-m-d'),
            'metodo_pagamento' => $dados['metodo_pagamento'] ?? 'dinheiro',
            'registrado_por' => $dados['registrado_por'],
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if (!empty($dados['comprovante_url'])) {
            $updateData['comprovante_url'] = $dados['comprovante_url'];
        }
        
        if (!empty($dados['observacoes'])) {
            $updateData['observacoes'] = $dados['observacoes'];
        }
        
        $db->update('propinas', $updateData, 'id = :id', [':id' => $id]);
        
        return ['id' => $id, 'status' => 'pago'];
    }
    
    /**
     * Atualizar status
     */
    public static function atualizarStatus($id, $status, $observacoes = null) {
        $db = db();
        
        $data = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if ($observacoes) {
            $data['observacoes'] = $observacoes;
        }
        
        $db->update('propinas', $data, 'id = :id', [':id' => $id]);
        
        return true;
    }
    
    /**
     * Negociar propina
     */
    public static function negociar($id, $dados) {
        $db = db();
        
        $negociacao = [
            'valor_negociado' => $dados['valor_negociado'],
            'parcelas' => $dados['parcelas'] ?? 1,
            'data_inicio' => $dados['data_inicio'],
            'observacoes' => $dados['observacoes'] ?? null
        ];
        
        $db->update('propinas', [
            'status' => 'negociacao',
            'negociacao' => json_encode($negociacao),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = :id', [':id' => $id]);
        
        return true;
    }
    
    /**
     * Obter resumo por aluno
     */
    public static function getResumoAluno($alunoId, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $ano = date('Y');
        }
        
        // Total pago
        $pago = $db->fetchOne(
            "SELECT COALESCE(SUM(valor), 0) as total, COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND ano_ref = :ano AND status = 'pago'",
            [':aluno_id' => $alunoId, ':ano' => $ano]
        );
        
        // Total pendente
        $pendente = $db->fetchOne(
            "SELECT COALESCE(SUM(valor), 0) as total, COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND ano_ref = :ano AND status = 'pendente'",
            [':aluno_id' => $alunoId, ':ano' => $ano]
        );
        
        // Total atrasado
        $atrasado = $db->fetchOne(
            "SELECT COALESCE(SUM(valor), 0) as total, COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND ano_ref = :ano AND status = 'atrasado'",
            [':aluno_id' => $alunoId, ':ano' => $ano]
        );
        
        // Isentos
        $isento = $db->fetchOne(
            "SELECT COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND ano_ref = :ano AND status = 'isento'",
            [':aluno_id' => $alunoId, ':ano' => $ano]
        );
        
        // Próximo vencimento
        $proximo = $db->fetchOne(
            "SELECT * FROM propinas 
             WHERE aluno_id = :aluno_id AND status IN ('pendente', 'atrasado')
             ORDER BY data_vencimento ASC LIMIT 1",
            [':aluno_id' => $alunoId]
        );
        
        return [
            'pago' => $pago,
            'pendente' => $pendente,
            'atrasado' => $atrasado,
            'isento' => $isento,
            'proximo_vencimento' => $proximo,
            'total_devido' => $pendente['total'] + $atrasado['total']
        ];
    }
    
    /**
     * Listar todas as propinas com filtros
     */
    public static function listar($filtros = []) {
        $db = db();
        
        $sql = "SELECT p.*, a.nome as aluno_nome, a.codigo_acesso
                FROM propinas p
                JOIN alunos a ON p.aluno_id = a.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filtros['aluno_id'])) {
            $sql .= " AND p.aluno_id = :aluno_id";
            $params[':aluno_id'] = $filtros['aluno_id'];
        }
        
        if (!empty($filtros['status'])) {
            $sql .= " AND p.status = :status";
            $params[':status'] = $filtros['status'];
        }
        
        if (!empty($filtros['ano'])) {
            $sql .= " AND p.ano_ref = :ano";
            $params[':ano'] = $filtros['ano'];
        }
        
        if (!empty($filtros['mes'])) {
            $sql .= " AND p.mes_ref = :mes";
            $params[':mes'] = $filtros['mes'];
        }
        
        if (!empty($filtros['vencidas'])) {
            $sql .= " AND p.data_vencimento < date('now') AND p.status IN ('pendente', 'atrasado')";
        }
        
        $sql .= " ORDER BY p.data_vencimento DESC, a.nome";
        
        return $db->fetchAll($sql, $params);
    }
    
    /**
     * Obter estatísticas financeiras
     */
    public static function getEstatisticas($ano = null, $mes = null) {
        $db = db();
        
        if (!$ano) {
            $ano = date('Y');
        }
        
        $params = [':ano' => $ano];
        $where = "ano_ref = :ano";
        
        if ($mes) {
            $where .= " AND mes_ref = :mes";
            $params[':mes'] = $mes;
        }
        
        // Por status
        $porStatus = $db->fetchAll(
            "SELECT status, COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total 
             FROM propinas WHERE {$where} GROUP BY status",
            $params
        );
        
        // Total
        $total = $db->fetchOne(
            "SELECT COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total 
             FROM propinas WHERE {$where}",
            $params
        );
        
        // Por mês (se ano inteiro)
        $porMes = [];
        if (!$mes) {
            $porMes = $db->fetchAll(
                "SELECT mes_ref, status, COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total 
                 FROM propinas WHERE ano_ref = :ano GROUP BY mes_ref, status ORDER BY mes_ref",
                [':ano' => $ano]
            );
        }
        
        // Vencidas
        $vencidas = $db->fetchOne(
            "SELECT COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total 
             FROM propinas WHERE status = 'atrasado' AND ano_ref = :ano",
            [':ano' => $ano]
        );
        
        return [
            'por_status' => $porStatus,
            'total' => $total,
            'por_mes' => $porMes,
            'vencidas' => $vencidas
        ];
    }
}
