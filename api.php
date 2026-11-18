<?php
session_start();
header('Content-Type: application/json');

// Verificar se está logado
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=profinder;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    
    switch ($action) {
        
        // Obter ou criar conversa com outro utilizador
        case 'iniciar_conversa':
            $outroUserId = (int)($_POST['user_id'] ?? 0);
            
            if (!$outroUserId || $outroUserId === $userId) {
                echo json_encode(['success' => false, 'error' => 'ID de utilizador inválido']);
                exit;
            }
            
            // Verificar se já existe conversa (em qualquer ordem)
            $stmt = $pdo->prepare("
                SELECT id_con FROM conversas 
                WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
            ");
            $stmt->execute([$userId, $outroUserId, $outroUserId, $userId]);
            $conversa = $stmt->fetch();
            
            if ($conversa) {
                echo json_encode(['success' => true, 'conversa_id' => $conversa['id_con']]);
            } else {
                // Criar nova conversa
                $stmt = $pdo->prepare("INSERT INTO conversas (user1_id, user2_id) VALUES (?, ?)");
                $stmt->execute([$userId, $outroUserId]);
                echo json_encode(['success' => true, 'conversa_id' => $pdo->lastInsertId()]);
            }
            break;
            
        // Listar todas as conversas do utilizador (apenas as que têm mensagens)
        case 'listar_conversas':
            $stmt = $pdo->prepare("
                SELECT 
                    c.id_con as id,
                    c.ultima_mensagem,
                    CASE 
                        WHEN c.user1_id = ? THEN c.user2_id 
                        ELSE c.user1_id 
                    END as outro_user_id,
                    COALESCE(ep.Nome_Empresa, ec.Nome, u.email) as outro_nome,
                    u.Avatar as outro_avatar,
                    (SELECT mensagem FROM mensagens WHERE conversa_id = c.id_con ORDER BY enviado_em DESC LIMIT 1) as ultima_msg,
                    (SELECT COUNT(*) FROM mensagens WHERE conversa_id = c.id_con AND remetente_id != ? AND lida = 0) as nao_lidas
                FROM conversas c
                JOIN `user` u ON u.`id user` = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
                LEFT JOIN edit_prestadores ep ON ep.iduser = u.`id user`
                LEFT JOIN edit_clientes ec ON ec.iduser = u.`id user`
                WHERE (c.user1_id = ? OR c.user2_id = ?)
                AND EXISTS (SELECT 1 FROM mensagens WHERE conversa_id = c.id_con)
                ORDER BY c.ultima_mensagem DESC
            ");
            $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
            $conversas = $stmt->fetchAll();
            
            // Clean up pedido tags from sidebar previews
            foreach ($conversas as &$conv) {
                if ($conv['ultima_msg']) {
                    $conv['ultima_msg'] = preg_replace('/\[PEDIDO:\d+\]/', '', $conv['ultima_msg']);
                }
            }
            
            echo json_encode(['success' => true, 'conversas' => $conversas]);
            break;
            
        // Obter mensagens de uma conversa
        case 'obter_mensagens':
            $conversaId = (int)($_GET['conversa_id'] ?? 0);
            $ultimaId = (int)($_GET['ultima_id'] ?? 0);
            
            // Verificar se o utilizador faz parte da conversa
            $stmt = $pdo->prepare("SELECT id_con FROM conversas WHERE id_con = ? AND (user1_id = ? OR user2_id = ?)");
            $stmt->execute([$conversaId, $userId, $userId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Conversa não encontrada']);
                exit;
            }
            
            // Buscar mensagens
            if ($ultimaId > 0) {
                // Apenas novas mensagens
                $stmt = $pdo->prepare("
                    SELECT m.*, m.id_men as id,
                           m.remetente_id = ? as minha
                    FROM mensagens m
                    WHERE m.conversa_id = ? AND m.id_men > ?
                    ORDER BY m.enviado_em ASC
                ");
                $stmt->execute([$userId, $conversaId, $ultimaId]);
            } else {
                // Todas as mensagens
                $stmt = $pdo->prepare("
                    SELECT m.*, m.id_men as id,
                           m.remetente_id = ? as minha
                    FROM mensagens m
                    WHERE m.conversa_id = ?
                    ORDER BY m.enviado_em ASC
                ");
                $stmt->execute([$userId, $conversaId]);
            }
            $mensagens = $stmt->fetchAll();
            
            // Enrich with pedido info for action buttons
            foreach ($mensagens as &$msg) {
                if (preg_match('/\[PEDIDO:(\d+)\]/', $msg['mensagem'], $matches)) {
                    $pid = $matches[1];
                    $msg['pedido_id'] = $pid;
                    $msg['mensagem'] = trim(str_replace($matches[0], '', $msg['mensagem']));
                    
                    // Fetch estado
                    $stmtPed = $pdo->prepare("SELECT estado FROM pedidos WHERE id_ped = ?");
                    $stmtPed->execute([$pid]);
                    if ($ped = $stmtPed->fetch()) {
                        $msg['pedido_estado'] = $ped['estado'];
                    }
                } elseif (preg_match('/\[AGENDAMENTO:(\d+)\]/', $msg['mensagem'], $matches)) {
                    $aid = $matches[1];
                    $msg['agendamento_id'] = $aid;
                    $msg['mensagem'] = trim(str_replace($matches[0], '', $msg['mensagem']));
                    
                    // Fetch estado
                    $stmtAg = $pdo->prepare("SELECT status FROM agendamentos WHERE id = ?");
                    $stmtAg->execute([$aid]);
                    if ($ag = $stmtAg->fetch()) {
                        // Map agendamentos 'pendente'/'confirmado'/'cancelado'/'concluido' to 'pendente'/'aceite'/'rejeitado'/'concluido'
                        $mappedStatus = 'pendente';
                        if ($ag['status'] === 'confirmado') $mappedStatus = 'aceite';
                        if ($ag['status'] === 'cancelado') $mappedStatus = 'rejeitado';
                        if ($ag['status'] === 'concluido') $mappedStatus = 'concluido';
                        $msg['agendamento_estado'] = $mappedStatus;
                    }
                }
            }
            
            // Marcar mensagens do outro como lidas
            $stmtLer = $pdo->prepare("
                UPDATE mensagens SET lida = 1 
                WHERE conversa_id = ? AND remetente_id != ? AND lida = 0
            ");
            $stmtLer->execute([$conversaId, $userId]);
            
            echo json_encode(['success' => true, 'mensagens' => $mensagens]);
            break;
            
        // Enviar mensagem
        case 'enviar_mensagem':
            $conversaId = (int)($_POST['conversa_id'] ?? 0);
            $mensagem = trim($_POST['mensagem'] ?? '');
            
            if (!$conversaId || empty($mensagem)) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            
            // Verificar se o utilizador faz parte da conversa
            $stmt = $pdo->prepare("SELECT id_con, user1_id, user2_id FROM conversas WHERE id_con = ? AND (user1_id = ? OR user2_id = ?)");
            $stmt->execute([$conversaId, $userId, $userId]);
            $conversa = $stmt->fetch();
            if (!$conversa) {
                echo json_encode(['success' => false, 'error' => 'Conversa não encontrada']);
                exit;
            }
            
            // Inserir mensagem
            $stmt = $pdo->prepare("INSERT INTO mensagens (conversa_id, remetente_id, mensagem) VALUES (?, ?, ?)");
            $stmt->execute([$conversaId, $userId, $mensagem]);
            $mensagemId = $pdo->lastInsertId();
            
            // Atualizar última mensagem da conversa
            $stmt = $pdo->prepare("UPDATE conversas SET ultima_mensagem = NOW() WHERE id_con = ?");
            $stmt->execute([$conversaId]);
            
            // ============== NOTIFICAÇÃO POR EMAIL ============== //
            try {
                $receiverId = ($conversa['user1_id'] == $userId) ? $conversa['user2_id'] : $conversa['user1_id'];
                
                // Obter email do destinatário
                $stmtUser = $pdo->prepare("SELECT email FROM `user` WHERE `id user` = ?");
                $stmtUser->execute([$receiverId]);
                $receiver = $stmtUser->fetch();
                
                if ($receiver && !empty($receiver['email'])) {
                    // Obter nome do remetente
                    $stmtMyName = $pdo->prepare("
                        SELECT COALESCE(ep.Nome_Empresa, ec.Nome, u.email) as nome
                        FROM `user` u
                        LEFT JOIN edit_prestadores ep ON ep.iduser = u.`id user`
                        LEFT JOIN edit_clientes ec ON ec.iduser = u.`id user`
                        WHERE u.`id user` = ?
                    ");
                    $stmtMyName->execute([$userId]);
                    $myInfo = $stmtMyName->fetch();
                    $senderName = $myInfo ? $myInfo['nome'] : 'Um utilizador';
                    
                    // Incluir ficheiros de email caso não estejam incluídos
                    require_once '../auth/enviar_email.php';
                    require_once '../auth/smtp_email.php';
                    
                    // Link base para a aplicação
                    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $dominio = $_SERVER['HTTP_HOST'];
                    $baseDir = dirname(dirname($_SERVER['REQUEST_URI']));
                    if ($baseDir == '\\' || $baseDir == '/') $baseDir = '';
                    $linkChat = "{$protocolo}://{$dominio}{$baseDir}/chat/";
                    
                    if (preg_match('/\[PEDIDO:(\d+)\]/', $mensagem, $matches)) {
                        $pedidoId = $matches[1];
                        
                        // Obter os detalhes do pedido e do serviço
                        $stmtPed = $pdo->prepare("
                            SELECT p.descricao as pedido_desc, s.titulo as servico_titulo, p.estado
                            FROM pedidos p
                            LEFT JOIN servicos s ON p.servico_id = s.id_ser
                            WHERE p.id_ped = ?
                        ");
                        $stmtPed->execute([$pedidoId]);
                        $pedido = $stmtPed->fetch();
                        
                        $tituloServico = $pedido ? $pedido['servico_titulo'] : 'Serviço Solicitado';
                        $estado = $pedido ? ucfirst($pedido['estado']) : 'Pendente';
                        $mensagemLimpa = trim(preg_replace('/\[PEDIDO:\d+\]/', '', $mensagem));
                        
                        $assunto = "PROFINDER - Novo Pedido: " . $tituloServico;
                        
                        $htmlMensagem = "
                        <html>
                        <head>
                            <meta charset='UTF-8'>
                            <style>
                                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7fa; margin: 0; padding: 20px; }
                                .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
                                .header { background: linear-gradient(135deg, #2563eb, #1e40af); padding: 30px; text-align: center; }
                                .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 1px; }
                                .content { padding: 35px 30px; }
                                .welcome { font-size: 18px; color: #1e293b; margin-top: 0; margin-bottom: 25px; }
                                .card-pedido { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
                                .card-pedido h3 { color: #0f172a; margin-top: 0; margin-bottom: 15px; font-size: 18px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; }
                                .detail-row { margin-bottom: 10px; display: flex; flex-direction: column; }
                                .detail-label { font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 3px; }
                                .detail-value { font-size: 16px; color: #334155; }
                                .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; background: #fef08a; color: #854d0e; }
                                .msg-extra { font-style: italic; color: #475569; border-left: 3px solid #3b82f6; padding-left: 15px; margin: 20px 0; }
                                .btn-wrapper { text-align: center; margin: 35px 0 15px; }
                                .btn { display: inline-block; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; transition: background 0.2s; }
                                .btn:hover { background: #1d4ed8; }
                                .footer { background: #f1f5f9; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; }
                                .footer p { margin: 5px 0; color: #64748b; font-size: 13px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h1>PROFINDER</h1>
                                </div>
                                <div class='content'>
                                    <h2 class='welcome'>Recebeu um novo Pedido de Serviço!</h2>
                                    <p style='color: #475569; line-height: 1.6;'>O utilizador <strong>" . htmlspecialchars($senderName) . "</strong> solicitou os seus serviços e enviou o seguinte pedido:</p>
                                    
                                    <div class='card-pedido'>
                                        <h3>Detalhes do Pedido #" . $pedidoId . "</h3>
                                        
                                        <div class='detail-row'>
                                            <span class='detail-label'>Serviço</span>
                                            <span class='detail-value'><strong>" . htmlspecialchars($tituloServico) . "</strong></span>
                                        </div>
                                        
                                        <div class='detail-row' style='margin-top: 15px;'>
                                            <span class='detail-label'>Estado Actual</span>
                                            <span class='detail-value'><span class='badge'>" . htmlspecialchars($estado) . "</span></span>
                                        </div>
                                    </div>
                                    ";
                        
                        if (!empty($mensagemLimpa)) {
                            $htmlMensagem .= "
                                    <p style='color: #475569; font-weight: 600; margin-bottom: 5px;'>Mensagem adicional:</p>
                                    <div class='msg-extra'>
                                        \"" . nl2br(htmlspecialchars($mensagemLimpa)) . "\"
                                    </div>";
                        }
                        
                        $htmlMensagem .= "
                                    <div class='btn-wrapper'>
                                        <a href='{$linkChat}' class='btn'>Ver Pedido e Responder</a>
                                    </div>
                                </div>
                                <div class='footer'>
                                    <p>Será notificado caso o estado deste pedido seja alterado.</p>
                                    <p>© " . date('Y') . " PROFINDER - Todos os direitos reservados</p>
                                </div>
                            </div>
                        </body>
                        </html>";
                    } else {
                        // Mensagem normal do chat
                        $assunto = "Nova mensagem no PROFINDER de $senderName";
                        
                        $mensagemLimpa = trim($mensagem);
                        
                        $htmlMensagem = "
                        <html>
                        <head>
                            <meta charset='UTF-8'>
                            <style>
                                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
                                .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
                                .header { color: #1f4aa0; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 25px; font-size: 20px; }
                                .msg-box { background: #f8f9fa; padding: 20px; border-left: 4px solid #1f4aa0; border-radius: 6px; margin: 25px 0; font-size: 16px; color: #333; line-height: 1.5; }
                                .btn-wrapper { text-align: center; margin-top: 25px; }
                                .btn { display: inline-block; background: #1f4aa0; color: #ffffff !important; text-decoration: none; padding: 12px 25px; border-radius: 6px; font-weight: 600; font-size: 15px; }
                                .footer { opacity: 0.7; font-size: 13px; text-align: center; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <strong>PROFINDER</strong>
                                </div>
                                <p>Olá!</p>
                                <p>Recebeste uma nova mensagem de <strong>" . htmlspecialchars($senderName) . "</strong> no chat da plataforma:</p>
                                
                                <div class='msg-box'>
                                    " . nl2br(htmlspecialchars($mensagemLimpa)) . "
                                </div>
                                
                                <div class='btn-wrapper'>
                                    <a href='{$linkChat}' class='btn'>Responder à mensagem</a>
                                </div>
                                
                                <div class='footer'>
                                    <p>Por favor não respondas diretamente a este email.</p>
                                    <p>© " . date('Y') . " PROFINDER</p>
                                </div>
                            </div>
                        </body>
                        </html>";
                    }
                    
                    enviarEmailSMTP($receiver['email'], $assunto, $htmlMensagem);
                }
            } catch (Exception $e) {
                // Ignorar erros de envio de email para não falhar o envio da mensagem no chat
            }
            // ==================================================== //
            
            echo json_encode([
                'success' => true, 
                'mensagem_id' => $mensagemId,
                'enviado_em' => date('Y-m-d H:i:s')
            ]);
            break;
            
        // Contar mensagens não lidas
        case 'contar_nao_lidas':
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM mensagens m
                JOIN conversas c ON c.id_con = m.conversa_id
                WHERE (c.user1_id = ? OR c.user2_id = ?)
                AND m.remetente_id != ?
                AND m.lida = 0
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $result = $stmt->fetch();
            
            echo json_encode(['success' => true, 'nao_lidas' => (int)$result['total']]);
            break;
            
        // Obter info do outro utilizador na conversa
        case 'info_conversa':
            $conversaId = (int)($_GET['conversa_id'] ?? 0);
            
            $stmt = $pdo->prepare("
                SELECT 
                    CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END as outro_user_id,
                    COALESCE(ep.Nome_Empresa, ec.Nome, u.email) as nome,
                    u.Avatar as avatar,
                    u.`tipo conta` as tipo
                FROM conversas c
                JOIN `user` u ON u.`id user` = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END
                LEFT JOIN edit_prestadores ep ON ep.iduser = u.`id user`
                LEFT JOIN edit_clientes ec ON ec.iduser = u.`id user`
                WHERE c.id_con = ? AND (c.user1_id = ? OR c.user2_id = ?)
            ");
            $stmt->execute([$userId, $userId, $conversaId, $userId, $userId]);
            $info = $stmt->fetch();
            
            if ($info) {
                echo json_encode(['success' => true, 'info' => $info]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Conversa não encontrada']);
            }
            break;
        
        // Editar mensagem
        case 'editar_mensagem':
            $mensagemId = (int)($_POST['mensagem_id'] ?? 0);
            $novaMensagem = trim($_POST['mensagem'] ?? '');
            
            if (!$mensagemId || empty($novaMensagem)) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            
            // Verificar se a mensagem pertence ao utilizador
            $stmt = $pdo->prepare("SELECT id_men FROM mensagens WHERE id_men = ? AND remetente_id = ?");
            $stmt->execute([$mensagemId, $userId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Mensagem não encontrada ou sem permissão']);
                exit;
            }
            
            // Atualizar mensagem
            $stmt = $pdo->prepare("UPDATE mensagens SET mensagem = ?, editado = 1 WHERE id_men = ?");
            $stmt->execute([$novaMensagem, $mensagemId]);
            
            echo json_encode(['success' => true]);
            break;
        
        // Apagar mensagem
        case 'apagar_mensagem':
            $mensagemId = (int)($_POST['mensagem_id'] ?? 0);
            
            if (!$mensagemId) {
                echo json_encode(['success' => false, 'error' => 'ID da mensagem inválido']);
                exit;
            }
            
            // Verificar se a mensagem pertence ao utilizador
            $stmt = $pdo->prepare("SELECT id_men FROM mensagens WHERE id_men = ? AND remetente_id = ?");
            $stmt->execute([$mensagemId, $userId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Mensagem não encontrada ou sem permissão']);
                exit;
            }
            
            // Apagar mensagem
            $stmt = $pdo->prepare("DELETE FROM mensagens WHERE id_men = ?");
            $stmt->execute([$mensagemId]);
            
            echo json_encode(['success' => true]);
            break;
        
        // Apagar conversa toda
        case 'apagar_conversa':
            $conversaId = (int)($_POST['conversa_id'] ?? 0);
            
            if (!$conversaId) {
                echo json_encode(['success' => false, 'error' => 'ID da conversa inválido']);
                exit;
            }
            
            // Verificar se o utilizador faz parte da conversa
            $stmt = $pdo->prepare("SELECT id_con FROM conversas WHERE id_con = ? AND (user1_id = ? OR user2_id = ?)");
            $stmt->execute([$conversaId, $userId, $userId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Conversa não encontrada ou sem permissão']);
                exit;
            }
            
            // Apagar todas as mensagens da conversa
            $stmt = $pdo->prepare("DELETE FROM mensagens WHERE conversa_id = ?");
            $stmt->execute([$conversaId]);
            
            // Apagar a conversa
            $stmt = $pdo->prepare("DELETE FROM conversas WHERE id_con = ?");
            $stmt->execute([$conversaId]);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'responder_pedido':
            $pedidoId = (int)($_POST['pedido_id'] ?? 0);
            $acaoPed = $_POST['acao_pedido'] ?? ''; // 'aceitar' ou 'rejeitar'
            $msgAdicional = trim($_POST['mensagem'] ?? '');
            
            if (!$pedidoId || !in_array($acaoPed, ['aceitar', 'rejeitar'])) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            
            // Buscar pedido
            $stmtPed = $pdo->prepare("SELECT * FROM pedidos WHERE id_ped = ?");
            $stmtPed->execute([$pedidoId]);
            $pedido = $stmtPed->fetch();
            
            if (!$pedido) {
                echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
                exit;
            }
            
            // Verificar se o utilizador é o prestador deste pedido
            $stmtVerifica = $pdo->prepare("SELECT idpres FROM edit_prestadores WHERE iduser = ?");
            $stmtVerifica->execute([$userId]);
            $pres = $stmtVerifica->fetch();
            
            if (!$pres || $pedido['id_prestador'] != $pres['idpres']) {
                echo json_encode(['success' => false, 'error' => 'Sem permissão para alterar este pedido.']);
                exit;
            }
            
            if ($pedido['estado'] !== 'pendente') {
                echo json_encode(['success' => false, 'error' => 'O pedido já foi respondido.']);
                exit;
            }
            
            // Atualizar estado
            $novoEstado = ($acaoPed === 'aceitar') ? 'aceite' : 'rejeitado';
            $stmtUpd = $pdo->prepare("UPDATE pedidos SET estado = ? WHERE id_ped = ?");
            $stmtUpd->execute([$novoEstado, $pedidoId]);
            
            // Descargar conversa para enviar msg automática
            $stmtCli = $pdo->prepare("SELECT iduser FROM edit_clientes WHERE idcli = ?");
            $stmtCli->execute([$pedido['id_cliente']]);
            $cliResult = $stmtCli->fetch();
            $clienteUserId = $cliResult ? $cliResult['iduser'] : 0;
            
            if ($clienteUserId) {
                $stmtCon = $pdo->prepare("
                    SELECT id_con FROM conversas 
                    WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
                ");
                $stmtCon->execute([$userId, $clienteUserId, $clienteUserId, $userId]);
                $conversa = $stmtCon->fetch();
                $conversaId = $conversa ? $conversa['id_con'] : 0;
                
                if ($conversaId) {
                    $ico = $novoEstado === 'aceite' ? '✅' : '❌';
                    $sysMsg = $ico . " *O pedido de serviço foi " . strtoupper($novoEstado) . "*\n";
                    if ($msgAdicional !== '') {
                        $sysMsg .= "\n💬 *Mensagem do prestador:*\n" . $msgAdicional;
                    }
                    
                    $stmtInsMsg = $pdo->prepare("INSERT INTO mensagens (conversa_id, remetente_id, mensagem) VALUES (?, ?, ?)");
                    $stmtInsMsg->execute([$conversaId, $userId, $sysMsg]);
                    
                    $stmtUpdConv = $pdo->prepare("UPDATE conversas SET ultima_mensagem = NOW() WHERE id_con = ?");
                    $stmtUpdConv->execute([$conversaId]);
                }
                
                // ============== NOTIFICAÇÃO POR EMAIL DO ESTADO ============== //
                try {
                    $stmtUserCli = $pdo->prepare("SELECT email FROM `user` WHERE `id user` = ?");
                    $stmtUserCli->execute([$clienteUserId]);
                    $receiver = $stmtUserCli->fetch();
                    
                    if ($receiver && !empty($receiver['email'])) {
                        // Obter nome do prestador
                        $stmtMyName = $pdo->prepare("SELECT Nome_Empresa FROM edit_prestadores WHERE iduser = ?");
                        $stmtMyName->execute([$userId]);
                        $myInfo = $stmtMyName->fetch();
                        $senderName = $myInfo ? $myInfo['Nome_Empresa'] : 'O Prestador';
                        
                        // Detalhes do serviço
                        $stmtSer = $pdo->prepare("SELECT s.TipoSer FROM servicos s JOIN preser ps ON ps.CodSer = s.CodSer WHERE ps.CodPS = ?");
                        $stmtSer->execute([$pedido['codps']]);
                        $serInfo = $stmtSer->fetch();
                        $nomeServico = $serInfo ? $serInfo['TipoSer'] : 'Serviço';
                        
                        require_once '../auth/enviar_email.php';
                        require_once '../auth/smtp_email.php';
                        
                        $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                        $dominio = $_SERVER['HTTP_HOST'];
                        $baseDir = dirname(dirname($_SERVER['REQUEST_URI']));
                        if ($baseDir == '\\' || $baseDir == '/') $baseDir = '';
                        $linkChat = "{$protocolo}://{$dominio}{$baseDir}/chat/";
                        
                        $estadoFormatado = $novoEstado === 'aceite' ? 'ACEITE ✅' : 'REJEITADO ❌';
                        $corEstado = $novoEstado === 'aceite' ? '#059669' : '#dc2626'; // verde ou vermelho
                        $bgEstado = $novoEstado === 'aceite' ? '#d1fae5' : '#fee2e2';
                        
                        $assunto = "PROFINDER - Pedido " . ucfirst($novoEstado) . ": " . $nomeServico;
                        
                        $htmlMensagem = "
                        <html>
                        <head>
                            <meta charset='UTF-8'>
                            <style>
                                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7fa; margin: 0; padding: 20px; }
                                .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
                                .header { background: linear-gradient(135deg, #2563eb, #1e40af); padding: 30px; text-align: center; }
                                .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 1px; }
                                .content { padding: 35px 30px; }
                                .welcome { font-size: 18px; color: #1e293b; margin-top: 0; margin-bottom: 25px; text-align: center; }
                                .card-estado { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px; text-align: center; }
                                .servico-nome { font-size: 20px; font-weight: 600; color: #334155; margin-bottom: 15px; }
                                .badge-estado { display: inline-block; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 700; background: {$bgEstado}; color: {$corEstado}; }
                                .msg-extra { font-style: italic; color: #475569; border-left: 3px solid {$corEstado}; padding-left: 15px; margin: 20px 0; text-align: left;}
                                .btn-wrapper { text-align: center; margin: 35px 0 15px; }
                                .btn { display: inline-block; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; transition: background 0.2s; }
                                .btn:hover { background: #1d4ed8; }
                                .footer { background: #f1f5f9; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; }
                                .footer p { margin: 5px 0; color: #64748b; font-size: 13px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h1>PROFINDER</h1>
                                </div>
                                <div class='content'>
                                    <h2 class='welcome'>Atualização do seu Pedido #" . $pedidoId . "</h2>
                                    <p style='color: #475569; line-height: 1.6; text-align: center;'>O prestador <strong>" . htmlspecialchars($senderName) . "</strong> respondeu ao seu pedido.</p>
                                    
                                    <div class='card-estado'>
                                        <div class='servico-nome'>" . htmlspecialchars($nomeServico) . "</div>
                                        <div class='badge-estado'>" . $estadoFormatado . "</div>
                                    </div>
                                    ";
                        
                        if (!empty($msgAdicional)) {
                            $htmlMensagem .= "
                                    <p style='color: #475569; font-weight: 600; margin-bottom: 5px;'>Mensagem do Prestador:</p>
                                    <div class='msg-extra'>
                                        \"" . nl2br(htmlspecialchars($msgAdicional)) . "\"
                                    </div>";
                        }
                        
                        $htmlMensagem .= "
                                    <div class='btn-wrapper'>
                                        <a href='{$linkChat}' class='btn'>Ver no Chat</a>
                                    </div>
                                </div>
                                <div class='footer'>
                                    <p>Pode continuar a conversar com o prestador através do chat da plataforma.</p>
                                    <p>© " . date('Y') . " PROFINDER - Todos os direitos reservados</p>
                                </div>
                            </div>
                        </body>
                        </html>";
                        
                        enviarEmailSMTP($receiver['email'], $assunto, $htmlMensagem);
                    }
                } catch (Exception $e) {}
                // ========================================================== //
            }
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro na base de dados: ' . $e->getMessage()]);
}
