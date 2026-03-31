<?php
/**
 * Model Auditoria - Instituto Politécnico Sumayya
 */

require_once __DIR__ . '/../database.php';

class AuditoriaModel {
    
    /**
     * Registrar entrada de auditoria
     */
    public static function registrar($dados) {
        $db = db();
        
        $id = $db->insert('auditoria', [
            'usuario_tipo' => $dados['usuario_tipo'],
            'usuario_id' => $dados['usuario_id'],
            'usuario_nome' => $dados['usuario_nome'] ?? null,
            'acao' => $dados['acao'],
            'ip_address' => $dados['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $dados['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            'codigo_acesso_usado' => $dados['codigo_acesso_usado'] ?? null,
            'alvo_tipo' => $dados['alvo_tipo'] ?? null,
            'alvo_id' => $dados['alvo_id'] ?? null,
            'detalhes' => isset($dados['detalhes']) ? json_encode($dados['detalhes']) : null
        ]);
        
        return $id;
    }
    
    /**
     * Listar registros de auditoria
     */
    public static function listar($filtros = [], $limite = 100) {
        $db = db();
        
        $sql = "SELECT * FROM auditoria WHERE 1=1";
        $params = [];
        
        if (!empty($filtros['usuario_tipo'])) {
            $sql .= " AND usuario_tipo = :usuario_tipo";
            $params[':usuario_tipo'] = $filtros['usuario_tipo'];
        }
        
        if (!empty($filtros['usuario_id'])) {
            $sql .= " AND usuario_id = :usuario_id";
            $params[':usuario_id'] = $filtros['usuario_id'];
        }
        
        if (!empty($filtros['acao'])) {
            $sql .= " AND acao = :acao";
            $params[':acao'] = $filtros['acao'];
        }
        
        if (!empty($filtros['alvo_tipo'])) {
            $sql .= " AND alvo_tipo = :alvo_tipo";
            $params[':alvo_tipo'] = $filtros['alvo_tipo'];
        }
        
        if (!empty($filtros['data_inicio'])) {
            $sql .= " AND DATE(created_at) >= :data_inicio";
            $params[':data_inicio'] = $filtros['data_inicio'];
        }
        
        if (!empty($filtros['data_fim'])) {
            $sql .= " AND DATE(created_at) <= :data_fim";
            $params[':data_fim'] = $filtros['data_fim'];
        }
        
        if (!empty($filtros['ip_address'])) {
            $sql .= " AND ip_address = :ip_address";
            $params[':ip_address'] = $filtros['ip_address'];
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limite;
        
        return $db->fetchAll($sql, $params);
    }
    
    /**
     * Obter estatísticas de auditoria
     */
    public static function getEstatisticas($dias = 7) {
        $db = db();
        
        // Acessos nos últimos dias
        $acessos = $db->fetchOne(
            "SELECT COUNT(*) as total FROM auditoria 
             WHERE created_at >= datetime('now', '-{$dias} days')"
        );
        
        // Acessos por tipo de usuário
        $porTipo = $db->fetchAll(
            "SELECT usuario_tipo, COUNT(*) as total FROM auditoria 
             WHERE created_at >= datetime('now', '-{$dias} days')
             GROUP BY usuario_tipo"
        );
        
        // Ações mais comuns
        $acoes = $db->fetchAll(
            "SELECT acao, COUNT(*) as total FROM auditoria 
             WHERE created_at >= datetime('now', '-{$dias} days')
             GROUP BY acao ORDER BY total DESC LIMIT 10"
        );
        
        // Tentativas de login falhas
        $falhasLogin = $db->fetchOne(
            "SELECT COUNT(*) as total FROM auditoria 
             WHERE acao = 'login_falha' 
             AND created_at >= datetime('now', '-{$dias} days')"
        );
        
        // Edições de notas após 48h
        $edicoesPos48h = $db->fetchAll(
            "SELECT a.*, n.editado_apos_48h 
             FROM auditoria a
             JOIN notas n ON a.alvo_id = n.id AND a.alvo_tipo = 'nota'
             WHERE a.acao = 'nota_editada' 
             AND n.editado_apos_48h = 1
             AND a.created_at >= datetime('now', '-{$dias} days')
             ORDER BY a.created_at DESC LIMIT 20"
        );
        
        // IPs suspeitos (muitas falhas)
        $ipsSuspeitos = $db->fetchAll(
            "SELECT ip_address, COUNT(*) as total FROM auditoria 
             WHERE acao = 'login_falha' 
             AND created_at >= datetime('now', '-1 day')
             GROUP BY ip_address HAVING total >= 3
             ORDER BY total DESC"
        );
        
        // Últimos acessos
        $ultimosAcessos = $db->fetchAll(
            "SELECT * FROM auditoria 
             WHERE acao IN ('login_sucesso', 'logout')
             ORDER BY created_at DESC LIMIT 20"
        );
        
        return [
            'acessos_total' => $acessos['total'],
            'por_tipo' => $porTipo,
            'acoes' => $acoes,
            'falhas_login' => $falhasLogin['total'],
            'edicoes_pos_48h' => $edicoesPos48h,
            'ips_suspeitos' => $ipsSuspeitos,
            'ultimos_acessos' => $ultimosAcessos
        ];
    }
    
    /**
     * Verificar atividade suspeita
     */
    public static function verificarSuspeita($ipAddress = null, $usuarioId = null) {
        $db = db();
        
        $alertas = [];
        
        // Verificar múltiplas falhas de login
        if ($ipAddress) {
            $falhas = $db->fetchOne(
                "SELECT COUNT(*) as total FROM auditoria 
                 WHERE ip_address = :ip AND acao = 'login_falha'
                 AND created_at >= datetime('now', '-1 hour')",
                [':ip' => $ipAddress]
            );
            
            if ($falhas['total'] >= 3) {
                $alertas[] = [
                    'tipo' => 'falhas_login',
                    'gravidade' => 'alta',
                    'mensagem' => 'Múltiplas tentativas de login falhas detectadas',
                    'detalhes' => ['tentativas' => $falhas['total'], 'ip' => $ipAddress]
                ];
            }
        }
        
        // Verificar acessos fora do horário (ex: 23h às 5h)
        $hora = date('H');
        if ($hora >= 23 || $hora < 5) {
            $alertas[] = [
                'tipo' => 'horario_incomum',
                'gravidade' => 'media',
                'mensagem' => 'Acesso em horário incomum detectado'
            ];
        }
        
        return $alertas;
    }
    
    /**
     * Limpar registros antigos
     */
    public static function limparAntigos($dias = 365) {
        $db = db();
        
        $db->delete(
            "auditoria", 
            "created_at < datetime('now', '-{$dias} days')"
        );
        
        return $db->getConnection()->rowCount();
    }
    
    /**
     * Exportar para CSV
     */
    public static function exportarCSV($filtros = []) {
        $registros = self::listar($filtros, 10000);
        
        $csv = "Data,Usuário Tipo,Usuário ID,Usuário Nome,Ação,IP,Alvo Tipo,Alvo ID,Detalhes\n";
        
        foreach ($registros as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $r['created_at'],
                $r['usuario_tipo'],
                $r['usuario_id'],
                str_replace(',', ' ', $r['usuario_nome'] ?? ''),
                $r['acao'],
                $r['ip_address'] ?? '',
                $r['alvo_tipo'] ?? '',
                $r['alvo_id'] ?? '',
                str_replace(',', ' ', $r['detalhes'] ?? '')
            );
        }
        
        return $csv;
    }
}
