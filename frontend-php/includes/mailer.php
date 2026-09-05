<?php

// Transactional email over SMTP.
//
// Why a hand-rolled client rather than PHPMailer: there is no Composer in the
// frontend image and no PHP dependency in this repo at all, so pulling one in
// would mean adding a build stage, a vendor tree and a supply-chain surface for
// what these messages need — plain text/HTML, no attachments, no DKIM. If
// attachments or DKIM are ever required, replace this with PHPMailer rather
// than growing it.
//
// mail() is not an option: the container has no MTA. /usr/sbin/sendmail does not
// exist, so mail() returns false and every activation and password-reset email
// silently failed to send before this existed.
//
// The protocol details that actually bite are handled explicitly below:
// CRLF-only line endings, dot-stuffing, header-injection stripping, RFC 2047
// subject encoding, and multi-line reply parsing.

function smtpSettings(mysqli $conn = null) {
    $db = settingsConn($conn);
    $get = fn($k, $d = '') => $db ? (string)appSetting($db, $k, $d) : $d;

    return [
        'host'       => $get('smtp_host'),
        'port'       => (int)($get('smtp_port', '587') ?: 587),
        'encryption' => $get('smtp_encryption', 'tls'),
        'username'   => $get('smtp_username'),
        'password'   => decryptSecret($get('smtp_password_enc')),
        'from_email' => $get('smtp_from_email') ?: MAIL_FROM,
        'from_name'  => $get('smtp_from_name') ?: MAIL_FROM_NAME,
    ];
}

function smtpConfigured(mysqli $conn = null) {
    $s = smtpSettings($conn);
    return $s['host'] !== '' && $s['from_email'] !== '';
}

// True when a password is stored but cannot be decrypted — i.e. the instance
// secret was rotated. The admin page surfaces this so the fix (re-enter it) is
// obvious, instead of mail failing for an invisible reason.
function smtpCredentialUnreadable(mysqli $conn = null) {
    $db = settingsConn($conn);
    if (!$db) return false;
    $stored = (string)appSetting($db, 'smtp_password_enc', '');
    return $stored !== '' && decryptSecret($stored) === null;
}

// --- Header safety ----------------------------------------------------------

// Makes a value safe to place in a header. Without this, a newline in a display
// name or subject lets a caller inject extra headers — Bcc, a different From —
// the classic mail-header injection.
//
// It **truncates** at the first CR/LF/NUL rather than deleting them. Deleting
// is equally safe (the header count cannot change) but silently *concatenates*
// the attacker's payload onto the legitimate value, so a subject becomes
// "SubjectBcc: attacker@evil.com". Truncating discards it instead.
function mailHeaderSafe($value) {
    $value = (string)$value;
    $cut = strcspn($value, "\r\n\0");
    return trim(substr($value, 0, $cut));
}

// RFC 2047 encoded-word for headers containing non-ASCII. Plain ASCII is left
// alone so the common case stays readable in transit.
function mailEncodeHeader($value) {
    $value = mailHeaderSafe($value);
    if (preg_match('/^[\x20-\x7E]*$/', $value)) return $value;
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function mailValidAddress($email) {
    $email = mailHeaderSafe($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

// --- SMTP conversation ------------------------------------------------------

class SmtpClient
{
    private $socket = null;
    private array $log = [];
    private int $timeout;

    public function __construct(int $timeout = 15)
    {
        $this->timeout = $timeout;
    }

    public function log(): array
    {
        return $this->log;
    }

    // Throws RuntimeException with a message safe to show an admin. The
    // password is never written to the transcript.
    public function send(array $cfg, string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $encryption = $cfg['encryption'] ?? 'tls';
        $host = $cfg['host'];
        $port = (int)$cfg['port'];

        // Implicit TLS ("smtps", usually 465) wraps the socket from the first
        // byte. STARTTLS (usually 587) begins in cleartext and upgrades.
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT, $context
        );
        if (!$this->socket) {
            throw new RuntimeException("Cannot connect to {$host}:{$port} — " . ($errstr ?: 'connection failed'));
        }
        stream_set_timeout($this->socket, $this->timeout);

        try {
            $this->expect([220], 'greeting');

            $ehloName = $this->ehloName($cfg['from_email'] ?? '');
            $this->command("EHLO {$ehloName}");
            $caps = $this->expect([250], 'EHLO');

            if ($encryption === 'tls') {
                $this->command('STARTTLS');
                $this->expect([220], 'STARTTLS');
                if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed (certificate or protocol mismatch).');
                }
                // Capabilities must be re-read after the upgrade: the server is
                // allowed to advertise a different set, and AUTH usually only
                // appears once the channel is encrypted.
                $this->command("EHLO {$ehloName}");
                $caps = $this->expect([250], 'EHLO after STARTTLS');
            }

            if (($cfg['username'] ?? '') !== '' && ($cfg['password'] ?? '') !== '') {
                $this->authenticate($caps, $cfg['username'], $cfg['password']);
            }

            $from = mailValidAddress($cfg['from_email']);
            $to = mailValidAddress($toEmail);
            if (!$from) throw new RuntimeException('The "from" address is not a valid email address.');
            if (!$to) throw new RuntimeException('The recipient address is not a valid email address.');

            $this->command("MAIL FROM:<{$from}>");
            $this->expect([250], 'MAIL FROM');
            $this->command("RCPT TO:<{$to}>");
            $this->expect([250, 251], 'RCPT TO');
            $this->command('DATA');
            $this->expect([354], 'DATA');

            $this->writeRaw($this->buildMessage($cfg, $to, $subject, $htmlBody, $textBody));
            $this->writeRaw("\r\n.\r\n");
            $this->expect([250], 'message body');

            $this->command('QUIT');
            return true;
        } finally {
            if ($this->socket) {
                @fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    // Some servers reject an EHLO argument that is not a FQDN or bracketed
    // literal, so derive it from the sender domain rather than sending a bare
    // hostname that may be a container id.
    private function ehloName(string $fromEmail): string
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '', 1);
        if ($domain === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $domain)) {
            $domain = defined('APP_HOST') ? APP_HOST : 'localhost';
        }
        return preg_match('/^[A-Za-z0-9.-]+$/', $domain) ? $domain : 'localhost';
    }

    private function authenticate(string $caps, string $username, string $password): void
    {
        $upper = strtoupper($caps);

        // AUTH PLAIN is one round trip; LOGIN is the fallback for servers that
        // do not offer it.
        if (str_contains($upper, 'PLAIN')) {
            $token = base64_encode("\0" . $username . "\0" . $password);
            $this->command('AUTH PLAIN ' . $token, 'AUTH PLAIN <redacted>');
            $this->expect([235], 'authentication');
            return;
        }
        if (str_contains($upper, 'LOGIN')) {
            $this->command('AUTH LOGIN');
            $this->expect([334], 'AUTH LOGIN');
            $this->command(base64_encode($username), '<username>');
            $this->expect([334], 'username');
            $this->command(base64_encode($password), '<redacted>');
            $this->expect([235], 'authentication');
            return;
        }
        throw new RuntimeException('The server advertises no supported authentication method (PLAIN or LOGIN).');
    }

    private function buildMessage(array $cfg, string $to, string $subject, string $html, string $text): string
    {
        $fromEmail = mailValidAddress($cfg['from_email']);
        $fromName = mailEncodeHeader($cfg['from_name'] ?? '');
        $boundary = 'b' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? "{$fromName} <{$fromEmail}>" : $fromEmail),
            'To: ' . $to,
            'Subject: ' . mailEncodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (defined('APP_HOST') ? APP_HOST : 'localhost') . '>',
            'MIME-Version: 1.0',
            // Transactional mail must not generate vacation replies or be
            // treated as bulk.
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];

        if ($text !== '') {
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text), 76, "\r\n")
                . "\r\n--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html), 76, "\r\n")
                . "\r\n--{$boundary}--";
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($html), 76, "\r\n");
        }

        // Base64 throughout, so no body line can exceed the 998-octet limit and
        // no line can begin with a bare "." — but dot-stuff anyway, because the
        // rule applies to whatever is actually written.
        return implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($body);
    }

    // A line consisting of a single "." terminates DATA, so any body line that
    // starts with "." must be doubled. Getting this wrong truncates messages.
    private function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], "\n", $body);
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') $line = '.' . $line;
        }
        return implode("\r\n", $lines);
    }

    private function command(string $line, string $logAs = null): void
    {
        $this->log[] = '> ' . ($logAs ?? $line);
        $this->writeRaw($line . "\r\n");
    }

    private function writeRaw(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Connection lost while sending.');
        }
    }

    // Reads a possibly multi-line reply ("250-CAP" lines then "250 CAP") and
    // asserts the code. Returns the joined text so EHLO capabilities can be
    // inspected.
    private function expect(array $codes, string $stage): string
    {
        $text = '';
        $code = 0;

        while (true) {
            $line = @fgets($this->socket, 1024);
            if ($line === false || $line === '') {
                $meta = $this->socket ? stream_get_meta_data($this->socket) : ['timed_out' => false];
                throw new RuntimeException(!empty($meta['timed_out'])
                    ? "Timed out waiting for the server at: {$stage}."
                    : "The server closed the connection at: {$stage}.");
            }
            $this->log[] = '< ' . rtrim($line);
            $text .= $line;
            $code = (int)substr($line, 0, 3);
            // A hyphen in the 4th column means more lines follow.
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }

        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("Server rejected {$stage}: " . trim($text));
        }
        return $text;
    }
}

// --- Public entry point -----------------------------------------------------

// Returns [ok(bool), message(string)]. Never throws: a failed notification must
// not take down the request that triggered it (a signup must still succeed even
// if the activation email cannot be delivered).
function sendMailNow($to, $subject, $htmlBody, $textBody = '', mysqli $conn = null) {
    $db = settingsConn($conn);

    // Development: write to a log instead of sending, so local work never mails
    // a real person.
    if (DEV_MODE) {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents(
            $logDir . '/emails.log',
            '[' . date('Y-m-d H:i:s') . "] To: {$to} | Subject: {$subject}\n{$htmlBody}\n---\n",
            FILE_APPEND
        );
        return [true, 'Logged to logs/emails.log (DEV_MODE).'];
    }

    if (!$db || !smtpConfigured($db)) {
        error_log("Email not sent (SMTP not configured): {$subject} -> {$to}");
        return [false, 'SMTP is not configured.'];
    }
    if (smtpCredentialUnreadable($db)) {
        error_log('Email not sent: stored SMTP password cannot be decrypted.');
        return [false, 'The stored SMTP password cannot be decrypted — re-enter it in Email settings.'];
    }

    $cfg = smtpSettings($db);
    $client = new SmtpClient();
    try {
        $client->send($cfg, $to, $subject, $htmlBody, $textBody);
        return [true, 'Sent.'];
    } catch (Throwable $e) {
        // The transcript redacts credentials, but keep it out of the response
        // and in the log.
        error_log('SMTP send failed: ' . $e->getMessage() . ' | ' . implode(' ', $client->log()));
        return [false, $e->getMessage()];
    }
}
