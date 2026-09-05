<?php

// Authenticated encryption for secrets that must live in the database — at the
// moment just the SMTP password.
//
// Uses libsodium's secretbox (XSalsa20-Poly1305), which is bundled in the
// frontend image (verified: `sodium` is in the container's module list). It is
// authenticated, so a tampered ciphertext fails to decrypt rather than
// returning garbage, and it has no mode/padding footguns to get wrong.
//
// The key is derived, never used raw: HKDF-SHA256 over the instance secret with
// a fixed context string. Deriving means the same secret can later back other
// purposes without those sharing key material.

function cryptoSecretAvailable() {
    return extension_loaded('sodium') && cryptoInstanceSecret() !== null;
}

// APP_SECRET_KEY if set, otherwise BACKEND_API_KEY.
//
// Falling back avoids forcing a new required variable onto every existing
// deployment. The trade-off is explicit: rotating BACKEND_API_KEY makes a
// stored SMTP password undecryptable. That degrades to "SMTP not configured"
// with a clear prompt to re-enter it — never to a fatal, and never to sending
// mail with a wrong credential.
function cryptoInstanceSecret() {
    $secret = env('APP_SECRET_KEY');
    if ($secret === null || $secret === '') {
        $secret = defined('BACKEND_API_KEY') ? BACKEND_API_KEY : null;
    }
    if ($secret === null || strlen($secret) < 16) return null;
    return $secret;
}

function cryptoKey($context = 'app-secret-v1') {
    $secret = cryptoInstanceSecret();
    if ($secret === null) return null;
    return hash_hkdf('sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $context);
}

// Returns "v1:<base64(nonce||ciphertext)>", or null if encryption is impossible.
// A null return must be treated as "cannot store this secret", never as "store
// it in the clear".
function encryptSecret($plaintext, $context = 'app-secret-v1') {
    if ($plaintext === null || $plaintext === '') return null;

    $key = cryptoKey($context);
    if ($key === null) return null;

    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

    // Version prefix so the format can change without ambiguity about what a
    // stored blob is.
    return 'v1:' . base64_encode($nonce . $cipher);
}

// Returns the plaintext, or null when the blob is absent, malformed, or was
// encrypted under a different key (e.g. the instance secret was rotated).
function decryptSecret($stored, $context = 'app-secret-v1') {
    if ($stored === null || $stored === '') return null;
    if (!str_starts_with($stored, 'v1:')) return null;

    $key = cryptoKey($context);
    if ($key === null) return null;

    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;

    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    try {
        // Returns false on a failed MAC check; it does not throw for that.
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    } catch (Throwable $e) {
        return null;
    }
    return $plain === false ? null : $plain;
}
