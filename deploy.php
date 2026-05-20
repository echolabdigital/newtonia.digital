<?php
/**
 * Newton IA — Deploy webhook
 * GitHub chama este endpoint a cada push no branch configurado.
 * URL: https://app.newtonia.digital/deploy.php
 *
 * Configure no GitHub:
 *   Settings → Webhooks → Add webhook
 *   Payload URL: https://app.newtonia.digital/deploy.php
 *   Content type: application/json
 *   Secret: (o mesmo de DEPLOY_SECRET abaixo)
 *   Events: Just the push event
 */

define('DEPLOY_SECRET', 'newton_deploy_2026');
define('DEPLOY_BRANCH', 'claude/setup-php-mysql-project-fvaVG');
define('DEPLOY_DIR',    '/home/newtonia.digital/app');
define('SSH_KEY',       '/root/.ssh/newtonia_deploy');
define('LOG_FILE',      '/tmp/newton_deploy.log');

header('Content-Type: application/json');

// ── Valida assinatura GitHub ────────────────────────────────────────────────
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_SECRET);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

$data   = json_decode($payload, true);
$branch = $data['ref'] ?? '';

// Só faz deploy se for o branch correto
if ($branch !== 'refs/heads/' . DEPLOY_BRANCH) {
    echo json_encode(['ok' => true, 'skip' => 'branch ' . $branch . ' ignored']);
    exit;
}

// ── Executa deploy ────────────────────────────────────────────────────────
$ts  = date('Y-m-d H:i:s');
$cmd = sprintf(
    'cd %s && GIT_SSH_COMMAND="ssh -i %s -o StrictHostKeyChecking=no" git fetch origin 2>&1 && git reset --hard origin/%s 2>&1',
    escapeshellarg(DEPLOY_DIR),
    escapeshellarg(SSH_KEY),
    escapeshellarg(DEPLOY_BRANCH)
);

$output = shell_exec($cmd . ' 2>&1');
$success = str_contains($output ?? '', 'HEAD is now at') || str_contains($output ?? '', 'Already up to date');

// Log
$log = "[{$ts}] Deploy triggered by GitHub push\n{$output}\n---\n";
file_put_contents(LOG_FILE, $log, FILE_APPEND);

echo json_encode([
    'ok'      => $success,
    'branch'  => DEPLOY_BRANCH,
    'output'  => substr($output ?? '', 0, 500),
    'ts'      => $ts,
]);
