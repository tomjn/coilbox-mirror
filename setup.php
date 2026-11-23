<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mirror\Config;
use Mirror\ConfigError;
use Mirror\Paths;
use Mirror\Setup;

// Sets up the mirror the first time it is opened in a browser, then only shows the link to ask the hub to add it.

$paths = new Paths(__DIR__);
$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** Shows the page for $state, with what it needs in $data, and stops. */
$page = static function (string $state, array $data = []) use ($h): never {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $link = $data['link'] ?? null;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Set up this Coilbox mirror</title>
<style>
body { font: 16px/1.5 system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
label { display: block; margin-top: 1rem; font-weight: 600; }
input { display: block; width: 100%; box-sizing: border-box; padding: .5rem; margin-top: .25rem; font: inherit; }
.hint { font-weight: normal; color: #555; font-size: .9rem; }
.error { color: #a00000; font-weight: normal; }
button, .button { display: inline-block; margin-top: 1.5rem; padding: .6rem 1.2rem; font: inherit; background: #1a4fa0; color: #fff; border: 0; border-radius: 4px; text-decoration: none; cursor: pointer; }
button:focus-visible, .button:focus-visible, input:focus-visible { outline: 3px solid #f5b400; outline-offset: 2px; }
code { background: #f0f0f0; padding: 0 .25rem; word-break: break-all; }
</style>
</head>
<body>
<main>
<h1>Set up this Coilbox mirror</h1>
<?php if ($state === 'form'): ?>
<p>This page writes the mirror's settings and makes its signing key. It works once. After that it only shows the link to ask the hub to add this mirror.</p>
<?php if (($data['errors'] ?? []) !== []): ?>
<p class="error" role="alert">Some details need changing. Nothing was saved.</p>
<?php endif; ?>
<form method="post">
<?php foreach ([
    'mirror_id' => ['ID', 'Lowercase letters, digits and hyphens. The hub knows the mirror by this.', 'autocomplete="off" spellcheck="false" maxlength="64"'],
    'mirror_name' => ['Name', 'What players see, such as the mirror\'s address.', 'autocomplete="off" maxlength="128"'],
    'base_url' => ['Address', 'The https:// address of this mirror, without setup.php.', 'type="url" autocomplete="off"'],
] as $field => [$label, $hint, $attributes]): ?>
<label for="<?= $field ?>"><?= $h($label) ?> <span class="hint"><?= $h($hint) ?></span>
<?php if (isset($data['errors'][$field])): ?><span class="error" id="<?= $field ?>-error"><?= $h($data['errors'][$field]) ?></span><?php endif; ?>
</label>
<input id="<?= $field ?>" name="<?= $field ?>" required <?= $attributes ?> value="<?= $h($data['values'][$field] ?? '') ?>"<?= isset($data['errors'][$field]) ? ' aria-invalid="true" aria-describedby="' . $field . '-error"' : '' ?>>
<?php endforeach; ?>
<button type="submit">Set up the mirror</button>
</form>
<?php elseif ($state === 'not_writable'): ?>
<p class="error" role="alert">This page cannot save the settings, because PHP cannot write to the <code>private</code> folder.</p>
<p>Let the web server write to <code>private</code>, for example with <code>chmod 750 private</code> on shared hosting, then reload this page. Or set the mirror up by hand from <code>private/config.sample.php</code>, as the README explains.</p>
<?php elseif ($state === 'broken'): ?>
<p class="error" role="alert">This mirror has a settings file, but it has a problem: <?= $h($data['problem']) ?></p>
<p>Fix <code>private/config.php</code>, or delete it and reload this page to start again.</p>
<?php else: ?>
<?php if ($state === 'done'): ?>
<p role="status">The mirror is set up and has its own signing key. The secret half stays in <code>private/config.php</code>. Never share it.</p>
<?php else: ?>
<p>This mirror is already set up. If you did not set it up, delete <code>private/config.php</code> and reload this page to start again.</p>
<?php endif; ?>
<?php if ($link !== null): ?>
<h2>Ask the hub to add it</h2>
<p>Open this link while signed in to the hub, check the details and send the request. A hub moderator then approves it.</p>
<p><a class="button" href="<?= $h($link) ?>">Ask the hub to add this mirror</a></p>
<p class="hint">The link again, to copy: <code><?= $h($link) ?></code></p>
<h2>Then</h2>
<p>Add a cron job that runs <code>php sync.php</code> in this folder every hour. Until a moderator approves the mirror, the job prints <code>not approved by the hub yet</code>.</p>
<?php else: ?>
<p>To ask the hub to add it, add <code>base_url</code> and <code>mirror_name</code> to <code>private/config.php</code>, as the README explains, then reload this page.</p>
<?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
<?php
    exit;
};

if (is_file($paths->config())) {
    try {
        $page('set_up', ['link' => Setup::registrationLink(Config::load($paths))]);
    } catch (ConfigError $e) {
        $page('broken', ['problem' => $e->getMessage()]);
    }
}

if (!is_writable($paths->private())) {
    $page('not_writable');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $page('form', ['values' => ['base_url' => Setup::baseUrlFromServer($_SERVER)]]);
}

$checked = Setup::validate($_POST);
if ($checked['errors'] !== []) {
    $page('form', $checked);
}

// False means someone else finished setup between the check above and this write.
$state = Setup::write($paths, Setup::configSource($checked['values'], Setup::newSecretKey())) ? 'done' : 'set_up';
try {
    $page($state, ['link' => Setup::registrationLink(Config::load($paths))]);
} catch (ConfigError $e) {
    $page('broken', ['problem' => $e->getMessage()]);
}
