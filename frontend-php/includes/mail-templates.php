<?php

// Transactional email bodies.
//
// Kept together so the wording and the layout are consistent, and so every
// message ships both an HTML and a plain-text part — text-only clients and spam
// filters both treat HTML-only mail worse.
//
// Every interpolated value is escaped: these bodies carry user-supplied names
// and admin-supplied instructions.
//
// The app name comes from brandName() (#23), not from APP_NAME, so an operator
// who rebranded the instance is not still emailing their customers under the
// deployment's name. brandName() falls back to APP_NAME, so nothing here
// changes on an instance that never set one. No connection is passed: these are
// called from request handlers and from the reminder cron, both of which have
// the global $conn that settingsConn() resolves.

function mailLayout($heading, $bodyHtml) {
    $appName = sanitize(brandName());
    return <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr><td style="background:#0f172a;padding:20px 28px;color:#ffffff;font-size:18px;font-weight:600;">{$appName}</td></tr>
        <tr><td style="padding:28px;color:#0f172a;font-size:15px;line-height:1.6;">
          <h2 style="margin:0 0 16px;font-size:20px;">{$heading}</h2>
          {$bodyHtml}
        </td></tr>
        <tr><td style="padding:18px 28px;background:#f8fafc;color:#64748b;font-size:12px;">
          This is an automated message from {$appName}. If it was not expected, you can ignore it.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}

function mailButton($url, $label) {
    $url = sanitize($url);
    $label = sanitize($label);
    return '<p style="margin:24px 0;"><a href="' . $url . '" '
        . 'style="background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 22px;'
        . 'border-radius:8px;display:inline-block;font-weight:600;">' . $label . '</a></p>'
        // Always repeat the link as text: buttons do not survive every client,
        // and a recipient who cannot click has no other way through.
        . '<p style="margin:0;color:#64748b;font-size:13px;word-break:break-all;">'
        . 'Or paste this into your browser:<br>' . $url . '</p>';
}

function mailActivation($name, $link) {
    $html = mailLayout(
        'Confirm your email address',
        '<p>Hello ' . sanitize($name) . ',</p>'
        . '<p>Confirm your email address to activate your ' . sanitize(brandName()) . ' account.</p>'
        . mailButton($link, 'Activate my account')
    );
    $text = "Hello {$name},\n\nConfirm your email address to activate your "
        . brandName() . " account:\n\n{$link}\n\nIf this was not expected, ignore this message.\n";
    return [$html, $text];
}

function mailPasswordReset($name, $link, $expiryHours = 1) {
    $hours = max(1, (int)$expiryHours);
    $window = $hours === 1 ? '1 hour' : $hours . ' hours';
    $html = mailLayout(
        'Reset your password',
        '<p>Hello ' . sanitize($name) . ',</p>'
        . '<p>We received a request to reset your password. This link expires in '
        . sanitize($window) . '.</p>'
        . mailButton($link, 'Choose a new password')
        . '<p style="margin-top:20px;color:#64748b;font-size:13px;">'
        . 'If you did not request this, no action is needed — your password has not changed.</p>'
    );
    $text = "Hello {$name},\n\nReset your password using the link below. "
        . "It expires in {$window}.\n\n{$link}\n\n"
        . "If you did not request this, no action is needed.\n";
    return [$html, $text];
}

// The invitation an admin-created tenant receives (#48).
//
// Deliberately not mailPasswordReset() with different wording. A reset says "if
// you did not request this, ignore it" — exactly the wrong advice here, because
// nobody requested this and ignoring it means never getting an account. The
// recipient has to be told who set it up and what it is for, or a link asking
// them to choose a password reads like phishing.
//
// The expiry is stated in days rather than hours: it is a week, and "168
// hour(s)" is not how anyone reads a week.
function mailTenantInvite($name, $link, $expiryHours = 168) {
    $brand = brandName();
    $days = max(1, (int)round($expiryHours / 24));
    $window = $days === 1 ? '1 day' : $days . ' days';

    $html = mailLayout(
        'Set up your ' . sanitize($brand) . ' account',
        '<p>Hello ' . sanitize($name) . ',</p>'
        . '<p>An account has been created for you on ' . sanitize($brand)
        . '. Choose a password to finish setting it up and sign in.</p>'
        . mailButton($link, 'Choose my password')
        . '<p style="margin-top:20px;color:#64748b;font-size:13px;">'
        . 'This link can only be used once and expires in ' . sanitize($window) . '. '
        . 'If it has already expired, ask whoever set up your account to send a new one.</p>'
    );
    $text = "Hello {$name},\n\nAn account has been created for you on {$brand}. "
        . "Choose a password to finish setting it up:\n\n{$link}\n\n"
        . "This link can only be used once and expires in {$window}. "
        . "If it has already expired, ask whoever set up your account to send a new one.\n";
    return [$html, $text];
}

function mailPlanExpiring($name, $planName, $endDate, $instructions, $billingUrl) {
    $days = (int)ceil((strtotime($endDate) - time()) / 86400);
    $when = $days > 0 ? 'in ' . $days . ($days === 1 ? ' day' : ' days') : 'today';

    $body = '<p>Hello ' . sanitize($name) . ',</p>'
        . '<p>Your <strong>' . sanitize($planName) . '</strong> plan period ends ' . sanitize($when)
        . ' (' . sanitize(date('M j, Y', strtotime($endDate))) . ').</p>';
    if (trim((string)$instructions) !== '') {
        $body .= '<p style="margin:20px 0 8px;font-weight:600;">How to renew</p>'
            // nl2br over an escaped string: admin-authored, but never raw HTML
            // into an email body.
            . '<p style="color:#334155;">' . nl2br(sanitize($instructions)) . '</p>';
    }
    $body .= mailButton($billingUrl, 'View billing');

    $text = "Hello {$name},\n\nYour {$planName} plan period ends {$when} ("
        . date('M j, Y', strtotime($endDate)) . ").\n\n"
        . (trim((string)$instructions) !== '' ? "How to renew:\n{$instructions}\n\n" : '')
        . "Billing: {$billingUrl}\n";

    return [mailLayout('Your plan is expiring', $body), $text];
}

function mailTest() {
    $html = mailLayout(
        'SMTP is working',
        '<p>This is a test message from ' . sanitize(brandName()) . '.</p>'
        . '<p>If you are reading it, the SMTP settings saved in the admin console '
        . 'can successfully deliver mail.</p>'
    );
    return [$html, "This is a test message from " . brandName() . ".\n\n"
        . "If you are reading it, your SMTP settings can deliver mail.\n"];
}
