<?php

// Opt-in integration check against the local database and Mailpit, never production.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

if (! $app->environment('local') || config('mail.default') !== 'smtp'
    || config('mail.mailers.smtp.host') !== '127.0.0.1'
    || (int) config('mail.mailers.smtp.port') !== 1025) {
    throw new RuntimeException('Requires local environment and Mailpit SMTP on 127.0.0.1:1025.');
}

$user = App\Models\User::create([
    'name' => 'Task 76 SMTP verification',
    'email' => 'task76-'.bin2hex(random_bytes(6)).'@example.test',
    'password' => null,
]);

$post = static function (string $path, array $data) use ($kernel): int {
    $request = Illuminate\Http\Request::create('/api/v1/auth/'.$path, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '192.0.2.76',
    ], json_encode($data, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);

    return $status;
};

try {
    if ($post('forgot-password', ['email' => $user->email]) !== 200) {
        throw new RuntimeException('Forgot-password request failed.');
    }
    $inbox = json_decode(file_get_contents('http://127.0.0.1:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
    $received = null;
    foreach ($inbox['messages'] as $message) {
        foreach ($message['To'] as $recipient) {
            if ($recipient['Address'] === $user->email) {
                $received = $message;
                break 2;
            }
        }
    }
    if (! $received) {
        throw new RuntimeException('Reset email was not received in Mailpit.');
    }
    $mail = json_decode(file_get_contents('http://127.0.0.1:8025/api/v1/message/'.$received['ID']), true, 512, JSON_THROW_ON_ERROR);
    $html = html_entity_decode($mail['HTML'], ENT_QUOTES | ENT_HTML5);
    preg_match_all('/href="([^"]+)"/', $html, $links);
    $credentials = null;
    foreach ($links[1] as $link) {
        if (str_starts_with($link, config('auth.password_reset_url').'?')) {
            parse_str(parse_url($link, PHP_URL_QUERY), $credentials);
            break;
        }
    }
    if (! $credentials || ($credentials['email'] ?? null) !== $user->email || empty($credentials['token'])) {
        throw new RuntimeException('Email does not contain the configured frontend reset URL.');
    }
    $password = 'Verify!'.bin2hex(random_bytes(16));
    $credentials += ['password' => $password, 'password_confirmation' => $password];
    if ($post('reset-password', $credentials) !== 200
        || ! Illuminate\Support\Facades\Hash::check($password, $user->fresh()->password)) {
        throw new RuntimeException('Reset using the delivered email failed.');
    }
    if ($post('reset-password', $credentials) !== 422) {
        throw new RuntimeException('Delivered reset token was reusable.');
    }
    echo "PASS: local SMTP email received, frontend URL verified, password reset succeeded, token reuse rejected.\n";
    echo "Mailpit inbox: http://127.0.0.1:8025\n";
} finally {
    Illuminate\Support\Facades\Password::deleteToken($user);
    $user->delete();
}
