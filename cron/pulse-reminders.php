<?php
/**
 * Newton IA — PULSE reminders
 * Cron a cada 5min: envia lembretes 24h e 1h antes via WhatsApp.
 *
 * Crontab: a cada 5 minutos
 *   star-slash-5 star star star star  php /home/newtonia.digital/app/cron/pulse-reminders.php
 */
require_once __DIR__ . '/../config.php';
$r = pulse_send_reminders();
echo sprintf("[pulse] reminders sent: 24h=%d 1h=%d\n", $r['sent_24h'], $r['sent_1h']);
