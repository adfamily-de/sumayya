<?php
/**
 * PdfHelper - Instituto Politécnico Sumayya
 * Geração de documentos PDF (usando HTML/CSS para impressão)
 */

require_once __DIR__ . '/../database.php';

class PdfHelper {
    
    /**
     * Template base para documentos
     */
    public static function getTemplateBase() {
        return '<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>{{titulo}} - Instituto Politécnico Sumayya</title>
    <link rel="stylesheet" href="/assets/css/reset.css">
    <link rel="stylesheet" href="/assets/css/variables.css">
    <link rel="stylesheet" href="/assets/css/print.css">
    <style>
        @page { size: A4; margin: 15mm; }
        body { font-family: Arial, sans-serif; font-size: 12pt; line-height: 1.4; }
        .cabecalho { text-align: center; border-bottom: 2pt solid #1a237e; padding-bottom: 15pt; margin-bottom: 20pt; }
        .cabecalho img { max-width: 80pt; margin-bottom: 10pt; }
        .cabecalho h1 { font-size: 16pt; color: #1a237e; margin-bottom: 5pt; }
        .cabecalho p { font-size: 9pt; color: #616161; margin: 2pt 0; }
        .documento h2 { font-size: 18pt; text-align: center; margin: 20pt 0; color: #1a237e; text-transform: uppercase; }
        .documento p { text-align: justify; margin-bottom: 10pt; text-indent: 30pt; }
        .info-box { border: 1pt solid #e0e0e0; padding: 15pt; margin: 15pt 0; background: #f5f5f5; }
        .info-row { display: flex; margin-bottom: 8pt; }
        .info-label { font-weight: bold; width: 150pt; color: #1a237e; }
        .assinaturas { display: flex; justify-content: space-between; margin-top: 40pt; }
        .assinatura { text-align: center; width: 45%; }
        .assinatura-line { border-top: 1pt solid #333; padding-top: 5pt; margin-top: 30pt; }
        .rodape { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8pt; color: #616161; border-top: 1pt solid #e0e0e0; padding-top: 10pt; }
        table { width: 100%; border-collapse: collapse; margin: 15pt 0; }
        th, td { border: 1pt solid #e0e0e0; padding: 8pt; text-align: left; }
        th { background: #1a237e; color: white; }
        tr:nth-child(even) { background: #f5f5f5; }
    </style>
</head>
<body>
    {{conteudo}}
</body>
</html>';
    }
    
    /**
     * Gerar cabeçalho padrão
     */
    public static function getCabecalho() {
        return '<div class="cabecalho">
    <img src="/assets/images/logo-sumayya.png" alt="Instituto Politécnico Sumayya">
    <h1>INSTITUTO POLITÉCNICO SUMAYYA</h1>
    <p>Avenida 25 de Setembro, Matola, Maputo, 1114</p>
    <p>Tel: 87 416 3000 | Email: secretaria@sumayya.edu.mz</p>
</div>';
    }
    
    /**
     * Gerar rodapé padrão
     */
    public static function getRodape($data = null) {
        if (!$data) {
            $data = date('d/m/Y');
        }
        return '<div class="rodape">
    <p>Documento gerado em ' . $data . ' - Instituto Politécnico Sumayya</p>
    <p>Este documento é válido somente com assinatura e carimbo da instituição</p>
</div>';
    }
    
    /**
     * Gerar declaração de matrícula
     */
    public static function gerarDeclaracaoMatricula($alunoId, $emitidoPor = null) {
        $db = db();
        
        $aluno = $db->fetchOne(
            "SELECT a.*, t.nome as turma_nome, t.ano_letivo 
             FROM alunos a 
             LEFT JOIN turmas t ON a.turma_id = t.id 
             WHERE a.id = :id",
            [':id' => $alunoId]
        );
        
        if (!$aluno) {
            return null;
        }
        
        $numero = 'DEC-' . date('Y') . '-' . str_pad($alunoId, 5, '0', STR_PAD_LEFT);
        $data = date('d/m/Y');
        
        $conteudo = self::getCabecalho();
        $conteudo .= '<div class="documento">
    <h2>DECLARAÇÃO DE MATRÍCULA</h2>
    <p style="text-align: right; font-size: 10pt;">Nº ' . $numero . '</p>
    
    <p>Declaramos para os devidos fins que <strong>' . htmlspecialchars($aluno['nome']) . '</strong>, 
    portador(a) do código de acesso <strong>' . $aluno['codigo_acesso'] . '</strong>, 
    está regularmente matriculado(a) no <strong>Instituto Politécnico Sumayya</strong>, 
    na turma <strong>' . htmlspecialchars($aluno['turma_nome']) . '</strong>, 
    no ano letivo de ' . $aluno['ano_letivo'] . '.</p>
    
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Aluno:</span>
            <span>' . htmlspecialchars($aluno['nome']) . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Código:</span>
            <span>' . $aluno['codigo_acesso'] . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Turma:</span>
            <span>' . htmlspecialchars($aluno['turma_nome']) . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Ano Letivo:</span>
            <span>' . $aluno['ano_letivo'] . '</span>
        </div>
    </div>
    
    <p>Esta declaração é válida por 30 (trinta) dias a partir da data de emissão.</p>
</div>

<div class="assinaturas">
    <div class="assinatura">
        <div class="assinatura-line">_______________________________</div>
        <p>Secretaria</p>
    </div>
    <div class="assinatura">
        <div class="assinatura-line">_______________________________</div>
        <p>Direção Pedagógica</p>
    </div>
</div>

<p style="text-align: center; margin-top: 30pt; font-size: 10pt;">
    Matola, ' . $data . '
</p>';
        
        $conteudo .= self::getRodape();
        
        // Registrar emissão
        if ($emitidoPor) {
            $db->insert('documentos_emitidos', [
                'tipo' => 'declaracao',
                'aluno_id' => $alunoId,
                'numero' => $numero,
                'conteudo' => $conteudo,
                'emitido_por' => $emitidoPor,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
        
        return [
            'numero' => $numero,
            'html' => str_replace('{{conteudo}}', $conteudo, self::getTemplateBase()),
            'titulo' => 'Declaração de Matrícula'
        ];
    }
    
    /**
     * Gerar boletim em PDF
     */
    public static function gerarBoletim($alunoId, $bimestre = null, $ano = null) {
        $db = db();
        
        if (!$ano) {
            $config = $db->getConfig();
            $ano = $config['ano_letivo_atual'];
        }
        if (!$bimestre) {
            $config = $db->getConfig();
            $bimestre = $config['bimestre_atual'];
        }
        
        $aluno = $db->fetchOne(
            "SELECT a.*, t.nome as turma_nome 
             FROM alunos a 
             LEFT JOIN turmas t ON a.turma_id = t.id 
             WHERE a.id = :id",
            [':id' => $alunoId]
        );
        
        if (!$aluno) {
            return null;
        }
        
        // Buscar notas do bimestre
        $notas = $db->fetchAll(
            "SELECT n.*, d.nome as disciplina_nome 
             FROM notas n 
             JOIN disciplinas d ON n.disciplina_id = d.id 
             WHERE n.aluno_id = :aluno_id AND n.bimestre = :bimestre AND n.ano_letivo = :ano
             ORDER BY d.nome",
            [':aluno_id' => $alunoId, ':bimestre' => $bimestre, ':ano' => $ano]
        );
        
        $data = date('d/m/Y');
        
        $tabelaNotas = '';
        $totalNotas = 0;
        $somaNotas = 0;
        
        foreach ($notas as $nota) {
            $classe = '';
            if ($nota['nota'] >= 10) {
                $classe = 'style="color: #4caf50; font-weight: bold;"';
            } elseif ($nota['nota'] >= 7) {
                $classe = 'style="color: #ff9800; font-weight: bold;"';
            } else {
                $classe = 'style="color: #f44336; font-weight: bold;"';
            }
            
            $tabelaNotas .= '<tr>
                <td>' . htmlspecialchars($nota['disciplina_nome']) . '</td>
                <td ' . $classe . '>' . number_format($nota['nota'], 1) . '</td>
                <td>' . $nota['faltas'] . '</td>
                <td>' . htmlspecialchars($nota['observacoes'] ?? '-') . '</td>
            </tr>';
            
            $somaNotas += $nota['nota'];
            $totalNotas++;
        }
        
        $media = $totalNotas > 0 ? $somaNotas / $totalNotas : 0;
        
        $conteudo = self::getCabecalho();
        $conteudo .= '<div class="documento">
    <h2>BOLETIM ESCOLAR</h2>
    
    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Aluno:</span>
            <span>' . htmlspecialchars($aluno['nome']) . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Turma:</span>
            <span>' . htmlspecialchars($aluno['turma_nome']) . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Ano Letivo:</span>
            <span>' . $ano . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Bimestre:</span>
            <span>' . $bimestre . 'º</span>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Disciplina</th>
                <th>Nota</th>
                <th>Faltas</th>
                <th>Observações</th>
            </tr>
        </thead>
        <tbody>
            ' . $tabelaNotas . '
        </tbody>
    </table>
    
    <div class="info-box" style="margin-top: 20pt;">
        <div class="info-row">
            <span class="info-label">Média Geral:</span>
            <span style="font-size: 14pt; font-weight: bold;">' . number_format($media, 1) . '</span>
        </div>
        <div class="info-row">
            <span class="info-label">Situação:</span>
            <span>' . ($media >= 10 ? '<span style="color: #4caf50;">APROVADO</span>' : '<span style="color: #f44336;">REPROVADO</span>') . '</span>
        </div>
    </div>
</div>

<div class="assinaturas">
    <div class="assinatura">
        <div class="assinatura-line">_______________________________</div>
        <p>Professor(a)</p>
    </div>
    <div class="assinatura">
        <div class="assinatura-line">_______________________________</div>
        <p>Responsável</p>
    </div>
</div>

<p style="text-align: center; margin-top: 30pt; font-size: 10pt;">
    Matola, ' . $data . '
</p>';
        
        $conteudo .= self::getRodape();
        
        return [
            'html' => str_replace('{{conteudo}}', $conteudo, self::getTemplateBase()),
            'titulo' => 'Boletim - ' . $bimestre . 'º Bimestre'
        ];
    }
    
    /**
     * Gerar certificado de conclusão
     */
    public static function gerarCertificado($alunoId, $curso, $serie, $ano, $emitidoPor = null) {
        $db = db();
        
        $aluno = $db->fetchOne("SELECT * FROM alunos WHERE id = :id", [':id' => $alunoId]);
        
        if (!$aluno) {
            return null;
        }
        
        $numero = 'CERT-' . date('Y') . '-' . str_pad($alunoId, 5, '0', STR_PAD_LEFT);
        $data = date('d/m/Y');
        
        $conteudo = self::getCabecalho();
        $conteudo .= '<div class="documento" style="text-align: center; padding: 40pt 20pt;">
    <h2 style="font-size: 24pt; margin-bottom: 40pt;">CERTIFICADO</h2>
    
    <p style="font-size: 14pt; text-indent: 0; margin-bottom: 30pt;">
        Certificamos que
    </p>
    
    <p style="font-size: 20pt; font-weight: bold; color: #1a237e; text-indent: 0; margin-bottom: 30pt;">
        ' . htmlspecialchars($aluno['nome']) . '
    </p>
    
    <p style="font-size: 14pt; text-indent: 0; line-height: 2;">
        concluiu com êxito o <strong>' . $serie . '</strong> do curso <strong>' . htmlspecialchars($curso) . '</strong> 
        no ano letivo de ' . $ano . ', no <strong>Instituto Politécnico Sumayya</strong>, 
        estando apto(a) a prosseguir os estudos.
    </p>
    
    <p style="text-align: center; margin-top: 60pt; font-size: 12pt;">
        Matola, ' . $data . '
    </p>
    
    <div style="margin-top: 80pt;">
        <div style="border-top: 1pt solid #333; display: inline-block; padding-top: 10pt; width: 300pt;">
            <p style="text-indent: 0; font-weight: bold;">Direção Pedagógica</p>
            <p style="text-indent: 0; font-size: 10pt;">Instituto Politécnico Sumayya</p>
        </div>
    </div>
</div>';
        
        $conteudo .= self::getRodape();
        
        // Registrar emissão
        if ($emitidoPor) {
            $db->insert('documentos_emitidos', [
                'tipo' => 'certificado',
                'aluno_id' => $alunoId,
                'numero' => $numero,
                'conteudo' => $conteudo,
                'emitido_por' => $emitidoPor,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
        
        return [
            'numero' => $numero,
            'html' => str_replace('{{conteudo}}', $conteudo, self::getTemplateBase()),
            'titulo' => 'Certificado de Conclusão'
        ];
    }
    
    /**
     * Salvar HTML como arquivo
     */
    public static function salvarHtml($html, $filename) {
        $path = UPLOAD_PATH . 'documentos/';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        $filepath = $path . $filename . '.html';
        file_put_contents($filepath, $html);
        
        return $filepath;
    }
}
