# Coilbox mirror

A mirror stores Recoil map and game archives and serves them over plain HTTP. Players download from it, and the Coilbox hub decides who may upload to it.

The hub is a separate website. It keeps a signed catalogue of every archive and every mirror. Each mirror stores a copy of that catalogue and checks its signature with the hub's public keys. A mirror also copies files from the other mirrors listed in the catalogue, so mirrors keep syncing with each other if the hub goes away.

This README covers installing a mirror on ordinary shared hosting or on your own server with nginx, and keeping it running. `PROTOCOL.md` describes the formats and HTTP interfaces in detail.

## Run a mirror

Anyone can offer a mirror. The hub only sends players and uploads to mirrors it lists, so the hub operator has to add yours.

1. Open an issue on [tomjn/coilbox-mirror](https://github.com/tomjn/coilbox-mirror/issues) to ask for a mirror ID. Say where the mirror will live and roughly how much disk space you can give it.
2. Install the mirror, either on [shared hosting with Apache](#set-up-on-shared-hosting) or [on nginx](#set-up-on-nginx).
3. Send the hub operator your public key, mirror ID and base URL, and add the hub's public key to your config.
4. Run the checks, then [add the cron job](#add-the-cron-job) that keeps the mirror in sync.
5. [Put your name on the listing pages](#put-your-name-on-the-listing-pages).

## What you need

- PHP 8.4 or later, with the extensions `sodium`, `hash`, `json`, `openssl` and `mbstring`
- The PHP settings `allow_url_fopen` and `file_uploads` turned on
- Apache 2.4 with `.htaccess` files allowed, or nginx with PHP-FPM
- Shell access, so you can run PHP from the command line and add a cron job
- A domain or subdomain for the mirror
- Disk space for the archives

## Set up on shared hosting

1. Point a domain or subdomain at an empty folder on the server.

   Give the mirror its own domain or subdomain. The `.htaccess` file in the root folder only lets visitors reach `upload.php` and `limits.php` at the top of the domain. To install in a subfolder instead, edit the `m#...#` pattern near the end of the first section of the root `.htaccess`, as its comment explains.

2. Put the deployable files in that folder.

   Upload these:

   - `.htaccess`
   - `listing.css`
   - `listing-header.sample.html`
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

   Each problem prints on its own line starting with `problem:`, and the command exits with status 1. When everything passes, it prints `ok` and the upload chunk size. Fix every problem before you go on.

   The check also prints `no catalogue held` until the first sync succeeds. That is normal on a new mirror and is not a problem. Use `--root=<folder>` to check a folder other than the one the script sits in. Use `--probe-file=maps/<name>` together with `--url` to test downloading a file you already hold.

## Set up on nginx

nginx ignores the `.htaccess` files, so its site config has to do the same job. `examples/nginx.conf` is a complete site that does.

1. Follow steps 1 to 7 of the shared hosting setup above. The `.htaccess` files can stay. nginx does not read them.
2. Copy `examples/nginx.conf` to `/etc/nginx/sites-available/` and link it into `sites-enabled`.
3. Change `server_name`, `root`, the certificate paths and the `fastcgi_pass` socket to match your server. The comment at the top of the file lists them.
4. Set `client_max_body_size` to at least PHP-FPM's `post_max_size`. If it is lower, nginx refuses upload chunks that the mirror told uploaders it would accept.
5. Run `sudo nginx -t`, then `sudo systemctl reload nginx`.
6. Run both checks from step 8 of the shared hosting setup. `--url` tests the nginx rules the same way it tests the `.htaccess` ones.

The listing header uses nginx's `sub` and `ssi` modules. The packages from nginx.org and the `nginx` package on Debian and Ubuntu include both. If `nginx -t` reports an unknown `sub_filter` directive, delete the four `sub_filter` and `ssi` lines and the listings stay plain.

PHP-FPM must run as the same user as cron, or both must be able to write to `private/state/`, as described in the next section.

## Add the cron job

Before you add the job, run one sync by hand, as the same user cron will use.

```
php sync.php --verbose
```

It prints each step. The first run fetches the catalogue, so `no catalogue held` should go away. Fix anything it reports as failed before you rely on cron, because cron only emails you about problems.

Cron and the web server must run as the same user, or both must be able to write to `private/state/`. The mirror creates that folder with mode 0700, so only the user that created it can write to it. If your host runs them as different users, change the folder's group and permissions yourself.

The mirror syncs with the hub and other mirrors when cron runs `sync.php`. Add an hourly job.

```
0 * * * * /usr/local/php85/bin/php /home/youruser/mirror.example.org/sync.php
```

Use the full path to a PHP binary that is 8.4 or later, and the full path to `sync.php`. Any interval is safe. If a run is still going when the next one starts, the new run stops straight away.

Command-line PHP usually has no time limit, so a peer that sends data very slowly could hold up a run for good, and every later run would stop straight away without telling you. To guard against this, set `sync_time_limit` in `private/config.php` to the longest a run may take, in seconds. Pick a value shorter than your cron interval. A run checks the limit before each archive download and starts no new download once it has passed. The limit does not cover fetching the catalogue or peer listings, so a peer that is slow to send those can still keep a run going past it. If a later run finds an older one still going past the limit, it prints `sync has been running for over N seconds`, so cron emails you. The default of 0 means no limit.

The plain `php` on a shared host can be older than the version you chose for the website. On DreamHost, plain `php` was 8.2 while the site ran 8.5, and the 8.5 binary was `/usr/local/php85/bin/php`. Run `php -v` to see what plain `php` is, and ask your host where the other versions live.

DreamHost users can add the job in the panel's cron section. Enter the same command.

## Put your name on the listing pages

The `maps/` and `games/` pages list every archive. They carry the Coilbox Hub heading, a link back to the hub and a line thanking you for hosting the mirror.

Copy `listing-header.sample.html` to `listing-header.html`, then put your name and link in the line that starts `Thanks to`.

```
cp listing-header.sample.html listing-header.html
```

Without `listing-header.html` the pages still list the files and keep the hub's colours, but have no heading. Updating the mirror leaves your copy alone, because only the sample ships with it.

On Apache the listing style needs `.htaccess` files to be allowed to set `Indexes` options. If `maps/` answers 500 after an update, your host does not allow that. Ask them to, or delete the four `Index` and `HeaderName` lines from `maps/.htaccess` and `games/.htaccess`.

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

The mirror keeps the sequence number of the catalogue it holds in `private/state/catalogue_seq`, so nobody can swap in an older catalogue through the web folder. If a run reports that `catalogue_seq` is damaged, or missing while `catalogue.json` cannot be read, the mirror refuses every new catalogue until you fix it. Delete `private/state/catalogue_seq`, `catalogue.json` and `catalogue.sig`, then run `php sync.php --verbose` to fetch a fresh catalogue.

The mirror adds a new hub key by itself when the hub publishes one in a signed catalogue. If a mirror missed that and stops accepting catalogues, add the key by hand. Open `private/config.php`, add the new public key as another line in `hub_keys`, keep the old keys, and run `php sync.php --verbose` to confirm. The hub operator tells you when this is needed.

## Development

Install PHPUnit with Composer, then run the tests.

```
composer install
vendor/bin/phpunit
```

The tests need PHP 8.4 or later. `PROTOCOL.md` describes every format and HTTP interface, and `fixtures/` holds the signed example files the tests use. Run `php bin/make-fixtures.php` to regenerate them.
