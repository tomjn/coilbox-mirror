# Coilbox mirror

A mirror stores Recoil map and game archives and serves them over plain HTTP. Players download from it, and the Coilbox hub decides who may upload to it.

The hub is a separate website. It keeps a signed catalogue of every archive and every mirror. Each mirror stores a copy of that catalogue and checks its signature with the hub's public keys. A mirror also copies files from the other mirrors listed in the catalogue, so mirrors keep syncing with each other if the hub goes away.

This README covers installing a mirror on ordinary shared hosting and keeping it running. `PROTOCOL.md` describes the formats and HTTP interfaces in detail.

## What you need

- PHP 8.4 or later, with the extensions `sodium`, `hash`, `json`, `openssl` and `mbstring`
- The PHP settings `allow_url_fopen` and `file_uploads` turned on
- Apache 2.4, with `.htaccess` files allowed
- Shell access, so you can run PHP from the command line and add a cron job
- A domain or subdomain for the mirror
- Disk space for the archives

## Set up on shared hosting

1. Point a domain or subdomain at an empty folder on the server.

   Give the mirror its own domain or subdomain. The `.htaccess` file in the root folder only lets visitors reach `upload.php` and `limits.php` at the top of the domain. To install in a subfolder instead, edit the `m#...#` pattern near the end of the first section of the root `.htaccess`, as its comment explains.

2. Put the deployable files in that folder.

   Upload these:

   - `.htaccess`
   - `upload.php`
   - `limits.php`
   - `sync.php`
   - `src/`
   - `bin/`
   - `private/`
   - `maps/`
   - `games/`
   - `.partial/`

   Keep these off the server: `vendor/`, `tests/`, `fixtures/`, `composer.json`, `composer.lock`, `phpunit.xml.dist` and `.git`. `PROTOCOL.md` and this README are optional.

   If you prefer `git clone`, clone into a folder outside the web folder. Then copy the files listed above into the web folder. A clone inside the web folder would also expose `tests/` and the other files you should keep off the server.

3. Copy `private/config.sample.php` to `private/config.php`.

   ```
   cp private/config.sample.php private/config.php
   ```

4. Set `mirror_id` in `private/config.php`.

   The hub operator gives you the ID. It is a short name of lowercase letters, digits and hyphens.

5. Make the mirror's signing key.

   ```
   php bin/keygen.php
   ```

   The script prints a `secret_key` line and a public key. Paste the `secret_key` line into `private/config.php`. Keep it secret. The script writes no files.

6. Send the hub operator three things: the public key from step 5, your mirror ID and the mirror's base URL, such as `https://mirror.example.org`.

7. Replace the `PASTE_THE_HUB_PUBLIC_KEY_HERE` entry in `hub_keys` with the hub public key the operator gives you. Check that `hub_url` is correct.

8. Check the setup.

   ```
   php bin/check.php
   php bin/check.php --url=https://mirror.example.org
   ```

   The first command checks PHP, the extensions, the settings and the folders. The second command also requests your mirror over HTTP to check what the web server exposes. It tries to download `private/canary.txt`, a file that must never be downloadable, so a successful download shows the private folder is exposed.

   Each problem prints on its own line starting with `problem:`, and the command exits with status 1. When everything passes, it prints `ok` and the upload chunk size. Fix every problem before you go on. Use `--root=<folder>` to check a folder other than the one the script sits in. Use `--probe-file=maps/<name>` together with `--url` to test downloading a file you already hold.

## Add the cron job

The mirror syncs with the hub and other mirrors when cron runs `sync.php`. Add an hourly job.

```
0 * * * * /usr/local/php85/bin/php /home/youruser/mirror.example.org/sync.php
```

Use the full path to a PHP binary that is 8.4 or later, and the full path to `sync.php`. Any interval is safe. If a run is still going when the next one starts, the new run stops straight away.

The plain `php` on a shared host can be older than the version you chose for the website. On DreamHost, plain `php` was 8.2 while the site ran 8.5, and the 8.5 binary was `/usr/local/php85/bin/php`. Run `php -v` to see what plain `php` is, and ask your host where the other versions live.

DreamHost users can add the job in the panel's cron section. Enter the same command.

## What the mirror serves

- `maps/` and `games/` hold the archives
- `listing.json` lists every file in `maps/` and `games/` with its size and SHA-256 hash
- `catalogue.json` and `catalogue.sig` are the hub's signed catalogue and its signature
- `limits.php` tells uploaders how large each upload chunk may be
- `upload.php` accepts uploads from people the hub has approved

The mirror creates `listing.json`, `catalogue.json` and `catalogue.sig` itself. The web server must be able to write to `maps/`, `games/`, `.partial/` and `private/`.

## Keep it running

A quiet `sync.php` run prints nothing when all is well, so cron sends no email. Any output is a problem, written to standard error. Examples are a failed download, a hash mismatch, a catalogue that fails verification and `sync report failed`. A failed report means the sync finished but the hub did not accept the mirror's report. Check that `hub_url` is right, that the hub has your public key and that the server can make outgoing HTTPS requests. The next sync sends the report again.

To see everything a run does, run it by hand with `--verbose`.

```
php sync.php --verbose
```

To see how fresh the mirror's copy of the catalogue is, run `php bin/check.php`. It prints the catalogue sequence number and the time the hub generated it.

The mirror adds a new hub key by itself when the hub publishes one in a signed catalogue. If a mirror missed that and stops accepting catalogues, add the key by hand. Open `private/config.php`, add the new public key as another line in `hub_keys`, keep the old keys, and run `php sync.php --verbose` to confirm. The hub operator tells you when this is needed.

## Development

Install PHPUnit with Composer, then run the tests.

```
composer install
vendor/bin/phpunit
```

The tests need PHP 8.4 or later. `PROTOCOL.md` describes every format and HTTP interface, and `fixtures/` holds the signed example files the tests use. Run `php bin/make-fixtures.php` to regenerate them.
