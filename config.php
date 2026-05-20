<?php
error_reporting(E_ERROR | E_PARSE);

// ── MySQL ─────────────────────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'newt_newtonia');
define('DB_USER', 'newt_newtonia');
define('DB_PASS', 'ahp64AFwjvaWO6uoevucr3ERnCdPMFCF');

// ── App ───────────────────────────────────────────────────────────────────────
define('APP_URL',  'https://app.newtonia.digital');
define('APP_ENV',  'production');
define('APP_NAME', 'Newton IA');

// ── Sessão ────────────────────────────────────────────────────────────────────
define('SESSION_NAME',     'newton_sess');
define('SESSION_LIFETIME', 86400 * 30);

// ── Mailer ────────────────────────────────────────────────────────────────────
define('MAIL_DRIVER',    'native');
define('MAIL_FROM',      'noreply@newtonia.digital');
define('MAIL_FROM_NAME', 'Newton IA');

// ── Integrações (fallback — preferir system_settings no banco) ────────────────
define('GROQ_API_KEY', '');
define('ZAPI_BASE',    'https://api.z-api.io');

date_default_timezone_set('America/Sao_Paulo');

// ── Core ──────────────────────────────────────────────────────────────────────
$_core = __DIR__ . '/core/';
require_once $_core . 'db.php';
require_once $_core . 'auth.php';
require_once $_core . 'tenant.php';
require_once $_core . 'guard.php';
require_once $_core . 'settings.php';
require_once $_core . 'util.php';
require_once $_core . 'user_prefs.php';
require_once $_core . 'mailer.php';
require_once $_core . 'groq.php';
require_once $_core . 'llm.php';
require_once $_core . 'zapi.php';
require_once $_core . 'agent.php';
require_once $_core . 'synapse.php';
require_once $_core . 'api_auth.php';
require_once $_core . 'webhooks.php';
require_once $_core . 'synapse_plus.php';
