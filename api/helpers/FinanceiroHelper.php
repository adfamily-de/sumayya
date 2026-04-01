<?php
/**
 * FinanceiroHelper - Instituto Politécnico Sumayya
 * Funções para controle financeiro e propinas
 */

require_once __DIR__ . '/../database.php';

class FinanceiroHelper {
    
    /**
     * Verificar se aluno tem propinas atrasadas
     */
    public static function temPropinasAtrasadas($alunoId) {
        $db = db();
        
        $config = $db->getConfig();
        $diasBloqueio = $config['dias_atraso_bloqueio'] ?? DIAS_BLOQUEIO_PROPINA;
        
        // Verificar propinas atrasadas
        $propinas = $db->fetchAll(
            "SELECT * FROM propinas 
             WHERE aluno_id = :aluno_id 
             AND status = 'atrasado'
             AND data_vencimento < date('now', '-{$diasBloqueio} days')",
            [':aluno_id' => $alunoId]
        );
        
        return count($propinas) > 0 ? $propinas : false;
    }
    
    /**
     * Verificar se aluno está bloqueado por propina
     */
    public static function estaBloqueado($alunoId) {
        $db = db();
        
        // Verificar liberação temporária
        $liberacao = $db->fetchOne(
            "SELECT * FROM liberacoes_temporarias 
             WHERE aluno_id = :aluno_id AND liberado_ate >= date('now')",
            [':aluno_id' => $alunoId]
        );
        
        if ($liberacao) {
            return ['bloqueado' => false, 'liberacao' => $liberacao];
        }
        
        // Verificar propinas atrasadas
        $propinasAtrasadas = self::temPropinasAtrasadas($alunoId);
        
        if ($propinasAtrasadas) {
            $config = $db->getConfig();
            return [
                'bloqueado' => true,
                'motivo' => 'propina_atrasada',
                'mensagem' => $config['msg_bloqueio_ativo'],
                'propinas' => $propinasAtrasadas
            ];
        }
        
        return ['bloqueado' => false];
    }
    
    /**
     * Obter resumo financeiro do aluno
     */
    public static function getResumoAluno($alunoId) {
        $db = db();
        
        // Total pago
        $pago = $db->fetchOne(
            "SELECT COALESCE(SUM(valor), 0) as total, COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND status = 'pago'",
            [':aluno_id' => $alunoId]
        );
        
        // Total pendente
        $pendente = $db->fetchOne(
            "SELECT COALESCE(SUM(valor), 0) as total, COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND status = 'pendente'",
            [':aluno_id' => $alunoId]
        );
        
        // Total atrasado
        $atrasado = $db->fetchOne(
            "SELECT COALESCE(SUM(valor), 0) as total, COUNT(*) as quantidade 
             FROM propinas WHERE aluno_id = :aluno_id AND status = 'atrasado'",
            [':aluno_id' => $alunoId]
        );
        
        // Próximo vencimento
        $proximo = $db->fetchOne(
            "SELECT * FROM propinas 
             WHERE aluno_id = :aluno_id 
             AND status IN ('pendente', 'atrasado')
             ORDER BY data_vencimento ASC LIMIT 1",
            [':aluno_id' => $alunoId]
        );
        
        return [
            'pago' => $pago,
            'pendente' => $pendente,
            'atrasado' => $atrasado,
            'proximo_vencimento' => $proximo
        ];
    }
    
    /**
     * Gerar propinas do ano para aluno
     */
    public static function gerarPropinasAno($alunoId, $ano, $valor, $diaVencimento = 10) {
        $db = db();
        
        $geradas = 0;
        
        for ($mes = 1; $mes <= 12; $mes++) {
            // Verificar se já existe
            $existe = $db->fetchOne(
                "SELECT id FROM propinas WHERE aluno_id = :aluno_id AND mes_ref = :mes AND ano_ref = :ano",
                [':aluno_id' => $alunoId, ':mes' => $mes, ':ano' => $ano]
            );
            
            if (!$existe) {
                $dataVencimento = sprintf('%04d-%02d-%02d', $ano, $mes, $diaVencimento);
                
                $db->insert('propinas', [
                    'aluno_id' => $alunoId,
                    'mes_ref' => $mes,
                    'ano_ref' => $ano,
                    'valor' => $valor,
                    'data_vencimento' => $dataVencimento,
                    'status' => 'pendente'
                ]);
                
                $geradas++;
            }
        }
        
        return $geradas;
    }
    
    /**
     * Registrar pagamento
     */
    public static function registrarPagamento($propinaId, $dataPagamento, $metodo, $registradoPor, $comprovante = null) {
        $db = db();
        
        $data = [
            'status' => 'pago',
            'data_pagamento' => $dataPagamento,
            'metodo_pagamento' => $metodo,
            'registrado_por' => $registradoPor
        ];
        
        if ($comprovante) {
            $data['comprovante_url'] = $comprovante;
        }
        
        $db->update('propinas', $data, 'id = :id', [':id' => $propinaId]);
        
        return true;
    }
    
    /**
     * Atualizar status de propinas vencidas
     */
    public static function atualizarPropinasVencidas() {
        $db = db();
        
        // Atualizar propinas pendentes com vencimento ultrapassado
        $db->query(
            "UPDATE propinas 
             SET status = 'atrasado' 
             WHERE status = 'pendente' 
             AND data_vencimento < date('now')"
        );
        
        return $db->getConnection()->rowCount();
    }
    
    /**
     * Criar liberação temporária
     */
    public static function criarLiberacao($alunoId, $dias, $motivo, $liberadoPor) {
        $db = db();
        
        $liberadoAte = date('Y-m-d', strtotime("+{$dias} days"));
        
        $id = $db->insert('liberacoes_temporarias', [
            'aluno_id' => $alunoId,
            'liberado_ate' => $liberadoAte,
            'motivo' => $motivo,
            'liberado_por' => $liberadoPor
        ]);
        
        return ['id' => $id, 'liberado_ate' => $liberadoAte];
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
        
        // Totais por status
        $porStatus = $db->fetchAll(
            "SELECT status, COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total 
             FROM propinas WHERE {$where} GROUP BY status",
            $params
        );
        
        // Total geral
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
        
        return [
            'por_status' => $porStatus,
            'total' => $total,
            'por_mes' => $porMes
        ];
    }
    
    /**
     * Gerar link de pagamento Pix (simulado)
     */
    public static function gerarPix($propinaId) {
        $db = db();
        
        $propina = $db->fetchOne(
            "SELECT p.*, a.nome as aluno_nome, a.codigo_acesso 
             FROM propinas p 
             JOIN alunos a ON p.aluno_id = a.id 
             WHERE p.id = :id",
            [':id' => $propinaId]
        );
        
        if (!$propina) {
            return null;
        }
        
        // Simular geração de QR Code Pix
        $pixCode = base64_encode(json_encode([
            'propina_id' => $propinaId,
            'aluno' => $propina['aluno_nome'],
            'valor' => $propina['valor'],
            'vencimento' => $propina['data_vencimento'],
            'timestamp' => time()
        ]));
        
        return [
            'pix_code' => $pixCode,
            'qr_code_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCode),
            'valor' => $propina['valor'],
            'descricao' => "Propina {$propina['mes_ref']}/{$propina['ano_ref']} - {$propina['aluno_nome']}"
        ];
    }
}
