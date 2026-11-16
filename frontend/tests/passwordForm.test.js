import test from 'node:test';
import assert from 'node:assert/strict';
import { validateNewPassword, validRecoveryEmail } from '../src/utils/passwordForm.js';

test('password strength, confirmation, bytes and exact spaces match the password API', () => {
    for (const value of ['short', 'alllowercase123!', 'ALLUPPERCASE123!', 'NoNumbersHere!', 'NoSymbolsHere123', 'Aa1!'.repeat(19), 'ValidPass123!\0']) {
        assert.ok(validateNewPassword(value, value).password);
    }
    assert.deepEqual(validateNewPassword(' SecureNew!123 ', ' SecureNew!123 '), {});
    assert.ok(validateNewPassword('SecureNew!123', 'different').password_confirmation);
    assert.deepEqual(validateNewPassword('Éé123456789!', 'Éé123456789!'), {});
});

test('recovery email validation accepts local domains while rejecting missing addresses', () => {
    assert.ok(validRecoveryEmail('sam@localhost'));
    assert.ok(validRecoveryEmail('sam@example.com'));
    assert.equal(validRecoveryEmail('missing'), false);
    assert.equal(validRecoveryEmail('a b@example.com'), false);
});
