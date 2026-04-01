<?php
/**
 * AuthHelper - Instituto Politécnico Sumayya
 * Funções de autenticação e autorização
 */

require_once __DIR__ . '/../database.php';

class AuthHelper {
    
    /**
     * Gerar hash de senha seguro
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Verificar senha
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Gerar token de sessão
     */
    public static function generateToken() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Gerar código de acesso para aluno (6-8 caracteres)
     */
    public static function generateCodigoAcesso($length = 6) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sem I, O, 0, 1 para evitar confusão
        $codigo = '';
        for ($i = 0; $i < $length; $i++) {
            $codigo .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $codigo;
    }
    
    /**
     * Hash do código de acesso para verificação
     */
    public static function hashCodigo($codigo) {
        return hash('sha256', strtoupper($codigo));
    }
    
    /**
     * Autenticar aluno por código e PIN/data de nascimento
     */
    public static function autenticarAluno($codigo, $pin) {
        $db = db();
        $codigoHash = self::hashCodigo($codigo);
        
        $aluno = $db->fetchOne(
            "SELECT * FROM alunos WHERE codigo_hash = :hash AND ativo = 1",
            [':hash' => $codigoHash]
        );
        
        if (!$aluno) {
            return null;
        }
        
        // Verificar PIN ou data de nascimento
        $pinValido = false;
        
        if (!empty($aluno['pin']) && $aluno['pin'] === $pin) {
            $pinValido = true;
        } elseif ($aluno['data_nascimento'] === $pin) {
            $pinValido = true;
        }
        
        if (!$pinValido) {
            // Registrar tentativa falha
            self::registrarAuditoria('aluno', $aluno['id'], $aluno['nome'], 'login_falha', null, null, $codigo);
            return null;
        }
        
        // Criar sessão
        $token = self::createSession('aluno', $aluno['id']);
        
        // Registrar acesso
        self::registrarAuditoria('aluno', $aluno['id'], $aluno['nome'], 'login_sucesso', null, null, $codigo);
        
        return [
            'token' => $token,
            'usuario' => [
                'id' => $aluno['id'],
                'tipo' => 'aluno',
                'nome' => $aluno['nome'],
                'codigo' => $aluno['codigo_acesso'],
                'turma_id' => $aluno['turma_id']
            ]
        ];
    }
    
    /**
     * Autenticar professor/admin por login e senha
     */
    public static function autenticarProfessor($login, $senha) {
        $db = db();
        
        $professor = $db->fetchOne(
            "SELECT * FROM professores WHERE login = :login AND ativo = 1",
            [':login' => $login]
        );
        
        if (!$professor) {
            return null;
        }
        
        // Verificar suspensão
        if ($professor['suspenso_ate'] && strtotime($professor['suspenso_ate']) > time()) {
            return ['erro' => 'conta_suspensa', 'ate' => $professor['suspenso_ate']];
        }
        
        if (!self::verifyPassword($senha, $professor['senha_hash'])) {
            // Registrar tentativa falha
            self::registrarAuditoria($professor['cargo'], $professor['id'], $professor['nome'], 'login_falha');
            return null;
        }
        
        // Atualizar último acesso
        $db->update('professores', 
            ['ultimo_acesso' => date('Y-m-d H:i:s')], 
            'id = :id', 
            [':id' => $professor['id']]
        );
        
        // Criar sessão
        $token = self::createSession($professor['cargo'], $professor['id']);
        
        // Registrar acesso
        self::registrarAuditoria($professor['cargo'], $professor['id'], $professor['nome'], 'login_sucesso');
        
        return [
            'token' => $token,
            'usuario' => [
                'id' => $professor['id'],
                'tipo' => $professor['cargo'],
                'nome' => $professor['nome'],
                'login' => $professor['login'],
                'email' => $professor['email'],
                'permissoes' => json_decode($professor['permissoes'], true)
            ]
        ];
    }
    
    /**
     * Criar sessão
     */
    public static function createSession($tipo, $id) {
        $db = db();
        $token = self::generateToken();
        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $db->insert('sessoes', [
            'usuario_tipo' => $tipo,
            'usuario_id' => $id,
            'session_token' => $token,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'expires_at' => $expires
        ]);
        
        return $token;
    }
    
    /**
     * Verificar sessão
     */
    public static function verificarSessao($token) {
        if (!$token) {
            return null;
        }
        
        $db = db();
        
        $sessao = $db->fetchOne(
            "SELECT * FROM sessoes WHERE session_token = :token AND expires_at > datetime('now')",
            [':token' => $token]
        );
        
        if (!$sessao) {
            return null;
        }
        
        // Renovar sessão se necessário
        $ultimaAtividade = strtotime($sessao['ultima_atividade']);
        if (time() - $ultimaAtividade > SESSION_REFRESH) {
            $db->update('sessoes', 
                [
                    'ultima_atividade' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', time() + SESSION_LIFETIME)
                ],
                'id = :id',
                [':id' => $sessao['id']]
            );
        }
        
        // Buscar dados do usuário
        $usuario = null;
        if ($sessao['usuario_tipo'] === 'aluno') {
            $usuario = $db->fetchOne("SELECT id, nome, codigo_acesso as codigo, turma_id FROM alunos WHERE id = :id", 
                [':id' => $sessao['usuario_id']]);
        } else {
            $usuario = $db->fetchOne("SELECT id, nome, login, email, cargo as tipo, permissoes FROM professores WHERE id = :id", 
                [':id' => $sessao['usuario_id']]);
            if ($usuario) {
                $usuario['permissoes'] = json_decode($usuario['permissoes'], true);
            }
        }
        
        if (!$usuario) {
            return null;
        }
        
        return [
            'sessao' => $sessao,
            'usuario' => array_merge($usuario, ['tipo' => $sessao['usuario_tipo']])
        ];
    }
    
    /**
     * Encerrar sessão
     */
    public static function logout($token) {
        $db = db();
        
        $sessao = $db->fetchOne("SELECT * FROM sessoes WHERE session_token = :token", [':token' => $token]);
        
        if ($sessao) {
            // Registrar logout
            self::registrarAuditoria($sessao['usuario_tipo'], $sessao['usuario_id'], null, 'logout');
            
            // Remover sessão
            $db->delete('sessoes', 'session_token = :token', [':token' => $token]);
        }
        
        return true;
    }
    
    /**
     * Verificar permissão
     */
    public static function temPermissao($usuario, $permissao) {
        if (!$usuario) {
            return false;
        }
        
        // Master tem todas as permissões
        if ($usuario['tipo'] === 'master') {
            return true;
        }
        
        // Verificar permissões específicas
        if (isset($usuario['permissoes'][$permissao])) {
            return $usuario['permissoes'][$permissao];
        }
        
        // Permissões por cargo
        $permissoesPorCargo = [
            'admin' => ['gerenciar_alunos', 'gerenciar_professores', 'ver_notas', 'gerenciar_financeiro', 'liberar_notas'],
            'secretaria' => ['gerenciar_alunos', 'gerenciar_financeiro'],
            'professor' => ['lancar_notas', 'ver_turmas'],
            'coordenador' => ['lancar_notas', 'ver_turmas', 'ver_notas_turma']
        ];
        
        if (isset($permissoesPorCargo[$usuario['tipo']])) {
            return in_array($permissao, $permissoesPorCargo[$usuario['tipo']]);
        }
        
        return false;
    }
    
    /**
     * Verificar se pode editar nota (regra das 48h)
     */
    public static function podeEditarNota($usuario, $nota) {
        if (!$usuario || !$nota) {
            return ['pode' => false, 'motivo' => 'Dados inválidos'];
        }
        
        // Master sempre pode editar
        if ($usuario['tipo'] === 'master') {
            return ['pode' => true, 'tipo' => 'master'];
        }
        
        // Admin pode editar notas bloqueadas
        if ($usuario['tipo'] === 'admin' && $nota['bloqueada']) {
            return ['pode' => true, 'tipo' => 'admin', 'requer_justificativa' => true];
        }
        
        // Professor só pode editar suas próprias notas
        if ($usuario['tipo'] === 'professor' && $nota['professor_id'] != $usuario['id']) {
            return ['pode' => false, 'motivo' => 'Nota de outro professor'];
        }
        
        // Verificar tempo desde o lançamento
        $dataLancamento = strtotime($nota['data_lancamento']);
        $agora = time();
        $horasDecorridas = ($agora - $dataLancamento) / 3600;
        
        if ($horasDecorridas <= HORAS_EDICAO_LIVRE) {
            // 0-24h: edição livre
            return ['pode' => true, 'tipo' => 'livre', 'horas_restantes' => HORAS_EDICAO_LIVRE - $horasDecorridas];
        } elseif ($horasDecorridas <= HORAS_EDICAO_JUSTIFICADA) {
            // 24-48h: edição com justificativa
            return [
                'pode' => true, 
                'tipo' => 'justificada', 
                'requer_justificativa' => true,
                'horas_restantes' => HORAS_EDICAO_JUSTIFICADA - $horasDecorridas
            ];
        } else {
            // 48h+: bloqueado para professor
            return ['pode' => false, 'motivo' => 'Prazo de edição expirado (48h)', 'solicitar_correcao' => true];
        }
    }
    
    /**
     * Registrar auditoria
     */
    public static function registrarAuditoria($usuarioTipo, $usuarioId, $usuarioNome, $acao, 
                                               $alvoTipo = null, $alvoId = null, $codigoAcesso = null, $detalhes = null) {
        try {
            $db = db();
            
            $db->insert('auditoria', [
                'usuario_tipo' => $usuarioTipo,
                'usuario_id' => $usuarioId,
                'usuario_nome' => $usuarioNome,
                'acao' => $acao,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'codigo_acesso_usado' => $codigoAcesso,
                'alvo_tipo' => $alvoTipo,
                'alvo_id' => $alvoId,
                'detalhes' => $detalhes ? json_encode($detalhes) : null
            ]);
        } catch (Exception $e) {
            error_log('Erro ao registrar auditoria: ' . $e->getMessage());
        }
    }
    
    /**
     * Limpar sessões expiradas
     */
    public static function limparSessoesExpiradas() {
        $db = db();
        $db->delete("sessoes", "expires_at < datetime('now')");
    }
}
