# Coilbox mirror

A mirror stores Recoil map and game archives, and engine test builds, and serves them over plain HTTP. Players download from it, and the Coilbox hub decides who may upload to it.

The hub is a separate website. It keeps a signed catalogue of every archive and every mirror. Each mirror stores a copy of that catalogue and checks its signature with the hub's public keys. A mirror also copies files from the other mirrors listed in the catalogue, so mirrors keep syncing with each other if the hub goes away.

This README covers installing a mirror on ordinary shared hosting or on your own server with nginx, and keeping it running. `PROTOCOL.md` describes the formats and HTTP interfaces in detail.

## Run a mirror

Anyone can offer a mirror. The hub only sends players and uploads to mirrors it lists, so the hub operator has to add yours.

1. Install the mirror, either on [shared hosting with Apache](#set-up-on-shared-hosting) or [on nginx](#set-up-on-nginx).
2. Open `setup.php` in your browser. It writes the config, makes the mirror's key and gives you a link.
3. Open the link while signed in to the hub and send the request. A hub moderator approves it.
4. Run the checks, then [add the cron job](#add-the-cron-job) that keeps the mirror in sync.
5. [Put your name on the listing pages](#put-your-name-on-the-listing-pages).

## What you need

- PHP 8.4 or later, with the extensions `sodium`, `hash`, `json`, `openssl` and `mbstring`
- The PHP settings `allow_url_fopen` and `file_uploads` turned on
- Apache 2.4 with `.htaccess` files allowed, or nginx with PHP-FPM
- Shell access, so you can run PHP from the command line and add a cron job
- An account on the hub, to ask for the mirror to be added
- A domain or subdomain for the mirror
- Disk space for the archives

## Set up on shared hosting

1. Point a domain or subdomain at an empty folder on the server.

   Give the mirror its own domain or subdomain. The `.htaccess` file in the root folder only lets visitors reach `upload.php`, `limits.php`, `identity.php` and `setup.php` at the top of the domain. To install in a subfolder instead, edit the `m#...#` pattern near the end of the first section of the root `.htaccess`, as its comment explains.

2. Put the deployable files in that folder.

   Upload these:

   - `.htaccess`
   - `listing.css`
   - `listing-header.sample.html`
   - `upload.php`
   - `limits.php`
   - `identity.php`
   - `setup.php`
   - `sync.php`
   - `src/`
   - `bin/`
   - `private/`
   - `maps/`
   - `games/`
   - `engines/`
   - `.partial/`

   Keep these off the server: `vendor/`, `tests/`, `fixtures/`, `composer.json`, `composer.lock`, `phpunit.xml.dist` and `.git`. `PROTOCOL.md` and this README are optional.

   You can also `git clone` straight into the web folder. The root `.htaccess` hides `tests/`, `fixtures/`, `examples/` and `vendor/`, which only exist in a clone, and the nginx example refuses them too. Step 8's `--url` check confirms they stay hidden. Your `private/config.php`, `listing-header.html` and archives are ignored by git, so `git pull` leaves them alone.

3. Open `https://<your mirror>/setup.php` in a browser, straight after uploading the files.

   Fill in an ID, a name players see and the mirror's address. The address starts filled in from the page you opened. The page writes `private/config.php`, makes the mirror's signing key and trusts the key of the hub at `hub.coilbox.org`. The secret key stays on the server and is never shown.

   Setup works once. Anyone who opens the page first sets the mirror up, so open it straight away. If the page says the mirror is already set up and you did not do it, delete `private/config.php` and open the page again.

   If the page says it cannot write to `private`, let the web server write to that folder and reload, or [set the mirror up by hand](#set-up-by-hand).

4. Ask the hub to add the mirror.

   The setup page shows a link to the hub. Open it while signed in to the hub, check the details and send the request. The hub asks your mirror to prove it holds its key, then a moderator reviews the request. `php bin/register.php` prints the same link again.

5. Check the setup.

   ```
   php bin/check.php
   php bin/check.php --url=https://mirror.example.org
   ```

   The first command checks PHP, the extensions, the settings and the folders. The second command also requests your mirror over HTTP to check what the web server exposes, and checks that the key the hub publishes is one the mirror trusts. It tries to download `private/canary.txt`, a file that must never be downloadable, so a successful download shows the private folder is exposed.

   Each problem prints on its own line starting with `problem:`, and the command exits with status 1. When everything passes, it prints `ok` and the upload chunk size. Fix every problem before you go on.

   The check also prints `no catalogue held` until the first sync succeeds. That is normal on a new mirror and is not a problem. Use `--root=<folder>` to check a folder other than the one the script sits in. Use `--probe-file=maps/<name>` together with `--url` to test downloading a file you already hold.

## Set up on nginx

nginx ignores the `.htaccess` files, so its site config has to do the same job. `examples/nginx.conf` is a complete site that does.

1. Follow steps 1 and 2 of the shared hosting setup above. The `.htaccess` files can stay. nginx does not read them.
2. Copy `examples/nginx.conf` to `/etc/nginx/sites-available/` and link it into `sites-enabled`.
3. Change `server_name`, `root`, the certificate paths and the `fastcgi_pass` socket to match your server. The comment at the top of the file lists them.
4. Set `client_max_body_size` to at least PHP-FPM's `post_max_size`. If it is lower, nginx refuses upload chunks that the mirror told uploaders it would accept.
5. Run `sudo nginx -t`, then `sudo systemctl reload nginx`.
6. Do steps 3 to 5 of the shared hosting setup. `--url` tests the nginx rules the same way it tests the `.htaccess` ones.

The listing header uses nginx's `sub` and `ssi` modules. The packages from nginx.org and the `nginx` package on Debian and Ubuntu include both. If `nginx -t` reports an unknown `sub_filter` directive, delete the four `sub_filter` and `ssi` lines and the listings stay plain.

`setup.php` runs as the PHP-FPM user, so it writes `private/config.php` as that user. PHP-FPM must run as the same user as cron, or both must be able to write to `private/state/`, as described in the next section.

## Add the cron job

Before you add the job, run one sync by hand, as the same user cron will use.

```
php sync.php --verbose
```

It prints each step. The first run fetches the catalogue, so `no catalogue held` should go away. Until a moderator approves the mirror, the run ends with `sync report failed: not approved by the hub yet`. That is expected. Add the cron job anyway. Reports start working once the mirror is approved. Fix anything it reports as failed before you rely on cron, because cron only emails you about problems.

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

The `maps/`, `games/` and `engines/` pages list every archive. They carry the Coilbox Hub heading, a link back to the hub and a line thanking you for hosting the mirror.

Copy `listing-header.sample.html` to `listing-header.html`, then put your name and link in the line that starts `Thanks to`.

```
cp listing-header.sample.html listing-header.html
```

Without `listing-header.html` the pages still list the files and keep the hub's colours, but have no heading. Updating the mirror leaves your copy alone, because only the sample ships with it.

On Apache the listing style needs the host to let `.htaccess` files set `Indexes` options. DreamHost does. On a host that does not, `maps/` answers 500 and `php bin/check.php --url=...` says so. To fall back to a plain listing, delete the lines under the `Listing style` comment in `maps/.htaccess`, `games/.htaccess` and `engines/.htaccess`.

## What the mirror serves

- `maps/` and `games/` hold the archives
- `engines/` holds engine test builds that the hub's trusted uploaders publish
- `listing.json` lists every file in `maps/`, `games/` and `engines/` with its size and SHA-256 hash
- `catalogue.json` and `catalogue.sig` are the hub's signed catalogue and its signature
- `limits.php` tells uploaders how large each upload chunk may be
- `upload.php` accepts uploads from people the hub has approved
- `identity.php` gives the mirror's ID, address and public key, and signs a challenge from the hub to prove the mirror holds its key
- `setup.php` sets the mirror up the first time it is opened, then only shows the link to ask the hub to add it

The mirror creates `listing.json`, `catalogue.json` and `catalogue.sig` itself. The web server must be able to write to `maps/`, `games/`, `engines/`, `.partial/` and `private/`.

## Keep it running

A quiet `sync.php` run prints nothing when all is well, so cron sends no email. Any output is a problem, written to standard error. Examples are a failed download, a hash mismatch, a catalogue that fails verification and `sync report failed`. A failed report means the sync finished but the hub did not accept the mirror's report. `not approved by the hub yet` means the hub does not list the mirror yet. Otherwise check that `hub_url` is right and that the server can make outgoing HTTPS requests. The next sync sends the report again.

To see everything a run does, run it by hand with `--verbose`.

```
php sync.php --verbose
```

To see how fresh the mirror's copy of the catalogue is, run `php bin/check.php`. It prints the catalogue sequence number and the time the hub generated it.

The mirror keeps the sequence number of the catalogue it holds in `private/state/catalogue_seq`, so nobody can swap in an older catalogue through the web folder. If a run reports that `catalogue_seq` is damaged, or missing while `catalogue.json` cannot be read, the mirror refuses every new catalogue until you fix it. Delete `private/state/catalogue_seq`, `catalogue.json` and `catalogue.sig`, then run `php sync.php --verbose` to fetch a fresh catalogue.

The mirror adds a new hub key by itself when the hub publishes one in a signed catalogue. If a mirror missed that and stops accepting catalogues, add the key by hand. Open `private/config.php`, add the new public key as another line in `hub_keys`, keep the old keys, and run `php sync.php --verbose` to confirm. The hub operator tells you when this is needed.

## Upgrade to a release with engines

Releases from this one on also hold engine test builds, in `engines/`.

1. Update the files. With `git pull`, `engines/` and its `.htaccess` arrive by themselves. Otherwise upload the `engines/` folder with its `.htaccess`.
2. Let the web server and cron write to `engines/`, as for `maps/`.
3. Run `php bin/check.php --url=https://mirror.example.org`.

Until `https://<your mirror>/engines/` answers 200, the hub marks the mirror unhealthy and sends it no uploads or players.

## Set up by hand

Use this when `setup.php` cannot write to `private/`, to point the mirror at a hub other than `hub.coilbox.org`, or for a mirror set up before `setup.php` existed.

1. Copy `private/config.sample.php` to `private/config.php`, or open your existing one.
2. Set `mirror_id`, `mirror_name` and `base_url`. Mirrors set up before `setup.php` existed need `mirror_name` and `base_url` added, so the hub can check their key at `identity.php`.
3. For a new mirror, run `php bin/keygen.php` and paste the `secret_key` line it prints. Keep it secret.
4. Set `hub_url`. Put the hub's public key in `hub_keys`. For `hub.coilbox.org` it is in `src/HubDefaults.php`. Another hub publishes its key at `<hub_url>/api/v1/mirrors/hub-key`. Check it with that hub's operator before you trust it, because whoever holds the matching secret key controls what your mirror stores.
5. Run `php bin/register.php` and open the link it prints while signed in to the hub. A mirror the hub already lists does not need this.

## Development

Install PHPUnit with Composer, then run the tests.

```
composer install
vendor/bin/phpunit
```

The tests need PHP 8.4 or later. `PROTOCOL.md` describes every format and HTTP interface, and `fixtures/` holds the signed example files the tests use. Run `php bin/make-fixtures.php` to regenerate them.
