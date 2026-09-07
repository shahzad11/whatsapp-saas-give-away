<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$user = getCurrentUser();
$userId = (int)$user['id'];

$fieldErrors = [];

// Both handlers below end at formRespond()/formErrors(), so a fetch() submit gets
// JSON and a plain submit behaves as it always did — see includes/ajax.php. The
// page-level message is a flash now rather than a local variable: the success
// path redirects, and a local variable does not survive a redirect.
$self = APP_URL . '/profile.php';

$profile = getUserProfile($conn, $userId);
$timezone = getUserSetting($conn, $userId, 'timezone', appTimezone($conn));

// The phone input is rendered from its own variable because storage and display
// differ: the column holds bare E.164 digits, the field shows a leading '+'. On a
// validation error it holds the raw text instead, so prefixing again would give
// '++92…'.
$whatsappInput = formatPhone($profile['whatsapp_number'] ?? '') ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    formRequireCsrf($self);

    if ($_POST['action'] === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        [$clean, $fieldErrors] = validateProfileInput($_POST);

        // Only name and email are required — everything else is optional, so
        // registration can stay a two-field form. Both are named as field
        // errors rather than as one page-level sentence, because "name and
        // email are required" left the tenant to work out which of the two
        // they had actually missed.
        if ($name === '') $fieldErrors['name'] = 'Enter your name.';
        if ($email === '') {
            $fieldErrors['email'] = 'Enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['email'] = 'Enter a valid email address.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param('si', $email, $userId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $fieldErrors['email'] = 'This email is already in use.';
            }
            $stmt->close();
        }

        $submittedTz = trim($_POST['timezone'] ?? '');
        if ($submittedTz !== '' && !isValidTimezone($submittedTz)) {
            $fieldErrors['timezone'] = 'Select a timezone from the list.';
        }

        if (!$fieldErrors) {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->bind_param('ssi', $name, $email, $userId);
            $stmt->execute();
            $stmt->close();

            saveUserProfile($conn, $userId, $clean);
            if ($submittedTz !== '') {
                setUserSetting($conn, $userId, 'timezone', $submittedTz);
            }

            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            logAudit($conn, 'profile.update', 'user', $userId);

            formRespond(true, 'Profile updated successfully.', $self);
        }

        // formErrors(), not formRespond(): the plain-form path has to fall
        // through to the render below, and a redirect would throw away
        // everything the tenant typed — which is what the redisplay block
        // underneath exists to prevent.
        formErrors('Please correct the highlighted fields.', $fieldErrors);

        // Redisplay exactly what was typed, taken from the raw POST
        // rather than from $clean — validation nulls the offending
        // field, so using $clean would blank the one input the user
        // needs to correct.
        foreach (profileFields() as $field) {
            if (in_array($field, ['contact_email', 'contact_whatsapp'], true)) {
                continue; // no longer rendered, so nothing to redisplay
            } elseif ($field === 'whatsapp_number') {
                continue; // rendered from $whatsappInput, see above
            } elseif (array_key_exists($field, $_POST)) {
                $profile[$field] = trim((string)$_POST[$field]);
            }
        }
        $whatsappInput = trim((string)($_POST['whatsapp_number'] ?? ''));
        if ($submittedTz !== '') $timezone = $submittedTz;
        $user['name'] = $name;
        $user['email'] = $email;
    }

    if ($_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // formErrors() again, for the same reason as above: a password form has
        // nothing worth keeping, but the three checks below are written as a
        // chain that ends in the save, and a redirect from the middle of it
        // would be a different control flow to the one that is tested here.
        if (!password_verify($currentPassword, $row['password'])) {
            $fieldErrors = ['current_password' => 'This is not your current password.'];
            formErrors('Current password is incorrect.', $fieldErrors);
        } elseif (strlen($newPassword) < 8) {
            $fieldErrors = ['new_password' => 'At least 8 characters.'];
            formErrors('New password must be at least 8 characters.', $fieldErrors);
        } elseif ($newPassword !== $confirmPassword) {
            $fieldErrors = ['confirm_password' => 'This does not match the new password.'];
            formErrors('Passwords do not match.', $fieldErrors);
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hashed, $userId);
            $stmt->execute();
            $stmt->close();
            logAudit($conn, 'profile.password_change', 'user', $userId);
            formRespond(true, 'Password changed successfully.', $self);
        }
    }
}

// Renders the inline validation message for a field, and the class that marks it.
function fieldError($key) {
    global $fieldErrors;
    if (empty($fieldErrors[$key])) return '';
    return '<div class="invalid-feedback d-block">' . sanitize($fieldErrors[$key]) . '</div>';
}
function fieldClass($key) {
    global $fieldErrors;
    return empty($fieldErrors[$key]) ? '' : ' is-invalid';
}

$pageTitle = 'Profile';
require_once __DIR__ . '/includes/header.php';
?>

<?php // The page-level message is the layout's job now (includes/flash.php) —
      // it matters here because both cards post to this same page and either one
      // of them can be the sender. The per-field messages below still say which
      // input is at fault. ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-center mb-4">
            <div class="card-body py-4">
                <div class="user-avatar-lg mx-auto mb-3">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <h5 class="fw-600 mb-1"><?= sanitize(tenantDisplayName($user, $profile)) ?></h5>
                <?php if (trim((string)$profile['company_name']) !== ''): ?>
                    <p class="text-muted small mb-1"><?= sanitize($user['name']) ?></p>
                <?php endif; ?>
                <p class="text-muted small mb-2"><?= sanitize($user['email']) ?></p>
                <span class="badge bg-light text-dark">Member since <?= sanitize(formatUserDate($user['created_at'], $timezone, 'M Y')) ?></span>
            </div>
        </div>

        <?php if (hasProfileDetail($profile)): ?>
        <div class="card">
            <div class="card-header">Contact Card</div>
            <div class="card-body small">
                <?php if ($profile['whatsapp_number']): ?>
                    <div class="mb-2">
                        <i class="bi bi-whatsapp text-success me-2"></i><?= sanitize(formatPhone($profile['whatsapp_number'])) ?>
                    </div>
                <?php endif; ?>
                <?php $lines = addressLines($profile); ?>
                <?php if ($lines): ?>
                    <div class="d-flex gap-2">
                        <i class="bi bi-geo-alt text-muted"></i>
                        <div class="text-muted">
                            <?= implode('<br>', array_map('sanitize', $lines)) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Profile Information</div>
            <div class="card-body">
                <form method="POST" data-ajax novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control<?= fieldClass('name') ?>"
                                   value="<?= sanitize($user['name']) ?>" maxlength="100" required>
                            <?= fieldError('name') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control<?= fieldClass('email') ?>"
                                   value="<?= sanitize($user['email']) ?>" maxlength="255" required>
                            <?= fieldError('email') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" class="form-control<?= fieldClass('company_name') ?>"
                                   value="<?= sanitize($profile['company_name'] ?? '') ?>" maxlength="150">
                            <div class="form-text">Used on billing and as the chatbot identity when set.</div>
                            <?= fieldError('company_name') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">WhatsApp Business Number</label>
                            <input type="tel" name="whatsapp_number" class="form-control<?= fieldClass('whatsapp_number') ?>"
                                   value="<?= sanitize($whatsappInput) ?>"
                                   placeholder="+92 300 1234567">
                            <div class="form-text">International format. Spaces and dashes are fine.</div>
                            <?= fieldError('whatsapp_number') ?>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="fw-600 mb-3">Postal Address</h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Street Address</label>
                            <input type="text" name="address_line1" class="form-control<?= fieldClass('address_line1') ?>"
                                   value="<?= sanitize($profile['address_line1'] ?? '') ?>" maxlength="200">
                            <?= fieldError('address_line1') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Apartment, suite, etc.</label>
                            <input type="text" name="address_line2" class="form-control<?= fieldClass('address_line2') ?>"
                                   value="<?= sanitize($profile['address_line2'] ?? '') ?>" maxlength="200">
                            <?= fieldError('address_line2') ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control<?= fieldClass('city') ?>"
                                   value="<?= sanitize($profile['city'] ?? '') ?>" maxlength="100">
                            <?= fieldError('city') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State / Region</label>
                            <input type="text" name="state_region" class="form-control<?= fieldClass('state_region') ?>"
                                   value="<?= sanitize($profile['state_region'] ?? '') ?>" maxlength="100">
                            <?= fieldError('state_region') ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Postal / ZIP</label>
                            <input type="text" name="postal_code" class="form-control<?= fieldClass('postal_code') ?>"
                                   value="<?= sanitize($profile['postal_code'] ?? '') ?>" maxlength="20">
                            <?= fieldError('postal_code') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country</label>
                            <select name="country" class="form-select<?= fieldClass('country') ?>">
                                <option value="">— Not set —</option>
                                <?php foreach (countryOptions() as $code => $label): ?>
                                    <option value="<?= $code ?>" <?= ($profile['country'] ?? '') === $code ? 'selected' : '' ?>>
                                        <?= sanitize($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= fieldError('country') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Timezone</label>
                            <select name="timezone" class="form-select<?= fieldClass('timezone') ?>">
                                <?php foreach (timezoneChoices() as $region => $zones): ?>
                                    <optgroup label="<?= sanitize($region) ?>">
                                    <?php foreach ($zones as $tz => $label): ?>
                                        <option value="<?= sanitize($tz) ?>" <?= $timezone === $tz ? 'selected' : '' ?>>
                                            <?= sanitize($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Chat timestamps use this (<?= sanitize(timezoneOffsetLabel($timezone)) ?>).
                                Defaults to the platform timezone.
                            </div>
                            <?= fieldError('timezone') ?>
                        </div>
                    </div>

                    <?php // No "Contact Preferences" switches here any more. They
                          // were stored on user_profiles and read by nothing:
                          // activation, password reset and handoff notifications
                          // all go to the account email regardless. Honouring
                          // them needs preference-aware routing in the mailer,
                          // which does not exist yet, so the controls are gone
                          // rather than silently ignored. The columns remain. ?>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Change Password</div>
            <div class="card-body">
                <form method="POST" data-ajax>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control<?= fieldClass('current_password') ?>" required>
                        <?= fieldError('current_password') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control<?= fieldClass('new_password') ?>" placeholder="Min. 8 characters" required>
                        <?= fieldError('new_password') ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control<?= fieldClass('confirm_password') ?>" required>
                        <?= fieldError('confirm_password') ?>
                    </div>
                    <?php // No data-confirm on either form on this page. Nothing here
                          // destroys anything: a profile save is an edit the tenant can
                          // simply make again, and a password change already requires the
                          // current password, which is a stronger check than a dialog. ?>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
