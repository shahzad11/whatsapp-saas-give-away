<?php
require_once __DIR__ . '/config/init.php';
requireLogin();

$user = getCurrentUser();
$userId = (int)$user['id'];

$error = '';
$success = '';
$passwordError = '';
$passwordSuccess = '';
$fieldErrors = [];

$profile = getUserProfile($conn, $userId);
$timezone = getUserSetting($conn, $userId, 'timezone', appTimezone($conn));

// The phone input is rendered from its own variable because storage and display
// differ: the column holds bare E.164 digits, the field shows a leading '+'. On a
// validation error it holds the raw text instead, so prefixing again would give
// '++92…'.
$whatsappInput = formatPhone($profile['whatsapp_number'] ?? '') ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        $error = 'Invalid request.';
    } else {
        if ($_POST['action'] === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            [$clean, $fieldErrors] = validateProfileInput($_POST);

            // Only name and email are required — everything else is optional, so
            // registration can stay a two-field form.
            if ($name === '' || $email === '') {
                $error = 'Name and email are required.';
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

            if (!$error && !$fieldErrors) {
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

                $success = 'Profile updated successfully.';
                $profile = getUserProfile($conn, $userId);
                $timezone = getUserSetting($conn, $userId, 'timezone', appTimezone($conn));
                $whatsappInput = formatPhone($profile['whatsapp_number'] ?? '') ?? '';
                $user['name'] = $name;
                $user['email'] = $email;
            } else {
                if (!$error) $error = 'Please correct the highlighted fields.';

                // Redisplay exactly what was typed, taken from the raw POST
                // rather than from $clean — validation nulls the offending
                // field, so using $clean would blank the one input the user
                // needs to correct.
                foreach (profileFields() as $field) {
                    if (in_array($field, ['contact_email', 'contact_whatsapp'], true)) {
                        $profile[$field] = isset($_POST[$field]) ? 1 : 0;
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

            if (!password_verify($currentPassword, $row['password'])) {
                $passwordError = 'Current password is incorrect.';
            } elseif (strlen($newPassword) < 8) {
                $passwordError = 'New password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $passwordError = 'Passwords do not match.';
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param('si', $hashed, $userId);
                $stmt->execute();
                $stmt->close();
                logAudit($conn, 'profile.password_change', 'user', $userId);
                $passwordSuccess = 'Password changed successfully.';
            }
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
                <span class="badge bg-light text-dark">Member since <?= date('M Y', strtotime($user['created_at'])) ?></span>
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
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= sanitize($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= sanitize($success) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
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

                    <hr class="my-4">
                    <h6 class="fw-600 mb-3">Contact Preferences</h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="contact_email" id="contactEmail"
                               <?= (int)$profile['contact_email'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="contactEmail">Contact me by email</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="contact_whatsapp" id="contactWhatsapp"
                               <?= (int)$profile['contact_whatsapp'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="contactWhatsapp">Contact me on WhatsApp</label>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Change Password</div>
            <div class="card-body">
                <?php if ($passwordError): ?>
                    <div class="alert alert-danger"><?= sanitize($passwordError) ?></div>
                <?php endif; ?>
                <?php if ($passwordSuccess): ?>
                    <div class="alert alert-success"><?= sanitize($passwordSuccess) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Min. 8 characters" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
