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

// The one message every page shows when a secret cannot be stored.
//
// "Set APP_SECRET_KEY." was a dead end: it names a variable without saying where
// it lives, that a fallback exists, or that this is a server-side file an admin
// may not be able to reach from the browser they are reading the message in. The
// two causes are also genuinely different — a missing variable is fixable by
// editing a file, a missing PHP extension needs a rebuilt image — so they are
// distinguished rather than collapsed into one sentence.
function cryptoSecretMissingMessage() {
    if (!extension_loaded('sodium')) {
        return 'Secrets cannot be encrypted on this server: PHP is missing the sodium extension. '
             . 'The frontend container image normally includes it — this instance needs to be rebuilt or repaired '
             . 'before API keys or SMTP passwords can be saved.';
    }
    return 'Secrets cannot be encrypted because this instance has no secret key. '
         . 'APP_SECRET_KEY is set in the .env file next to docker-compose.yml on the server, and BACKEND_API_KEY '
         . 'is used as a fallback when it is absent — so both being missing or shorter than 16 characters is what '
         . 'produces this. Contact whoever administers the server if you cannot edit that file; once it is set, '
         . 'the containers have to be restarted. Do not change either value on a working instance: '
         . 'everything already stored was encrypted with the old one and would become unreadable.';
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
