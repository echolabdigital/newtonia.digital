<?php
/**
 * Newton IA — FLUX dispatcher
 * Roda via cron a cada 1 minuto. Processa um lote de cada campanha rodando.
 *
 *   * * * * * php /home/newtonia.digital/app/cron/flux-dispatch.php >> /var/log/newton-flux.log 2>&1
 */
require_once __DIR__ . '/../config.php';

$running = db_all('SELECT id, tenant_id, throttle_per_min FROM campaigns WHERE status = "running" ORDER BY id ASC');
if (!$running) { echo "[flux] no running campaigns\n"; exit; }

foreach ($running as $c) {
    // Quantas msgs por minuto -> processa esse tanto em 1 chamada (com sleep interno).
    // Mantemos o lote pequeno (max 10) pra nao segurar o processo demais.
    $batch = max(1, min(10, (int)$c['throttle_per_min']));
    $r = flux_campaign_dispatch((int)$c['id'], $batch);
    echo sprintf("[flux] campaign=%d processed=%d sent=%d failed=%d skip=%s\n",
        $c['id'], $r['processed'] ?? 0, $r['sent'] ?? 0, $r['failed'] ?? 0, $r['skip'] ?? '-');
}
