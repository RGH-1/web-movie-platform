export function validateNewPassword(password, confirmation) {
    const errors = {};
    if (Array.from(password).length < 12 || !/\p{Ll}/u.test(password) || !/\p{Lu}/u.test(password) ||
        !/\p{N}/u.test(password) || !/[\p{Z}\p{S}\p{P}]/u.test(password)) {
        errors.password = ['Use at least 12 characters with uppercase, lowercase, a number, and a symbol.'];
    }
    if (new TextEncoder().encode(password).length > 72 || password.includes('\0')) {
        errors.password = ['Use at most 72 UTF-8 bytes and no NUL character.'];
    }
    if (!confirmation || password !== confirmation) errors.password_confirmation = ['The passwords must match.'];
    return errors;
}

export function validRecoveryEmail(email) {
    return email.length <= 255 && /^[^\s@]+@[^\s@]+$/.test(email);
}
