<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
@set_time_limit(30);

$tenant    = require_tenant();
$tenant_id = (int) $tenant['id'];

// ── Actions (AJAX) ──────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!csrf_check()) {
        echo json_encode(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }
    $action = $_POST['action'];

    if ($action === 'move') {
        $cid = (int) ($_POST['card_id'] ?? 0);
        $col = (int) ($_POST['column_id'] ?? 0);
        $pos = (int) ($_POST['position'] ?? 0);
        if ($cid && $col) {
            crm_move_card($cid, $col, $pos);
            echo json_encode(['ok' => true]);
        } else echo json_encode(['error' => 'missing params']);
        exit;
    }

    if ($action === 'add_from_cnpj') {
        $col = (int) ($_POST['column_id'] ?? 0);
        if (!$col) { $cols = crm_ensure_columns($tenant_id); $col = (int) $cols[0]['id']; }
        $data = json_decode($_POST['data'] ?? '{}', true) ?: [];

        // Consome crédito se o CNPJ ainda não foi visto este mês (evita bypass do drawer)
        $cnpj_raw = preg_replace('/\D/', '', $data['cnpj'] ?? '');
        $credit_consumed = false;
        if (strlen($cnpj_raw) === 14) {
            $already = (bool) db_val(
                "SELECT 1 FROM cnpj_download_log
                 WHERE tenant_id = ?
                   AND filters_json LIKE ?
                   AND YEAR(downloaded_at)  = YEAR(NOW())
                   AND MONTH(downloaded_at) = MONTH(NOW())
                 LIMIT 1",
                [$tenant_id, '%"_view":"' . $cnpj_raw . '"%']
            );
            if (!$already) {
                $limit_total = cnpj_monthly_limit($tenant_id);
                $used_now    = cnpj_monthly_used($tenant_id);
                $addon       = (int) db_val('SELECT cnpj_addon_credits FROM tenants WHERE id = ?', [$tenant_id]);
                $available   = max(0, $limit_total - $used_now) + $addon;
                if ($available <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'quota_exceeded', 'used' => $used_now, 'limit' => $limit_total]);
                    exit;
                }
                cnpj_quota_log($tenant_id, 1, ['_view' => $cnpj_raw, '_src' => 'pipeline']);
                $credit_consumed = true;
            }
        }

        $id = crm_add_card($tenant_id, $col, $data);
        crm_log_history($id, 'created', 'Adicionado do Radar');
        echo json_encode(['ok' => true, 'card_id' => $id, 'credit_consumed' => $credit_consumed]);
        exit;
    }

    if ($action === 'add_bulk') {
        $col = (int) ($_POST['column_id'] ?? 0);
        if (!$col) { $cols = crm_ensure_columns($tenant_id); $col = (int) $cols[0]['id']; }
        $items = json_decode($_POST['items'] ?? '[]', true) ?: [];
        $added = 0; $credits = 0;
        foreach ($items as $it) {
            // Consome crédito por item novo não visto este mês
            $cnpj_raw = preg_replace('/\D/', '', $it['cnpj'] ?? '');
            if (strlen($cnpj_raw) === 14) {
                $already = (bool) db_val(
                    "SELECT 1 FROM cnpj_download_log WHERE tenant_id=? AND filters_json LIKE ?
                     AND YEAR(downloaded_at)=YEAR(NOW()) AND MONTH(downloaded_at)=MONTH(NOW()) LIMIT 1",
                    [$tenant_id, '%"_view":"' . $cnpj_raw . '"%']
                );
                if (!$already) {
                    $avail = max(0, cnpj_monthly_limit($tenant_id) - cnpj_monthly_used($tenant_id))
                           + (int) db_val('SELECT cnpj_addon_credits FROM tenants WHERE id=?', [$tenant_id]);
                    if ($avail <= 0) break; // para de adicionar se cota esgotou
                    cnpj_quota_log($tenant_id, 1, ['_view' => $cnpj_raw, '_src' => 'pipeline_bulk']);
                    $credits++;
                }
            }
            $id = crm_add_card($tenant_id, $col, $it);
            crm_log_history($id, 'created', 'Bulk add do Radar');
            $added++;
        }
        echo json_encode(['ok' => true, 'added' => $added, 'credits_consumed' => $credits]);
        exit;
    }

    // EDIÇÃO COMPLETA — atualiza todos os campos editáveis do card
    if ($action === 'update_card') {
        $cid = (int) ($_POST['card_id'] ?? 0);
        if (!$cid) { echo json_encode(['error' => 'missing card']); exit; }
        $fields = [
            'razao_social'  => $_POST['razao_social']  ?? null,
            'nome_fantasia' => $_POST['nome_fantasia'] ?? null,
            'telefone'      => $_POST['telefone']      ?? null,
            'email'         => $_POST['email']         ?? null,
            'cidade_uf'     => $_POST['cidade_uf']     ?? null,
            'notes'         => $_POST['notes']         ?? null,
            'product_name'  => $_POST['product_name']  ?? null,
            'due_date'      => ($_POST['due_date'] ?? '') ?: null,
        ];
        db_q(
            'UPDATE crm_cards SET razao_social=?, nome_fantasia=?, telefone=?, email=?, cidade_uf=?, notes=?, product_name=?, due_date=?, last_action=NOW()
             WHERE id=? AND tenant_id=?',
            [...array_values($fields), $cid, $tenant_id]
        );
        // Tags (opcional — se enviou o campo, atualiza)
        if (isset($_POST['tag_ids'])) {
            $tagIds = array_filter(array_map('intval', explode(',', $_POST['tag_ids'])));
            crm_card_set_tags($cid, $tagIds);
        }
        // Responsável (opcional)
        if (array_key_exists('assigned_user_id', $_POST)) {
            $uid = $_POST['assigned_user_id'];
            $uid = ($uid === '' || $uid === '0') ? null : (int)$uid;
            crm_card_assign($tenant_id, $cid, $uid);
        }
        crm_log_history($cid, 'updated', 'Card editado');
        echo json_encode(['ok' => true]);
        exit;
    }

    // === v2: TAGS ===
    if ($action === 'tag_list') {
        echo json_encode(['ok' => true, 'tags' => crm_tags_list($tenant_id)]);
        exit;
    }
    if ($action === 'tag_create') {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#10b981';
        if ($name === '') { echo json_encode(['error' => 'name required']); exit; }
        $id = crm_tag_create($tenant_id, $name, $color);
        echo json_encode(['ok' => true, 'id' => $id, 'name' => $name, 'color' => $color]);
        exit;
    }
    if ($action === 'tag_delete') {
        $tid = (int) ($_POST['tag_id'] ?? 0);
        if (!$tid) { echo json_encode(['error' => 'missing tag']); exit; }
        crm_tag_delete($tenant_id, $tid);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'card_tags') {
        $cid = (int) ($_POST['card_id'] ?? 0);
        if (!$cid) { echo json_encode(['error' => 'missing card']); exit; }
        $owns = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
        if (!$owns) { echo json_encode(['error' => 'not found']); exit; }
        echo json_encode(['ok' => true, 'tags' => crm_card_tags($cid)]);
        exit;
    }

    // === v2: COMENTÁRIOS ===
    if ($action === 'comment_list') {
        $cid = (int) ($_POST['card_id'] ?? 0);
        if (!$cid) { echo json_encode(['error' => 'missing card']); exit; }
        $owns = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
        if (!$owns) { echo json_encode(['error' => 'not found']); exit; }
        echo json_encode(['ok' => true, 'comments' => crm_comments_list($cid)]);
        exit;
    }
    if ($action === 'comment_add') {
        $cid  = (int) ($_POST['card_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if (!$cid || $body === '') { echo json_encode(['error' => 'missing fields']); exit; }
        $owns = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
        if (!$owns) { echo json_encode(['error' => 'not found']); exit; }
        $uid = (int) auth_user_id();
        $id  = crm_comment_add($cid, $uid, $body);
        crm_log_history($cid, 'comment', mb_substr($body, 0, 120));
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }
    if ($action === 'comment_delete') {
        $coid = (int) ($_POST['comment_id'] ?? 0);
        $uid  = (int) auth_user_id();
        if (!$coid) { echo json_encode(['error' => 'missing comment']); exit; }
        crm_comment_delete($coid, $uid);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'note') {
        $cid  = (int) ($_POST['card_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($cid) {
            db_q('UPDATE crm_cards SET notes = ?, last_action = NOW() WHERE id = ? AND tenant_id = ?', [$note, $cid, $tenant_id]);
            crm_log_history($cid, 'note', mb_substr($note, 0, 200));
            echo json_encode(['ok' => true]);
        } else echo json_encode(['error' => 'missing card']);
        exit;
    }

    if ($action === 'delete') {
        $cid = (int) ($_POST['card_id'] ?? 0);
        if ($cid) {
            db_q('DELETE FROM crm_cards WHERE id = ? AND tenant_id = ?', [$cid, $tenant_id]);
            echo json_encode(['ok' => true]);
        } else echo json_encode(['error' => 'missing card']);
        exit;
    }

    // HISTÓRICO — busca log de ações do card
    if ($action === 'history') {
        $cid = (int) ($_POST['card_id'] ?? 0);
        if (!$cid) { echo json_encode(['error' => 'missing card']); exit; }
        // Garante que card pertence ao tenant (segurança)
        $owns = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
        if (!$owns) { echo json_encode(['error' => 'not found']); exit; }
        $rows = db_all('SELECT action, detail, created_at FROM crm_card_history WHERE card_id=? ORDER BY created_at DESC LIMIT 50', [$cid]);
        echo json_encode(['ok' => true, 'history' => $rows]);
        exit;
    }

    // COLUMNS CRUD
    if ($action === 'col_add') {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#94a3b8';
        if ($name === '') { echo json_encode(['error' => 'name required']); exit; }
        $pos = (int) db_val('SELECT COALESCE(MAX(position),0)+1 FROM crm_columns WHERE tenant_id=?', [$tenant_id]);
        $id = db_insert('crm_columns', ['tenant_id' => $tenant_id, 'name' => $name, 'color' => $color, 'position' => $pos]);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'col_update') {
        $cid   = (int) ($_POST['col_id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? null;
        if (!$cid || $name === '') { echo json_encode(['error' => 'missing fields']); exit; }
        db_q('UPDATE crm_columns SET name=?, color=? WHERE id=? AND tenant_id=?', [$name, $color, $cid, $tenant_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'col_delete') {
        $cid = (int) ($_POST['col_id'] ?? 0);
        if (!$cid) { echo json_encode(['error' => 'missing col']); exit; }
        // Conta quantos cards na coluna
        $count = (int) db_val('SELECT COUNT(*) FROM crm_cards WHERE column_id=? AND tenant_id=?', [$cid, $tenant_id]);
        if ($count > 0) { echo json_encode(['error' => 'has_cards', 'count' => $count]); exit; }
        db_q('DELETE FROM crm_columns WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Bulk actions ─────────────────────────────────────────────────────────
    if ($action === 'bulk_move') {
        $col_id   = (int) ($_POST['column_id'] ?? 0);
        $card_ids = array_filter(array_map('intval', explode(',', $_POST['card_ids'] ?? '')));
        if (!$col_id || !$card_ids) { echo json_encode(['error' => 'missing params']); exit; }
        // Verifica que coluna pertence ao tenant
        $col_ok = db_val('SELECT 1 FROM crm_columns WHERE id=? AND tenant_id=?', [$col_id, $tenant_id]);
        if (!$col_ok) { echo json_encode(['error' => 'col not found']); exit; }
        $moved = 0;
        foreach ($card_ids as $cid) {
            $ok = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
            if ($ok) {
                db_q('UPDATE crm_cards SET column_id=?, updated_at=NOW() WHERE id=? AND tenant_id=?', [$col_id, $cid, $tenant_id]);
                crm_log_history($cid, 'moved', 'Movido em lote');
                $moved++;
            }
        }
        echo json_encode(['ok' => true, 'moved' => $moved]);
        exit;
    }

    if ($action === 'bulk_archive') {
        $card_ids = array_filter(array_map('intval', explode(',', $_POST['card_ids'] ?? '')));
        if (!$card_ids) { echo json_encode(['error' => 'missing card_ids']); exit; }
        $archived = 0;
        foreach ($card_ids as $cid) {
            $ok = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
            if ($ok) {
                db_q('UPDATE crm_cards SET archived_at=NOW() WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
                crm_log_history($cid, 'archived', 'Arquivado em lote');
                $archived++;
            }
        }
        echo json_encode(['ok' => true, 'archived' => $archived]);
        exit;
    }

    if ($action === 'bulk_delete') {
        $card_ids = array_filter(array_map('intval', explode(',', $_POST['card_ids'] ?? '')));
        if (!$card_ids) { echo json_encode(['error' => 'missing card_ids']); exit; }
        $deleted = 0;
        foreach ($card_ids as $cid) {
            $ok = db_val('SELECT 1 FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
            if ($ok) {
                db_q('DELETE FROM crm_card_tags WHERE card_id=?', [$cid]);
                db_q('DELETE FROM crm_card_history WHERE card_id=?', [$cid]);
                db_q('DELETE FROM crm_comments WHERE card_id=?', [$cid]);
                db_q('DELETE FROM crm_cards WHERE id=? AND tenant_id=?', [$cid, $tenant_id]);
                $deleted++;
            }
        }
        echo json_encode(['ok' => true, 'deleted' => $deleted]);
        exit;
    }

    echo json_encode(['error' => 'unknown action']);
    exit;
}

$columns      = crm_ensure_columns($tenant_id);
$cards_by_col = [];
$all_card_ids = [];
foreach ($columns as $c) {
    $rows = crm_cards_by_column($tenant_id, (int)$c['id']);
    $cards_by_col[$c['id']] = $rows;
    foreach ($rows as $r) $all_card_ids[] = (int)$r['id'];
}
$stats        = crm_stats($tenant_id);
$tags_all     = crm_tags_list($tenant_id);
$users_all    = crm_tenant_users($tenant_id);
$tags_by_card = crm_tags_by_cards($all_card_ids); // [card_id => [tags]]
$pipe_prefs   = user_prefs_with_defaults((int) auth_user_id());

// Helper de cor de score (alinhado com cnpj_qualify.php)
function crm_score_tier(int $s): array {
    if ($s >= 70) return ['cls' => 'hot',  'label' => '🔥 Quente'];
    if ($s >= 50) return ['cls' => 'warm', 'label' => '⭐ Bom'];
    if ($s >= 25) return ['cls' => 'cool', 'label' => '🌱 Médio'];
    return                ['cls' => 'cold', 'label' => '❄ Frio'];
}

app_layout('Pipeline', 'crm', function() use ($columns, $cards_by_col, $stats, $tags_all, $users_all, $tags_by_card, $pipe_prefs) {
?>
<script>
window.PIPE_PREFS = {
  showProduct:  <?= ($pipe_prefs['pipeline_show_value']    ?? '1') !== '0' ? 'true' : 'false' ?>,
  showDue:      <?= ($pipe_prefs['pipeline_show_due_date'] ?? '1') !== '0' ? 'true' : 'false' ?>,
  productLabel: <?= json_encode($pipe_prefs['pipeline_product_label'] ?? '') ?>,
  cardColor:    <?= json_encode($pipe_prefs['pipeline_card_color']    ?? 'score') ?>,
};
</script>
<style>
  /* === Pipeline (HERMES) — cor #059669 (deeper emerald) === */
  :root { --pipeline: #059669; --pipeline-soft: rgba(5, 150, 105, .08); }

  .pipe-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px; flex-wrap:wrap; }
  .pipe-head h2 { font-size:1.15rem; font-weight:700; margin:0; display:flex; align-items:center; gap:8px; }
  .pipe-head h2 .badge { font-family:'Geist Mono',monospace; font-size:.58rem; font-weight:600; background:var(--pipeline); color:#fff; padding:3px 8px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; }
  .pipe-head .stats { font-size:.82rem; color:var(--mute); margin:4px 0 0; }
  .pipe-head .stats b { color:var(--ink); }
  .pipe-head .actions { display:flex; gap:8px; flex-wrap:wrap; }
  .pipe-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 14px; border-radius:8px; font-size:.85rem; font-weight:500; text-decoration:none; cursor:pointer; border:none; transition:all .15s; font-family:inherit; }
  .pipe-btn-primary { background:var(--pipeline); color:#fff; }
  .pipe-btn-primary:hover { background:#047857; }
  .pipe-btn-ghost { background:#fff; color:var(--ink-2); border:1px solid var(--line); }
  .pipe-btn-ghost:hover { background:var(--bone); border-color:var(--mute); color:var(--ink); }

  /* === Filtros === */
  .pipe-filters { display:flex; gap:10px; align-items:center; background:#fff; border:1px solid var(--line); border-radius:10px; padding:10px 12px; margin-bottom:14px; flex-wrap:wrap; }
  .pipe-filters input[type=text], .pipe-filters select { border:1px solid var(--line); border-radius:6px; padding:7px 10px; font-size:.85rem; font-family:inherit; background:#fff; color:var(--ink); }
  .pipe-filters input[type=text]:focus, .pipe-filters select:focus { outline:none; border-color:var(--pipeline); box-shadow:0 0 0 3px var(--pipeline-soft); }
  .pipe-filters input[type=text] { width:240px; }
  .pipe-filters label { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); text-transform:uppercase; letter-spacing:.06em; font-weight:600; margin-right:2px; }
  .pipe-filters .clear { font-size:.78rem; color:var(--mute); cursor:pointer; padding:4px 8px; border:none; background:transparent; }
  .pipe-filters .clear:hover { color:var(--coral); }

  /* === Kanban === */
  .kanban { display:flex; gap:14px; overflow-x:auto; padding-bottom:14px; }
  .kanban-col { background:#f4f3ef; border-radius:12px; padding:12px; min-width:300px; max-width:320px; flex-shrink:0; display:flex; flex-direction:column; max-height:calc(100vh - 240px); }
  .kanban-col.drag-over { background:var(--pipeline-soft); outline:2px dashed var(--pipeline); outline-offset:-4px; }
  .kanban-col-head { display:flex; align-items:center; gap:8px; margin-bottom:10px; padding:0 4px; }
  .kanban-col-head .dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .kanban-col-head h3 { margin:0; font-size:.88rem; font-weight:600; flex:1; cursor:pointer; }
  .kanban-col-head h3:hover { color:var(--pipeline); }
  .kanban-col-head .count { background:#fff; color:var(--mute); font-family:'Geist Mono',monospace; font-size:.7rem; padding:2px 9px; border-radius:99px; font-weight:600; }
  .kanban-col-head .col-menu { background:transparent; border:none; cursor:pointer; opacity:0; transition:opacity .15s; color:var(--mute); padding:2px 5px; border-radius:4px; }
  .kanban-col:hover .col-menu { opacity:.7; }
  .kanban-col-head .col-menu:hover { opacity:1; background:#fff; color:var(--ink); }
  .kanban-body { display:flex; flex-direction:column; gap:8px; flex:1; min-height:120px; overflow-y:auto; padding-right:2px; }
  .kanban-body::-webkit-scrollbar { width:5px; }
  .kanban-body::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:99px; }

  /* === Card estilo Bitrix24 === */
  .kanban-card { background:#fff; border-radius:10px; padding:12px; box-shadow:0 1px 3px rgba(0,0,0,.05); cursor:grab; transition:all .15s; position:relative; border:1px solid transparent; }
  .kanban-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.08); transform:translateY(-1px); border-color:var(--line); }
  .kanban-card:active { cursor:grabbing; }
  .kanban-card.dragging { opacity:.35; transform:rotate(2deg); }
  .kanban-card .top-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:6px; }
  .kanban-card .name { font-weight:600; font-size:.88rem; color:var(--ink); line-height:1.3; word-break:break-word; flex:1; min-width:0; }
  .kanban-card .score-chip { flex-shrink:0; font-family:'Geist Mono',monospace; font-size:.66rem; font-weight:700; padding:2px 7px; border-radius:99px; white-space:nowrap; }
  .kanban-card .score-chip.hot  { background:#fee2e2; color:#b91c1c; }
  .kanban-card .score-chip.warm { background:#fef3c7; color:#92400e; }
  .kanban-card .score-chip.cool { background:#dbeafe; color:#1e40af; }
  .kanban-card .score-chip.cold { background:#f3f4f6; color:#6b7280; }
  .kanban-card .cnpj { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); margin-bottom:6px; }
  .kanban-card .meta { font-size:.74rem; color:var(--ink-2); display:flex; flex-direction:column; gap:3px; }
  .kanban-card .meta .row { display:flex; align-items:center; gap:5px; }
  .kanban-card .meta .row svg { width:11px; height:11px; flex-shrink:0; color:var(--mute); }
  .kanban-card .notes-snip { background:var(--bone); border-left:2px solid var(--pipeline); padding:5px 8px; border-radius:4px; font-size:.72rem; color:var(--ink-2); margin-top:7px; font-style:italic; line-height:1.35; max-height:42px; overflow:hidden; }
  .kanban-card .actions { display:flex; gap:5px; margin-top:9px; padding-top:8px; border-top:1px solid var(--line); opacity:0; transition:opacity .15s; }
  .kanban-card:hover .actions { opacity:1; }
  .kanban-card .actions button, .kanban-card .actions a { background:var(--bone); border:none; padding:5px 9px; font-size:.7rem; border-radius:5px; cursor:pointer; color:var(--ink-2); text-decoration:none; font-family:inherit; transition:all .12s; display:inline-flex; align-items:center; gap:3px; }
  .kanban-card .actions button:hover, .kanban-card .actions a:hover { background:var(--pipeline); color:#fff; }
  .kanban-card .actions .danger:hover { background:var(--coral); }
  /* Botão de e-mail (Mail Lab) — teal próprio */
  .kanban-card .actions button[onclick*="openMailCompose"]:hover { background:#0d9488; }
  /* Botão de WhatsApp — verde nativo */
  .kanban-card .actions a[href*="wa.me"]:hover { background:#16a34a; }
  .kanban-empty { text-align:center; color:var(--mute); font-size:.78rem; padding:24px 8px; border:1px dashed var(--line); border-radius:8px; }
  .kanban-card.hidden-by-filter { display:none; }

  /* === Bulk selection mode === */
  .kanban-card .bulk-cb { display:none; position:absolute; top:10px; right:10px; z-index:10; }
  .kanban-card .bulk-cb input { width:17px; height:17px; cursor:pointer; accent-color:var(--pipeline); }
  body.bulk-mode .kanban-card .bulk-cb { display:block; }
  body.bulk-mode .kanban-card { cursor:pointer; }
  body.bulk-mode .kanban-card:hover { border-color:var(--pipeline); }
  body.bulk-mode .kanban-card.selected { border-color:var(--pipeline); background:var(--pipeline-soft); }
  body.bulk-mode .kanban-card .actions { display:none !important; } /* esconde ações individuais em bulk mode */
  body.bulk-mode .kanban-card[draggable] { cursor:pointer; }

  /* Floating bulk action bar */
  .bulk-bar { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(120%); background:#0f172a; color:#fff; border-radius:14px; padding:12px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 8px 30px rgba(0,0,0,.3); z-index:500; transition:transform .25s cubic-bezier(.34,1.56,.64,1); min-width:380px; flex-wrap:wrap; }
  .bulk-bar.show { transform:translateX(-50%) translateY(0); }
  .bulk-bar .bulk-count { font-family:'Geist Mono',monospace; font-size:.78rem; background:var(--pipeline); color:#fff; padding:4px 10px; border-radius:6px; font-weight:700; white-space:nowrap; }
  .bulk-bar .bulk-actions { display:flex; gap:8px; flex:1; flex-wrap:wrap; }
  .bulk-bar button, .bulk-bar select { background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.15); padding:7px 12px; border-radius:7px; font-size:.82rem; font-weight:600; cursor:pointer; font-family:inherit; transition:all .15s; white-space:nowrap; }
  .bulk-bar button:hover { background:rgba(255,255,255,.22); }
  .bulk-bar select { background:rgba(255,255,255,.1); min-width:150px; }
  .bulk-bar select option { color:#0f172a; background:#fff; }
  .bulk-bar .bulk-danger:hover { background:rgba(220,38,38,.6) !important; border-color:rgba(220,38,38,.4) !important; }
  .bulk-bar .bulk-cancel { background:transparent; border-color:rgba(255,255,255,.3); font-size:.78rem; }

  /* === Add new column button === */
  .kanban-add-col { min-width:60px; max-width:60px; flex-shrink:0; background:transparent; border:2px dashed var(--line); border-radius:12px; cursor:pointer; color:var(--mute); display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:300; transition:all .15s; }
  .kanban-add-col:hover { border-color:var(--pipeline); color:var(--pipeline); background:var(--pipeline-soft); }

  /* === Edit Modal === */
  .em-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:300; align-items:center; justify-content:center; padding:20px; }
  .em-bg.open { display:flex; }
  .em-modal { background:#fff; border-radius:14px; max-width:680px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 50px rgba(0,0,0,.2); overflow:hidden; }
  .em-head { padding:18px 22px; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; }
  .em-head h3 { margin:0; font-size:1.05rem; font-weight:700; }
  .em-head .em-close { background:transparent; border:none; cursor:pointer; font-size:1.3rem; color:var(--mute); padding:0 4px; }
  .em-tabs { display:flex; gap:0; border-bottom:1px solid var(--line); padding:0 22px; background:var(--bone); }
  .em-tab { background:transparent; border:none; padding:10px 14px; font-size:.82rem; font-weight:500; color:var(--mute); cursor:pointer; border-bottom:2px solid transparent; font-family:inherit; transition:all .15s; }
  .em-tab:hover { color:var(--ink); }
  .em-tab.active { color:var(--pipeline); border-bottom-color:var(--pipeline); font-weight:600; }
  .em-body { padding:20px 22px; overflow-y:auto; flex:1; }
  .em-pane { display:none; }
  .em-pane.active { display:block; }
  .em-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .em-field { display:flex; flex-direction:column; gap:4px; }
  .em-field.full { grid-column:1 / -1; }
  .em-field label { font-family:'Geist Mono',monospace; font-size:.66rem; text-transform:uppercase; letter-spacing:.06em; color:var(--mute); font-weight:600; }
  .em-field input, .em-field textarea { border:1px solid var(--line); border-radius:7px; padding:9px 12px; font-size:.88rem; font-family:inherit; color:var(--ink); background:#fff; }
  .em-field input:focus, .em-field textarea:focus { outline:none; border-color:var(--pipeline); box-shadow:0 0 0 3px var(--pipeline-soft); }
  .em-field textarea { min-height:90px; resize:vertical; line-height:1.5; }
  .em-foot { padding:14px 22px; border-top:1px solid var(--line); display:flex; gap:8px; justify-content:flex-end; background:var(--bone); }

  /* História do card */
  .hist-list { display:flex; flex-direction:column; gap:10px; }
  .hist-item { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid var(--line); }
  .hist-item:last-child { border-bottom:none; }
  .hist-dot { width:8px; height:8px; border-radius:50%; background:var(--pipeline); margin-top:6px; flex-shrink:0; }
  .hist-content { flex:1; }
  .hist-action { font-family:'Geist Mono',monospace; font-size:.66rem; text-transform:uppercase; letter-spacing:.06em; color:var(--pipeline); font-weight:600; }
  .hist-detail { font-size:.85rem; color:var(--ink); margin-top:2px; word-break:break-word; }
  .hist-time { font-family:'Geist Mono',monospace; font-size:.7rem; color:var(--mute); margin-top:2px; }

  /* Col edit modal */
  .col-edit-bg { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:300; align-items:center; justify-content:center; }
  .col-edit-bg.open { display:flex; }
  .col-edit { background:#fff; border-radius:12px; padding:22px; max-width:380px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,.2); }
  .col-edit h3 { margin:0 0 14px; font-size:1rem; }
  .col-edit-row { display:flex; gap:8px; align-items:center; margin-bottom:12px; }
  .col-edit input[type=text] { flex:1; padding:8px 11px; border:1px solid var(--line); border-radius:7px; font-size:.88rem; font-family:inherit; }
  .col-edit input[type=color] { width:42px; height:38px; border:1px solid var(--line); border-radius:7px; cursor:pointer; padding:2px; background:#fff; }
  .col-edit .actions { display:flex; gap:8px; justify-content:space-between; margin-top:16px; }
  .col-edit .actions .left { display:flex; gap:6px; }
  .col-edit .actions .right { display:flex; gap:6px; }
  .col-edit button { padding:8px 14px; border-radius:7px; border:none; cursor:pointer; font-weight:500; font-size:.85rem; font-family:inherit; }
  .col-edit .btn-save { background:var(--pipeline); color:#fff; }
  .col-edit .btn-cancel { background:var(--bone); color:var(--ink-2); }
  .col-edit .btn-del { background:#fef2f2; color:var(--coral); border:1px solid #fecaca; }

  /* === Tags === */
  .tag-chip { display:inline-flex; align-items:center; gap:4px; font-family:'Geist Mono',monospace; font-size:.62rem; font-weight:600; padding:2px 7px; border-radius:99px; letter-spacing:.02em; white-space:nowrap; }
  .kanban-card .card-tags { display:flex; gap:4px; flex-wrap:wrap; margin-top:7px; }
  .kanban-card .card-tags .tag-chip { padding:2px 6px; font-size:.6rem; }

  /* Avatar do responsável no card */
  .assign-avatar { width:22px; height:22px; border-radius:50%; background:var(--pipeline); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-family:'Geist Mono',monospace; font-size:.62rem; font-weight:700; flex-shrink:0; text-transform:uppercase; }
  .kanban-card .top-row .assign-avatar { margin-left:4px; }

  /* === Tags input no modal (combobox) === */
  .em-tags-box { display:flex; flex-wrap:wrap; gap:5px; padding:8px 10px; border:1px solid var(--line); border-radius:7px; background:#fff; min-height:42px; align-items:center; }
  .em-tags-box .tag-chip { padding:3px 8px; font-size:.7rem; }
  .em-tags-box .tag-chip .x { cursor:pointer; opacity:.6; font-weight:700; }
  .em-tags-box .tag-chip .x:hover { opacity:1; }
  .em-tags-box input { border:none; outline:none; flex:1; min-width:140px; font-size:.85rem; font-family:inherit; padding:4px; }
  .em-tags-suggestions { position:relative; }
  .em-tags-pop { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--line); border-radius:7px; box-shadow:0 4px 14px rgba(0,0,0,.08); margin-top:4px; max-height:200px; overflow-y:auto; z-index:10; display:none; }
  .em-tags-pop.open { display:block; }
  .em-tags-pop-item { padding:7px 12px; cursor:pointer; font-size:.85rem; display:flex; align-items:center; gap:8px; }
  .em-tags-pop-item:hover { background:var(--bone); }
  .em-tags-pop-item.create { font-style:italic; color:var(--mute); }
  .em-tags-pop-item .swatch { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

  /* === Comentários === */
  .cmt-list { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; max-height:340px; overflow-y:auto; padding-right:4px; }
  .cmt-item { background:var(--bone); border-radius:8px; padding:10px 12px; position:relative; }
  .cmt-head { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
  .cmt-head .avatar { width:24px; height:24px; border-radius:50%; background:var(--pipeline); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Geist Mono',monospace; font-size:.62rem; font-weight:700; flex-shrink:0; text-transform:uppercase; }
  .cmt-head .name { font-weight:600; font-size:.82rem; color:var(--ink); }
  .cmt-head .time { font-family:'Geist Mono',monospace; font-size:.66rem; color:var(--mute); margin-left:auto; }
  .cmt-head .del  { background:transparent; border:none; color:var(--mute); cursor:pointer; font-size:.85rem; padding:0 4px; opacity:0; transition:opacity .15s; }
  .cmt-item:hover .del { opacity:.6; }
  .cmt-head .del:hover { color:var(--coral); opacity:1; }
  .cmt-body { font-size:.86rem; color:var(--ink); line-height:1.5; word-break:break-word; white-space:pre-wrap; }
  .cmt-empty { text-align:center; color:var(--mute); padding:24px; font-size:.85rem; }
  .cmt-form { display:flex; gap:8px; align-items:flex-end; padding-top:12px; border-top:1px solid var(--line); }
  .cmt-form textarea { flex:1; border:1px solid var(--line); border-radius:7px; padding:9px 11px; font-size:.86rem; font-family:inherit; min-height:60px; resize:vertical; }
  .cmt-form textarea:focus { outline:none; border-color:var(--pipeline); box-shadow:0 0 0 3px var(--pipeline-soft); }

  @media (max-width: 740px) {
    .em-grid { grid-template-columns:1fr; }
    .kanban-col { min-width:280px; }
  }
</style>

<div class="pipe-head">
  <div>
    <h2>📋 Pipeline <span class="badge">HERMES</span></h2>
    <p class="stats">
      <b><?= number_format($stats['total_cards'], 0, ',', '.') ?></b> leads no funil ·
      <b><?= number_format($stats['from_cnpj'], 0, ',', '.') ?></b> do Radar
    </p>
  </div>
  <div class="actions">
    <a href="cnpj.php" class="pipe-btn pipe-btn-ghost">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      Buscar mais leads
    </a>
    <button type="button" class="pipe-btn pipe-btn-primary" onclick="openColEdit(0)">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nova coluna
    </button>
  </div>
</div>

<!-- FILTROS -->
<div class="pipe-filters">
  <label>Filtros</label>
  <input type="text" id="filter-q" placeholder="Buscar por nome, CNPJ, email…" oninput="applyFilters()">
  <select id="filter-score" onchange="applyFilters()">
    <option value="">Score: qualquer</option>
    <option value="70">🔥 Quentes (≥70)</option>
    <option value="50">⭐ Bom+ (≥50)</option>
    <option value="25">🌱 Médio+ (≥25)</option>
  </select>
  <select id="filter-has" onchange="applyFilters()">
    <option value="">Contato: qualquer</option>
    <option value="email">Com e-mail</option>
    <option value="tel">Com telefone</option>
    <option value="both">Com e-mail e telefone</option>
  </select>
  <select id="filter-tag" onchange="applyFilters()">
    <option value="">Tag: qualquer</option>
    <?php foreach ($tags_all as $t): ?>
      <option value="<?= (int)$t['id'] ?>" style="color:<?= e($t['color']) ?>">● <?= e($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (count($users_all) > 1): ?>
  <select id="filter-assigned" onchange="applyFilters()">
    <option value="">Responsável: qualquer</option>
    <option value="0">Sem responsável</option>
    <?php foreach ($users_all as $u): ?>
      <option value="<?= (int)$u['id'] ?>"><?= e(explode(' ', $u['name'])[0]) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button type="button" class="clear" onclick="clearFilters()">× Limpar</button>
  <span id="filter-count" style="margin-left:auto;font-size:.78rem;color:var(--mute);font-family:'Geist Mono',monospace"></span>
  <button type="button" id="bulk-toggle" class="pipe-btn pipe-btn-ghost" onclick="toggleBulkMode()" style="gap:6px;font-size:.82rem;padding:7px 12px">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><rect x="3" y="5" width="4" height="4" rx="1"/><rect x="3" y="11" width="4" height="4" rx="1"/><rect x="3" y="17" width="4" height="4" rx="1"/><line x1="10" y1="7" x2="21" y2="7"/><line x1="10" y1="13" x2="21" y2="13"/><line x1="10" y1="19" x2="21" y2="19"/></svg>
    Selecionar
  </button>
</div>

<div class="kanban" id="kanban">
  <?php foreach ($columns as $col): $cards = $cards_by_col[$col['id']] ?? []; ?>
  <div class="kanban-col" data-col-id="<?= $col['id'] ?>" data-col-name="<?= e($col['name']) ?>" data-col-color="<?= e($col['color']) ?>"
       ondragover="onDragOver(event, this)" ondragleave="onDragLeave(event, this)" ondrop="onDrop(event, this)">
    <div class="kanban-col-head">
      <span class="dot" style="background:<?= e($col['color']) ?>"></span>
      <h3 onclick="openColEdit(<?= $col['id'] ?>)" title="Editar coluna"><?= e($col['name']) ?></h3>
      <span class="count"><?= count($cards) ?></span>
      <button class="col-menu" onclick="openColEdit(<?= $col['id'] ?>)" title="Editar coluna">⋯</button>
    </div>
    <div class="kanban-body" id="col-<?= $col['id'] ?>">
      <?php if (empty($cards)): ?>
        <div class="kanban-empty">Solte cards aqui</div>
      <?php endif; ?>
      <?php foreach ($cards as $card):
        $score = (int) ($card['score'] ?? 0);
        $tier  = crm_score_tier($score);
        $tel_digits = $card['telefone'] ? preg_replace('/\D/', '', '55' . $card['telefone']) : '';
        $cnpj_fmt   = $card['cnpj'] ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $card['cnpj']) : '';
        // v2: tags + responsável
        $card_tags  = $tags_by_card[$card['id']] ?? [];
        $card['_tags']    = $card_tags;
        $card['_tag_ids'] = array_column($card_tags, 'id');
        $card_json  = htmlspecialchars(json_encode($card, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $assign_init = $card['assigned_user_name'] ? mb_substr($card['assigned_user_name'], 0, 1) : '';
        // Para filtro: tag ids agregados + assigned user id
        $tag_ids_str = implode(',', $card['_tag_ids']);
      ?>
      <div class="kanban-card" draggable="true" data-card-id="<?= $card['id'] ?>"
           data-search="<?= e(mb_strtolower(($card['nome_fantasia'] ?? '') . ' ' . ($card['razao_social'] ?? '') . ' ' . ($card['cnpj'] ?? '') . ' ' . ($card['email'] ?? ''))) ?>"
           data-score="<?= $score ?>"
           data-has-email="<?= !empty($card['email']) ? '1' : '0' ?>"
           data-has-tel="<?= !empty($card['telefone']) ? '1' : '0' ?>"
           data-tags="<?= e($tag_ids_str) ?>"
           data-assigned="<?= (int)($card['assigned_user_id'] ?? 0) ?>"
           ondragstart="onDragStart(event, this)" ondragend="onDragEnd(event, this)"
           onclick="bulkCardClick(event, this) || cardSingleClick(event, this, <?= $card_json ?>)">
        <div class="bulk-cb"><input type="checkbox" onchange="bulkCbChange(event, this)" onclick="e => e.stopPropagation()"></div>
        <div class="top-row">
          <div class="name"><?= e($card['nome_fantasia'] ?: $card['razao_social']) ?></div>
          <?php if ($score > 0): ?><span class="score-chip <?= $tier['cls'] ?>"><?= $score ?></span><?php endif; ?>
          <?php if ($assign_init): ?>
            <span class="assign-avatar" title="Responsável: <?= e($card['assigned_user_name']) ?>"><?= e($assign_init) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($cnpj_fmt): ?><div class="cnpj"><?= e($cnpj_fmt) ?></div><?php endif; ?>
        <div class="meta">
          <?php if ($card['cidade_uf']): ?>
            <div class="row"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg><?= e($card['cidade_uf']) ?></div>
          <?php endif; ?>
          <?php if ($card['telefone']): ?>
            <div class="row"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg><?= e($card['telefone']) ?></div>
          <?php endif; ?>
          <?php if ($card['email']): ?>
            <div class="row"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><?= e(mb_strimwidth($card['email'], 0, 28, '…')) ?></div>
          <?php endif; ?>
        </div>
        <?php if (!empty($card_tags)): ?>
          <div class="card-tags">
            <?php foreach ($card_tags as $t): ?>
              <span class="tag-chip" style="background:<?= e($t['color']) ?>22;color:<?= e($t['color']) ?>"><?= e($t['name']) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($card['notes'])): ?>
          <div class="notes-snip" title="<?= e($card['notes']) ?>"><?= e(mb_strimwidth($card['notes'], 0, 80, '…')) ?></div>
        <?php endif; ?>
        <div class="actions">
          <button type="button" onclick='openEdit(<?= $card_json ?>)'>👁 Ver</button>
          <?php if (!empty($card['email'])): ?>
            <button type="button" onclick='openMailCompose({to:<?= json_encode($card['email']) ?>, name:<?= json_encode($card['nome_fantasia'] ?: $card['razao_social']) ?>})'>✉ E-mail</button>
          <?php endif; ?>
          <?php if ($tel_digits): ?>
            <a href="https://wa.me/<?= e($tel_digits) ?>" target="_blank">💬 WhatsApp</a>
          <?php endif; ?>
          <button type="button" class="danger" onclick="deleteCard(<?= $card['id'] ?>)">🗑</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <!-- Botão de nova coluna no final -->
  <button class="kanban-add-col" onclick="openColEdit(0)" title="Adicionar coluna">+</button>
</div>

<!-- ── Bulk action floating bar ── -->
<div class="bulk-bar" id="bulk-bar">
  <span class="bulk-count" id="bulk-count">0 cards</span>
  <div class="bulk-actions">
    <select id="bulk-col-select" title="Mover para coluna">
      <option value="">↳ Mover para...</option>
      <?php foreach ($columns as $col): ?>
        <option value="<?= $col['id'] ?>"><?= e($col['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button onclick="bulkMove()" title="Mover selecionados para a coluna escolhida">Mover →</button>
    <button onclick="bulkArchive()" title="Arquivar selecionados">📁 Arquivar</button>
    <button onclick="bulkDelete()" class="bulk-danger" title="Deletar permanentemente">🗑 Deletar</button>
  </div>
  <button class="bulk-cancel" onclick="toggleBulkMode(false)">× Cancelar</button>
</div>

<!-- ── Modal de edição completa do card ── -->
<div class="em-bg" id="em-bg">
  <div class="em-modal">
    <div class="em-head">
      <h3 id="em-title">Editar card</h3>
      <button class="em-close" onclick="closeEdit()">×</button>
    </div>
    <div class="em-tabs">
      <button class="em-tab active" data-pane="info" onclick="switchPane('info')">Informações</button>
      <button class="em-tab" data-pane="notes" onclick="switchPane('notes')">Anotações</button>
      <button class="em-tab" data-pane="comments" onclick="switchPane('comments')">Comentários</button>
      <button class="em-tab" data-pane="history" onclick="switchPane('history')">Histórico</button>
    </div>
    <div class="em-body">
      <input type="hidden" id="em-card-id">
      <!-- Pane: Informações -->
      <div class="em-pane active" data-pane="info">
        <div class="em-grid">
          <div class="em-field full">
            <label>Nome fantasia</label>
            <input type="text" id="em-nome-fantasia">
          </div>
          <div class="em-field full">
            <label>Razão social</label>
            <input type="text" id="em-razao-social">
          </div>
          <div class="em-field">
            <label>Telefone</label>
            <input type="text" id="em-telefone" placeholder="(11) 9XXXX-XXXX">
          </div>
          <div class="em-field">
            <label>Email</label>
            <input type="email" id="em-email">
          </div>
          <div class="em-field full">
            <label>Cidade/UF</label>
            <input type="text" id="em-cidade-uf">
          </div>
          <div class="em-field full" id="em-cnpj-row" style="display:none">
            <label>CNPJ</label>
            <input type="text" id="em-cnpj" readonly style="background:var(--bone)">
          </div>
          <?php if (!empty($users_all)): ?>
          <div class="em-field full">
            <label>Responsável</label>
            <select id="em-assigned" style="border:1px solid var(--line);border-radius:7px;padding:9px 12px;font-size:.88rem;font-family:inherit;background:#fff;">
              <option value="">— Sem responsável —</option>
              <?php foreach ($users_all as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> <small style="color:var(--mute)">· <?= e($u['email']) ?></small></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <!-- Produto e Prazo (visíveis conforme preferências do usuário) -->
          <div class="em-field" id="em-product-row">
            <label id="em-product-label">Produto / Serviço</label>
            <input type="text" id="em-product-name" placeholder="Ex: Consultoria mensal, SaaS Pro…" maxlength="120">
          </div>
          <div class="em-field" id="em-due-row">
            <label>Prazo / Fechamento</label>
            <input type="date" id="em-due-date">
          </div>
          <div class="em-field full">
            <label>Tags</label>
            <div class="em-tags-suggestions">
              <div class="em-tags-box" id="em-tags-box">
                <!-- chips dinâmicos via JS -->
                <input type="text" id="em-tag-input" placeholder="Digite para adicionar ou criar tag…" autocomplete="off">
              </div>
              <div class="em-tags-pop" id="em-tags-pop"></div>
            </div>
          </div>
        </div>
      </div>
      <!-- Pane: Anotações -->
      <div class="em-pane" data-pane="notes">
        <div class="em-field full">
          <label>Notas internas</label>
          <textarea id="em-notes" placeholder="Última conversa, próximo passo, observações…" style="min-height:200px"></textarea>
        </div>
      </div>
      <!-- Pane: Comentários -->
      <div class="em-pane" data-pane="comments">
        <div class="cmt-list" id="em-comments">
          <div class="cmt-empty">Carregando…</div>
        </div>
        <div class="cmt-form">
          <textarea id="cmt-input" placeholder="Adicione um comentário… (Ctrl+Enter envia)"></textarea>
          <button type="button" class="pipe-btn pipe-btn-primary" onclick="addComment()">Enviar</button>
        </div>
      </div>
      <!-- Pane: Histórico -->
      <div class="em-pane" data-pane="history">
        <div class="hist-list" id="em-history">
          <div style="text-align:center;color:var(--mute);padding:20px;font-size:.85rem">Carregando…</div>
        </div>
      </div>
    </div>
    <div class="em-foot">
      <button class="pipe-btn pipe-btn-ghost" onclick="closeEdit()">Cancelar</button>
      <button class="pipe-btn pipe-btn-primary" onclick="saveEdit()">Salvar alterações</button>
    </div>
  </div>
</div>

<!-- ── Modal de coluna (add/edit/del) ── -->
<div class="col-edit-bg" id="col-edit-bg">
  <div class="col-edit">
    <h3 id="col-edit-title">Nova coluna</h3>
    <div class="col-edit-row">
      <input type="text" id="col-edit-name" placeholder="Nome da coluna (ex: Em negociação)">
      <input type="color" id="col-edit-color" value="#10b981">
    </div>
    <div class="actions">
      <div class="left">
        <button type="button" class="btn-del" id="col-edit-del" onclick="deleteColumn()" style="display:none">🗑 Excluir</button>
      </div>
      <div class="right">
        <button type="button" class="btn-cancel" onclick="closeColEdit()">Cancelar</button>
        <button type="button" class="btn-save" onclick="saveColumn()">Salvar</button>
      </div>
    </div>
  </div>
</div>

<script>
// CSRF — token injetado uma vez, incluído em todos os POST para crm.php
const _crmCsrf = <?= json_encode(csrf_token()) ?>;
function crmPost(fd) {
    fd.append('_csrf', _crmCsrf);
    return fetch('crm.php', { method: 'POST', body: fd });
}

// ────────────────────────────────────────────────────────────────────────────
// Drag & Drop
// ────────────────────────────────────────────────────────────────────────────
let _draggedCard = null;

function onDragStart(e, el) { _draggedCard = el; el.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
function onDragEnd(e, el)   { el.classList.remove('dragging'); document.querySelectorAll('.kanban-col').forEach(c => c.classList.remove('drag-over')); }
function onDragOver(e, col) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; col.classList.add('drag-over'); }
function onDragLeave(e, col){ col.classList.remove('drag-over'); }
function onDrop(e, col) {
    e.preventDefault();
    col.classList.remove('drag-over');
    if (!_draggedCard) return;
    const cardId = _draggedCard.dataset.cardId;
    const colId  = col.dataset.colId;
    col.querySelector('.kanban-body').appendChild(_draggedCard);
    col.querySelector('.kanban-empty')?.remove();
    const fd = new FormData();
    fd.append('action', 'move');
    fd.append('card_id', cardId);
    fd.append('column_id', colId);
    crmPost(fd).then(r => r.json()).then(() => updateCounts());
    _draggedCard = null;
}
function updateCounts() {
    document.querySelectorAll('.kanban-col').forEach(col => {
        col.querySelector('.count').textContent = col.querySelectorAll('.kanban-card:not(.hidden-by-filter)').length;
    });
}

// ────────────────────────────────────────────────────────────────────────────
// Filtros (busca + score + contato)
// ────────────────────────────────────────────────────────────────────────────
function applyFilters() {
    const q    = document.getElementById('filter-q').value.trim().toLowerCase();
    const scr  = parseInt(document.getElementById('filter-score').value) || 0;
    const has  = document.getElementById('filter-has').value;
    const tag  = document.getElementById('filter-tag')?.value || '';
    const asg  = document.getElementById('filter-assigned')?.value ?? '';
    let visible = 0, total = 0;
    document.querySelectorAll('.kanban-card').forEach(c => {
        total++;
        let show = true;
        if (q && !c.dataset.search.includes(q)) show = false;
        if (scr && (parseInt(c.dataset.score) || 0) < scr) show = false;
        if (has === 'email' && c.dataset.hasEmail !== '1') show = false;
        if (has === 'tel'   && c.dataset.hasTel   !== '1') show = false;
        if (has === 'both' && (c.dataset.hasEmail !== '1' || c.dataset.hasTel !== '1')) show = false;
        if (tag) {
            const tags = (c.dataset.tags || '').split(',').filter(Boolean);
            if (!tags.includes(tag)) show = false;
        }
        if (asg !== '') {
            // '0' = sem responsável; outros = id específico
            if ((c.dataset.assigned || '0') !== asg) show = false;
        }
        c.classList.toggle('hidden-by-filter', !show);
        if (show) visible++;
    });
    const anyFilter = q || scr || has || tag || (asg !== '');
    document.getElementById('filter-count').textContent = anyFilter ? `${visible} de ${total}` : '';
    updateCounts();
}
function clearFilters() {
    document.getElementById('filter-q').value = '';
    document.getElementById('filter-score').value = '';
    document.getElementById('filter-has').value = '';
    if (document.getElementById('filter-tag'))      document.getElementById('filter-tag').value = '';
    if (document.getElementById('filter-assigned')) document.getElementById('filter-assigned').value = '';
    applyFilters();
}

// ────────────────────────────────────────────────────────────────────────────
// Modal de edição
// ────────────────────────────────────────────────────────────────────────────
// State da seleção de tags no modal
let _editTags = []; // [{id, name, color}]

function openEdit(card) {
    document.getElementById('em-card-id').value     = card.id;
    document.getElementById('em-nome-fantasia').value = card.nome_fantasia || '';
    document.getElementById('em-razao-social').value  = card.razao_social  || '';
    document.getElementById('em-telefone').value      = card.telefone      || '';
    document.getElementById('em-email').value         = card.email         || '';
    document.getElementById('em-cidade-uf').value     = card.cidade_uf     || '';
    document.getElementById('em-notes').value         = card.notes         || '';
    document.getElementById('em-title').textContent   = card.nome_fantasia || card.razao_social || 'Editar card';
    if (card.cnpj) {
        document.getElementById('em-cnpj-row').style.display = '';
        document.getElementById('em-cnpj').value = card.cnpj.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    } else {
        document.getElementById('em-cnpj-row').style.display = 'none';
    }
    // Responsável
    const asg = document.getElementById('em-assigned');
    if (asg) asg.value = card.assigned_user_id || '';
    // Tags
    _editTags = (card._tags || []).slice();
    renderEditTags();
    // Produto e Prazo (lê prefs do usuário via window.PIPE_PREFS)
    const showProduct  = window.PIPE_PREFS?.showProduct  !== false;
    const showDue      = window.PIPE_PREFS?.showDue      !== false;
    const productLabel = window.PIPE_PREFS?.productLabel || 'Produto / Serviço';
    const productRow   = document.getElementById('em-product-row');
    const dueRow       = document.getElementById('em-due-row');
    if (productRow) productRow.style.display = showProduct ? '' : 'none';
    if (dueRow)     dueRow.style.display     = showDue     ? '' : 'none';
    const lbl = document.getElementById('em-product-label');
    if (lbl && productLabel) lbl.textContent = productLabel;
    const pn = document.getElementById('em-product-name');
    if (pn) pn.value = card.product_name || '';
    const dd = document.getElementById('em-due-date');
    if (dd) dd.value = card.due_date || '';
    switchPane('info');
    document.getElementById('em-bg').classList.add('open');
}
function closeEdit() { document.getElementById('em-bg').classList.remove('open'); }

function switchPane(pane) {
    document.querySelectorAll('.em-tab').forEach(t => t.classList.toggle('active', t.dataset.pane === pane));
    document.querySelectorAll('.em-pane').forEach(p => p.classList.toggle('active', p.dataset.pane === pane));
    if (pane === 'history')  loadHistory();
    if (pane === 'comments') loadComments();
}

function loadHistory() {
    const id = document.getElementById('em-card-id').value;
    const box = document.getElementById('em-history');
    box.innerHTML = '<div style="text-align:center;color:var(--mute);padding:20px;font-size:.85rem">Carregando…</div>';
    const fd = new FormData();
    fd.append('action', 'history');
    fd.append('card_id', id);
    crmPost(fd)
        .then(r => r.json())
        .then(r => {
            if (!r.ok) { box.innerHTML = '<div style="text-align:center;color:var(--mute);padding:20px">Sem histórico</div>'; return; }
            if (!r.history.length) { box.innerHTML = '<div style="text-align:center;color:var(--mute);padding:20px">Nenhuma ação registrada ainda</div>'; return; }
            box.innerHTML = r.history.map(h => `
                <div class="hist-item">
                    <div class="hist-dot"></div>
                    <div class="hist-content">
                        <div class="hist-action">${esc(h.action)}</div>
                        <div class="hist-detail">${esc(h.detail || '—')}</div>
                        <div class="hist-time">${fmtDate(h.created_at)}</div>
                    </div>
                </div>
            `).join('');
        });
}
function esc(s)     { return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function fmtDate(s) { if (!s) return ''; const d = new Date(s.replace(' ','T')); return d.toLocaleString('pt-BR'); }

function saveEdit() {
    const id = document.getElementById('em-card-id').value;
    const fd = new FormData();
    fd.append('action', 'update_card');
    fd.append('card_id', id);
    fd.append('nome_fantasia', document.getElementById('em-nome-fantasia').value);
    fd.append('razao_social',  document.getElementById('em-razao-social').value);
    fd.append('telefone',      document.getElementById('em-telefone').value);
    fd.append('email',         document.getElementById('em-email').value);
    fd.append('cidade_uf',     document.getElementById('em-cidade-uf').value);
    fd.append('notes',         document.getElementById('em-notes').value);
    fd.append('tag_ids',       _editTags.map(t => t.id).join(','));
    const asg = document.getElementById('em-assigned');
    if (asg) fd.append('assigned_user_id', asg.value || '');
    const pn = document.getElementById('em-product-name');
    if (pn) fd.append('product_name', pn.value);
    const dd = document.getElementById('em-due-date');
    if (dd) fd.append('due_date', dd.value);
    crmPost(fd)
        .then(r => r.json())
        .then(r => { if (r.ok) location.reload(); else alert('Erro ao salvar'); });
}

function deleteCard(id) {
    if (!confirm('Remover este card do funil?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('card_id', id);
    crmPost(fd)
        .then(r => r.json())
        .then(r => { if (r.ok) { document.querySelector(`[data-card-id="${id}"]`)?.remove(); updateCounts(); } });
}

// ────────────────────────────────────────────────────────────────────────────
// Tags — combobox no modal de edição
// ────────────────────────────────────────────────────────────────────────────
// Cache de tags disponíveis do tenant — atualizado quando cria nova
let _allTags = <?= json_encode($tags_all) ?>;

function renderEditTags() {
    const box = document.getElementById('em-tags-box');
    const input = document.getElementById('em-tag-input');
    // Remove chips existentes (mantém o input)
    box.querySelectorAll('.tag-chip').forEach(el => el.remove());
    _editTags.forEach(t => {
        const chip = document.createElement('span');
        chip.className = 'tag-chip';
        chip.style.background = t.color + '22';
        chip.style.color = t.color;
        chip.innerHTML = esc(t.name) + ' <span class="x" title="Remover">×</span>';
        chip.querySelector('.x').onclick = () => {
            _editTags = _editTags.filter(x => x.id !== t.id);
            renderEditTags();
        };
        box.insertBefore(chip, input);
    });
}

function tagsPopUpdate() {
    const input = document.getElementById('em-tag-input');
    const pop = document.getElementById('em-tags-pop');
    const q = input.value.trim().toLowerCase();
    const selected = new Set(_editTags.map(t => t.id));
    const matches = _allTags.filter(t => !selected.has(t.id) && (!q || t.name.toLowerCase().includes(q)));

    let html = matches.slice(0, 8).map(t =>
        `<div class="em-tags-pop-item" data-action="pick" data-id="${t.id}">
            <span class="swatch" style="background:${esc(t.color)}"></span>${esc(t.name)}
        </div>`
    ).join('');

    if (q && !_allTags.some(t => t.name.toLowerCase() === q)) {
        html += `<div class="em-tags-pop-item create" data-action="create" data-name="${esc(q)}">
            + Criar tag "${esc(q)}"
        </div>`;
    }
    pop.innerHTML = html || '<div class="em-tags-pop-item" style="color:var(--mute)">Sem sugestões</div>';
    pop.classList.add('open');
}

function tagsPopClose() {
    document.getElementById('em-tags-pop').classList.remove('open');
}

function pickTag(id) {
    const t = _allTags.find(x => x.id === parseInt(id));
    if (!t) return;
    if (!_editTags.some(x => x.id === t.id)) _editTags.push(t);
    document.getElementById('em-tag-input').value = '';
    renderEditTags();
    tagsPopClose();
}

function createTag(name) {
    const colors = ['#10b981','#06b6d4','#be123c','#f59e0b','#8b5cf6','#0ea5e9','#ec4899','#84cc16'];
    const color  = colors[Math.floor(Math.random() * colors.length)];
    const fd = new FormData();
    fd.append('action', 'tag_create');
    fd.append('name', name);
    fd.append('color', color);
    crmPost(fd)
        .then(r => r.json())
        .then(r => {
            if (!r.ok) return alert('Erro ao criar tag');
            const newTag = { id: r.id, name: r.name, color: r.color };
            if (!_allTags.some(t => t.id === newTag.id)) _allTags.push(newTag);
            _editTags.push(newTag);
            document.getElementById('em-tag-input').value = '';
            renderEditTags();
            tagsPopClose();
        });
}

// Setup do combobox de tags (event listeners)
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('em-tag-input');
    const pop   = document.getElementById('em-tags-pop');
    if (!input) return;
    input.addEventListener('focus', tagsPopUpdate);
    input.addEventListener('input', tagsPopUpdate);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const q = input.value.trim();
            if (!q) return;
            const match = _allTags.find(t => t.name.toLowerCase() === q.toLowerCase());
            if (match) pickTag(match.id);
            else createTag(q);
        } else if (e.key === 'Escape') tagsPopClose();
        else if (e.key === 'Backspace' && !input.value && _editTags.length) {
            _editTags.pop();
            renderEditTags();
        }
    });
    pop.addEventListener('mousedown', e => {
        const item = e.target.closest('.em-tags-pop-item');
        if (!item) return;
        e.preventDefault(); // não tira focus do input
        if (item.dataset.action === 'pick')   pickTag(item.dataset.id);
        if (item.dataset.action === 'create') createTag(item.dataset.name);
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('.em-tags-suggestions')) tagsPopClose();
    });
});

// ────────────────────────────────────────────────────────────────────────────
// Comentários
// ────────────────────────────────────────────────────────────────────────────
function loadComments() {
    const id = document.getElementById('em-card-id').value;
    const box = document.getElementById('em-comments');
    box.innerHTML = '<div class="cmt-empty">Carregando…</div>';
    const fd = new FormData();
    fd.append('action', 'comment_list');
    fd.append('card_id', id);
    crmPost(fd)
        .then(r => r.json())
        .then(r => {
            if (!r.ok) { box.innerHTML = '<div class="cmt-empty">Erro ao carregar</div>'; return; }
            if (!r.comments.length) { box.innerHTML = '<div class="cmt-empty">Nenhum comentário ainda. Adicione o primeiro abaixo.</div>'; return; }
            box.innerHTML = r.comments.map(c => `
                <div class="cmt-item" data-id="${c.id}">
                    <div class="cmt-head">
                        <span class="avatar">${esc((c.user_name || '?').substring(0,1))}</span>
                        <span class="name">${esc(c.user_name || c.user_email || 'Usuário')}</span>
                        <span class="time">${fmtDate(c.created_at)}</span>
                        <button class="del" title="Excluir" onclick="deleteComment(${c.id})">🗑</button>
                    </div>
                    <div class="cmt-body">${esc(c.body)}</div>
                </div>
            `).join('');
        });
}

function addComment() {
    const id = document.getElementById('em-card-id').value;
    const inp = document.getElementById('cmt-input');
    const body = inp.value.trim();
    if (!body) return;
    const fd = new FormData();
    fd.append('action', 'comment_add');
    fd.append('card_id', id);
    fd.append('body', body);
    crmPost(fd)
        .then(r => r.json())
        .then(r => {
            if (r.ok) { inp.value = ''; loadComments(); }
            else alert('Erro ao comentar');
        });
}

function deleteComment(id) {
    if (!confirm('Excluir comentário?')) return;
    const fd = new FormData();
    fd.append('action', 'comment_delete');
    fd.append('comment_id', id);
    crmPost(fd)
        .then(r => r.json())
        .then(() => loadComments());
}

// Ctrl+Enter envia
document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('cmt-input');
    if (inp) inp.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); addComment(); }
    });
});

// ────────────────────────────────────────────────────────────────────────────
// Modal de coluna (add/edit/del)
// ────────────────────────────────────────────────────────────────────────────
let _editingColId = 0;

function openColEdit(colId) {
    _editingColId = colId;
    if (colId === 0) {
        document.getElementById('col-edit-title').textContent = 'Nova coluna';
        document.getElementById('col-edit-name').value = '';
        document.getElementById('col-edit-color').value = '#10b981';
        document.getElementById('col-edit-del').style.display = 'none';
    } else {
        const col = document.querySelector(`[data-col-id="${colId}"]`);
        document.getElementById('col-edit-title').textContent = 'Editar coluna';
        document.getElementById('col-edit-name').value  = col.dataset.colName;
        document.getElementById('col-edit-color').value = col.dataset.colColor;
        document.getElementById('col-edit-del').style.display = '';
    }
    document.getElementById('col-edit-bg').classList.add('open');
    setTimeout(() => document.getElementById('col-edit-name').focus(), 50);
}
function closeColEdit() { document.getElementById('col-edit-bg').classList.remove('open'); }

function saveColumn() {
    const name  = document.getElementById('col-edit-name').value.trim();
    const color = document.getElementById('col-edit-color').value;
    if (!name) { alert('Digite o nome da coluna'); return; }
    const fd = new FormData();
    if (_editingColId === 0) {
        fd.append('action', 'col_add');
        fd.append('name', name);
        fd.append('color', color);
    } else {
        fd.append('action', 'col_update');
        fd.append('col_id', _editingColId);
        fd.append('name', name);
        fd.append('color', color);
    }
    crmPost(fd)
        .then(r => r.json())
        .then(r => { if (r.ok) location.reload(); else alert('Erro: ' + (r.error || 'desconhecido')); });
}

function deleteColumn() {
    if (_editingColId === 0) return;
    const col = document.querySelector(`[data-col-id="${_editingColId}"]`);
    const count = col?.querySelectorAll('.kanban-card').length || 0;
    if (count > 0) { alert(`Coluna tem ${count} card(s). Mova ou remova os cards primeiro.`); return; }
    if (!confirm('Excluir esta coluna?')) return;
    const fd = new FormData();
    fd.append('action', 'col_delete');
    fd.append('col_id', _editingColId);
    crmPost(fd)
        .then(r => r.json())
        .then(r => {
            if (r.ok) location.reload();
            else if (r.error === 'has_cards') alert(`Coluna tem ${r.count} card(s). Remova primeiro.`);
            else alert('Erro ao excluir');
        });
}

// Fechar modais com ESC ou click no backdrop
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeEdit(); closeColEdit(); }
});
document.getElementById('em-bg').addEventListener('click', e => { if (e.target.id === 'em-bg') closeEdit(); });
document.getElementById('col-edit-bg').addEventListener('click', e => { if (e.target.id === 'col-edit-bg') closeColEdit(); });

// ── Bulk selection ──────────────────────────────────────────────────────────
let _bulkMode = false;

// Clique simples no card: abre modal (exceto em modo bulk ou clique em botão/link/checkbox)
function cardSingleClick(e, card, data) {
  if (_bulkMode) return; // bulk mode cuida do próprio click
  const tag = e.target.tagName;
  if (['BUTTON','A','INPUT','SELECT','TEXTAREA'].includes(tag)) return;
  if (e.target.closest('.actions, .bulk-cb')) return;
  openEdit(data);
}

function toggleBulkMode(forceOff) {
  _bulkMode = forceOff === false ? false : !_bulkMode;
  document.body.classList.toggle('bulk-mode', _bulkMode);
  const btn = document.getElementById('bulk-toggle');
  btn.textContent = _bulkMode ? '× Sair da seleção' : '☑ Selecionar';
  if (!_bulkMode) {
    // Desmarca tudo
    document.querySelectorAll('.kanban-card').forEach(c => {
      c.classList.remove('selected');
      const cb = c.querySelector('.bulk-cb input');
      if (cb) cb.checked = false;
    });
    updateBulkBar();
  }
}

function bulkCardClick(e, card) {
  if (!_bulkMode) return; // Em modo normal, deixa o dblclick abrir o modal
  e.stopPropagation();
  card.classList.toggle('selected');
  const cb = card.querySelector('.bulk-cb input');
  if (cb) cb.checked = card.classList.contains('selected');
  updateBulkBar();
}

function bulkCbChange(e, cb) {
  e.stopPropagation();
  const card = cb.closest('.kanban-card');
  if (card) card.classList.toggle('selected', cb.checked);
  updateBulkBar();
}

function updateBulkBar() {
  const sel = document.querySelectorAll('.kanban-card.selected');
  const bar = document.getElementById('bulk-bar');
  const cnt = document.getElementById('bulk-count');
  cnt.textContent = sel.length + (sel.length === 1 ? ' card' : ' cards');
  bar.classList.toggle('show', sel.length > 0 && _bulkMode);
}

function getSelectedIds() {
  return Array.from(document.querySelectorAll('.kanban-card.selected'))
    .map(c => c.dataset.cardId).join(',');
}

async function bulkMove() {
  const colId = document.getElementById('bulk-col-select').value;
  if (!colId) { alert('Selecione uma coluna primeiro.'); return; }
  const ids = getSelectedIds();
  if (!ids) return;
  const fd = new FormData();
  fd.append('action', 'bulk_move');
  fd.append('card_ids', ids);
  fd.append('column_id', colId);
  const r = await crmPost(fd).then(r => r.json());
  if (r.ok) {
    pipeToast(`✓ ${r.moved} card${r.moved !== 1 ? 's' : ''} movido${r.moved !== 1 ? 's' : ''}`);
    toggleBulkMode(false);
    setTimeout(() => location.reload(), 800);
  } else alert('Erro: ' + (r.error || 'desconhecido'));
}

async function bulkArchive() {
  const ids = getSelectedIds();
  if (!ids) return;
  const count = ids.split(',').length;
  if (!confirm(`Arquivar ${count} card${count !== 1 ? 's' : ''}? Eles ficarão ocultos no kanban.`)) return;
  const fd = new FormData();
  fd.append('action', 'bulk_archive');
  fd.append('card_ids', ids);
  const r = await crmPost(fd).then(r => r.json());
  if (r.ok) {
    pipeToast(`📁 ${r.archived} card${r.archived !== 1 ? 's' : ''} arquivado${r.archived !== 1 ? 's' : ''}`);
    toggleBulkMode(false);
    setTimeout(() => location.reload(), 800);
  } else alert('Erro: ' + (r.error || 'desconhecido'));
}

async function bulkDelete() {
  const ids = getSelectedIds();
  if (!ids) return;
  const count = ids.split(',').length;
  if (!confirm(`⚠ Deletar permanentemente ${count} card${count !== 1 ? 's' : ''}? Esta ação não pode ser desfeita.`)) return;
  const fd = new FormData();
  fd.append('action', 'bulk_delete');
  fd.append('card_ids', ids);
  const r = await crmPost(fd).then(r => r.json());
  if (r.ok) {
    pipeToast(`🗑 ${r.deleted} card${r.deleted !== 1 ? 's' : ''} deletado${r.deleted !== 1 ? 's' : ''}`);
    toggleBulkMode(false);
    setTimeout(() => location.reload(), 800);
  } else alert('Erro: ' + (r.error || 'desconhecido'));
}

function pipeToast(msg) {
  let t = document.getElementById('pipe-toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'pipe-toast';
    t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 20px;border-radius:8px;font-size:.86rem;z-index:600;opacity:0;transition:opacity .2s;pointer-events:none;';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  setTimeout(() => { t.style.opacity = '0'; }, 2200);
}
</script>
<?php
});
