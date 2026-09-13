<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

/** Helpers **/
function _mail_sanitize_email(?string $v): ?string {
    if (!$v) return null;
    $v = trim($v);
    if (preg_match('~[\r\n]~', $v)) return null;
    return filter_var($v, FILTER_VALIDATE_EMAIL) ?: null;
}
function _mail_sanitize_subject(string $s): string {
    $s = str_replace(["\r","\n"], ' ', trim($s));
    return mb_substr($s, 0, 255);
}
function _mail_alt_from_html(string $html): string {
    $t = strip_tags($html);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('~[ \t]+~', ' ', $t);
    return trim($t);
}
function _mail_log_dir(): string {
    $dir = (defined('LOG_DIR') ? LOG_DIR : (__DIR__ . '/../logs'));
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n"); }
    return $dir;
}
function _mail_log(string $msg): void {
    $file = _mail_log_dir() . '/mailer.log';
    @file_put_contents($file, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

/* Simple Template-Renderer */
function render_email_template(string $template, array $vars): string {
    foreach ($vars as $k => $v) { $template = str_replace('{{'.$k.'}}', (string)$v, $template); }
    return $template;
}

/* Hauptfunktion – robust gegen fehlenden PHPMailer und ohne SMTP-Auth möglich */
/* Hauptfunktion – robust gegen fehlenden PHPMailer und ohne SMTP-Auth möglich */
function send_mail_html(string $to, string $subject, string $html, array $opts = []): bool {
    $toEmail = _mail_sanitize_email($to);
    if (!$toEmail) { _mail_log("Ungültige Empfänger-Adresse: {$to}"); return false; }

    $subject = _mail_sanitize_subject($subject);
    $textAlt = isset($opts['text_alt']) && is_string($opts['text_alt']) ? $opts['text_alt'] : _mail_alt_from_html($html);

    $fromEmail = _mail_sanitize_email($opts['from_email'] ?? (defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@pendenz.com')) ?: 'no-reply@pendenz.com';
    $fromName  = trim($opts['from_name'] ?? (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'pendenz.com'));

    // Dry-Run?
    $dryRun = (getenv('MAIL_DRY_RUN') === '1') || (defined('MAIL_DRY_RUN') && MAIL_DRY_RUN);
    if ($dryRun) {
        $dir = _mail_log_dir() . '/emails';
        @mkdir($dir, 0777, true);
        $dump = "To: {$toEmail}\nSubject: {$subject}\nFrom: {$fromName} <{$fromEmail}>\n\n{$html}";
        @file_put_contents($dir.'/'.date('Ymd_His').'_'.preg_replace('~[^a-z0-9_.-]+~i','_',$toEmail).'.eml', $dump);
        _mail_log("Dry-run: E-Mail gespeichert (an {$toEmail})");
        return true;
    }

    // SMTP vorgesehen?
    $useSmtp = defined('SMTP_HOST') && SMTP_HOST;

    // Composer Autoloader optional laden
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendorAutoload)) { @require_once $vendorAutoload; }
    $hasPhpMailer = class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');

    // === Pfad 1: PHPMailer + SMTP ===
    if ($useSmtp && $hasPhpMailer) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = (int)(defined('SMTP_PORT') ? SMTP_PORT : 25);

            if (defined('SMTP_USER') && SMTP_USER !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = defined('SMTP_PASS') ? SMTP_PASS : '';
            } else {
                $mail->SMTPAuth = false;
            }
            if (defined('SMTP_SECURE') && SMTP_SECURE) {
                $mail->SMTPSecure = SMTP_SECURE; // 'tls' | 'ssl'
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail);

            // Reply-To
            if (!empty($opts['reply_to'])) {
                $rt = $opts['reply_to'];
                if (is_string($rt)) {
                    if (preg_match('~^(.*)<([^>]+)>$~', $rt, $m)) {
                        $rtEmail = _mail_sanitize_email(trim($m[2]));
                        $rtName  = trim($m[1], " \t\"'");
                        if ($rtEmail) $mail->addReplyTo($rtEmail, $rtName);
                    } else {
                        $rtEmail = _mail_sanitize_email($rt);
                        if ($rtEmail) $mail->addReplyTo($rtEmail);
                    }
                }
            }

            // CC/BCC
            foreach (($opts['cc'] ?? []) as $cc)  { $cc = _mail_sanitize_email($cc);  if ($cc)  $mail->addCC($cc); }
            foreach (($opts['bcc'] ?? []) as $bc) { $bc = _mail_sanitize_email($bc); if ($bc) $mail->addBCC($bc); }

            // DKIM (optional)
            if (defined('SMTP_DKIM_DOMAIN') && SMTP_DKIM_DOMAIN && defined('SMTP_DKIM_SELECTOR') && SMTP_DKIM_SELECTOR && defined('SMTP_DKIM_KEYFILE') && is_readable(SMTP_DKIM_KEYFILE)) {
                $mail->DKIM_domain   = SMTP_DKIM_DOMAIN;
                $mail->DKIM_selector = SMTP_DKIM_SELECTOR;
                $mail->DKIM_private  = file_get_contents(SMTP_DKIM_KEYFILE);
                $mail->DKIM_identity = $fromEmail;
            }

            if (!empty($opts['x_category'])) {
                $mail->addCustomHeader('X-Category', preg_replace('~[\r\n]~',' ', (string)$opts['x_category']));
            }
            if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
                foreach ($opts['attachments'] as $att) {
                    $path = $att['path'] ?? null;
                    if ($path && is_readable($path)) {
                        $name = $att['name'] ?? basename($path);
                        $mail->addAttachment($path, $name);
                    }
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $textAlt;

            $mail->send();
            return true;
        } catch (Throwable $e) {
            _mail_log('SMTP/PHPMailer send failed: ' . $e->getMessage());
            // weiter zu Fallbacks
        }
    } elseif ($useSmtp && !$hasPhpMailer) {
        _mail_log('PHPMailer nicht vorhanden. Nutze einfachen Socket-Fallback oder mail().');
    }

    // === Pfad 2: Einfacher Socket-SMTP (nur ohne Auth/TLS, z.B. MailHog/Mailpit) ===
    if ($useSmtp && function_exists('fsockopen')) {
        $user   = defined('SMTP_USER')   ? (string)SMTP_USER   : '';
        $secure = defined('SMTP_SECURE') ? (string)SMTP_SECURE : '';
        $port   = (int)(defined('SMTP_PORT') ? SMTP_PORT : 1025);

        if ($user === '' && ($secure === '' || $secure === null)) {
            $ok = _smtp_send_basic(SMTP_HOST, $port, $fromEmail, $fromName, $toEmail, $subject, $html, $textAlt);
            if ($ok) return true;
            _mail_log('Basic SMTP (fsockopen) fehlgeschlagen. Fallback auf mail().');
        } else {
            _mail_log('Basic SMTP deaktiviert (Auth/TLS konfiguriert). Installiere PHPMailer oder setze MAIL_DRY_RUN=true.');
        }
    }

    // === Pfad 3: Fallback auf PHP mail() ===
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    if (!empty($opts['reply_to'])) {
        $rt = $opts['reply_to'];
        if (is_string($rt)) {
            if (preg_match('~^(.*)<([^>]+)>$~', $rt, $m)) {
                $rtEmail = _mail_sanitize_email(trim($m[2]));
                $rtName  = trim($m[1], " \t\"'");
                if ($rtEmail) $headers .= "Reply-To: {$rtName} <{$rtEmail}>\r\n";
            } else {
                $rtEmail = _mail_sanitize_email($rt);
                if ($rtEmail) $headers .= "Reply-To: {$rtEmail}\r\n";
            }
        }
    }
    if (!empty($opts['x_category'])) {
        $headers .= "X-Category: " . preg_replace('~[\r\n]~',' ', (string)$opts['x_category']) . "\r\n";
    }

    $ok = @mail($toEmail, $subject, $html, $headers);
    if (!$ok) {
        _mail_log("PHP mail() fehlgeschlagen an {$toEmail} (Betreff: {$subject})");
    }
    return $ok;
}
