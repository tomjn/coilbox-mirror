# Coilbox mirror protocol

This file is the single reference for every format the mirror reads or writes. It is written for a developer building the hub side who has not seen the mirror code. Where this file and the code disagree, the code is right and this file has a bug.

The sections are:

1. Keys and encoding
2. Upload token
3. Upload HTTP API
4. limits.php
5. listing.json
6. Catalogue and signature
7. Mirror report
8. Fixtures

All times are integer Unix seconds. All JSON is UTF-8.

## 1. Keys and encoding

Every key and signature is Ed25519, from libsodium (`sodium_crypto_sign_detached`).

- A public key is 32 raw bytes.
- A secret key is 64 raw bytes, as libsodium stores it (seed followed by public key).
- A signature is 64 raw bytes.

Every binary value in a JSON document, a header or a file is base64url, as in RFC 4648 section 5, with no padding. The alphabet is `A-Z a-z 0-9 - _`. The mirror rejects padding (`=`), the standard alphabet (`+` and `/`), and any whitespace, including a trailing newline. A signature file must contain the signature text and nothing else.

A signature never covers the raw message alone. It covers a context string followed by the message bytes. The context for each kind of signature is below. Each context ends with one line feed (`\n`) byte.

| Signature | Context | Message | Signer |
| --- | --- | --- | --- |
| Upload token | `coilbox-mirror/token/v1\n` | the decoded token payload bytes | hub key |
| Catalogue | `coilbox-mirror/catalogue/v1\n` | the catalogue file bytes | hub key, or the key named in `next_key` |
| Hub key endorsement | `coilbox-mirror/hub-key/v1\n` | the 32 raw bytes of the new public key | a hub key the mirror already trusts |
| Mirror report | `coilbox-mirror/report/v1\n` | the decoded report payload bytes | mirror key |

The mirror verifies the exact bytes it receives. It does not parse and re-serialise before checking a signature. The hub must therefore sign the exact bytes it sends, and must serve those bytes unchanged.

### Which hub keys a mirror trusts

A mirror trusts the keys in its `hub_keys` config list, plus every key it has stored from an accepted `next_key` (see section 6). It accepts a token or catalogue signed by any of them.

There is no key retirement. Once a mirror has stored a key, it trusts that key for good. If a hub key leaks, the hub operator must contact each mirror operator and have them change the mirror config by hand. Rotation adds trust. It never removes it.

### Mirror identity

A mirror has an id and a key pair. The id is 1 to 64 characters, lowercase letters, digits and hyphens, and does not start with a hyphen. The hub lists the id and public key of every mirror in the catalogue. The mirror's `hub_url` is an `https://` URL, or `http://` only for `127.0.0.1` and `localhost`. A trailing slash is removed.

### JSON output

The mirror writes JSON with `json_encode` and the flags `JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`. Output has no extra whitespace and no trailing newline. The mirror accepts any valid JSON when reading. The mirror rejects a document whose top level is not an object.

Fixture: `fixtures/keys.json`. These keys are for tests only. The seeds are 32 bytes of `0x01` (hub), `0x02` (rotated hub), `0x03` (mirror) and `0x04` (a second mirror, of which the fixtures use only the public key).

## 2. Upload token

The hub issues one token for each upload. The token tells one mirror to accept one file.

### Format

```
base64url(payload) "." base64url(signature)
```

The token has exactly two parts split on a single `.`. The payload is a JSON object. The signature covers the decoded payload bytes with the token context. Key order and whitespace in the payload do not matter, because the mirror verifies the bytes as sent.

The payload has exactly these eight keys. A missing key or an extra key makes the token invalid.

| Key | Type | Rule |
| --- | --- | --- |
| `v` | integer | Must be `1`. |
| `mirror` | string | The id of the mirror the token is for. |
| `upload` | string | 1 to 64 characters from `A-Z a-z 0-9 -`. Unique for each upload. |
| `sha256` | string | 64 lowercase hex characters. The SHA-256 of the whole file. |
| `size` | integer | The file size in bytes. At least 1. |
| `filename` | string | A valid archive filename, see below. |
| `kind` | string | `map` or `game`. |
| `start_by` | integer | The last second at which the first chunk may arrive. |

### Archive filenames

A valid filename is valid UTF-8, 1 to 255 bytes long, and ends in `.sd7` or `.sdz` in any letter case. It does not start with a dot. It contains no `/`, no `\`, no NUL and no control character (Unicode category Cc, which includes DEL and the C1 controls). Spaces, parentheses, extra dots and non-ASCII letters are fine. The mirror stores the name unchanged.

A kind decides the folder. `map` files live in `maps/` and `game` files live in `games/`.

### Check order

The mirror checks a token in this order. The first failure ends the check.

1. The token splits into two parts and both decode as base64url. Otherwise `bad_token`.
2. The signature verifies with one of the trusted hub keys. Otherwise `bad_token`.
3. The payload is a JSON object with exactly the eight keys, and every value passes its rule. Otherwise `bad_token`.
4. The `mirror` value equals this mirror's id. Otherwise `wrong_mirror`.

The id check comes after the signature check on purpose. Someone without a valid signature learns nothing about the mirror's id. The `start_by` check happens later, at the first chunk (see section 3).

A token is valid for one upload id. When the file is finished, the mirror records the id as used, and a later `POST` with that token gets `used`. A later `GET` answers `200` with `complete` set to `true`.

Fixtures: `fixtures/token.txt` and `fixtures/token.payload.json`, which holds the decoded payload as the hub serialised it.

## 3. Upload HTTP API

The mirror serves one upload endpoint, `upload.php`, at the domain root. For example `https://jj.darkstars.co.uk/upload.php`.

An uploader sends the file in chunks. It asks the mirror how many bytes have arrived, sends the next chunk from that offset, and repeats until the mirror answers `201`. The uploader learns the largest chunk it can send from `limits.php` (section 4).

Every response has the headers `Content-Type: application/json` and `Cache-Control: no-store`. Every body is a JSON object. Only `GET` and `POST` are allowed.

### Headers

| Header | Used on | Value |
| --- | --- | --- |
| `X-Mirror-Token` | `GET` and `POST` | The token from section 2. |
| `X-Mirror-Offset` | `POST` | The number of bytes the uploader believes the mirror already holds. Decimal digits with no leading zeros and no sign, up to 19 digits. |

The token does not travel in `Authorization`, because Apache with FastCGI often removes that header.

### GET upload.php

This asks how many bytes have arrived. It needs only `X-Mirror-Token`. It does not check `start_by` or the removal list.

```
200 {"received": 1048576, "complete": false}
```

`received` is the size of the partial file, or `0` if there is none. After a finished upload the mirror answers `{"received": <size from the token>, "complete": true}`.

### POST upload.php

The request is `multipart/form-data` with exactly one part, named `chunk`. The part's file data is the next slice of the file. A chunk must hold at least 1 byte.

To stay inside `limits.php`'s figure, the request must match the envelope that figure assumes. Use `filename="chunk"` in the part's `Content-Disposition`, `Content-Type: application/octet-stream` as the part's only other header, CRLF line endings, and a boundary of at most 70 characters. Extra parts or longer part headers add bytes, and the request can then exceed `post_max_size`. PHP drops the whole body in that case, so the mirror sees no `chunk` part and answers `bad_request`.

The mirror handles a `POST` in this order.

1. Read `X-Mirror-Token` and verify it (section 2). A missing or empty header is `bad_request`.
2. If the upload id is already used, answer `used`.
3. Read the `chunk` part. A missing part, a failed PHP upload or an unreadable file is `bad_request`. An empty chunk is `bad_request`.
4. Take a lock on the partial file, so two requests for one upload cannot interleave. Check again whether the upload became used while waiting.
5. If the partial file already holds `size` bytes, an earlier finish was cut short, for example because PHP was stopped while hashing. Ignore the chunk and the offset, and finish the upload (below).
6. If the partial file holds no bytes, this is a first chunk. Answer `expired` if the current time is later than `start_by`. Answer `removed` if the catalogue the mirror holds lists this file's hash, or its filename in any letter case, as removed.
7. Read `X-Mirror-Offset`. A missing or malformed value is `bad_request`. If it does not equal the number of bytes the mirror holds, answer `bad_offset`.
8. If the bytes held plus the chunk would pass `size`, answer `too_large`.
9. Append the chunk. If the write comes up short, cut the partial back to its old length and answer `write_failed`.
10. If the bytes held now equal `size`, finish the upload (below). Otherwise answer `200`.

Only a first chunk is checked against `start_by` and the removal list. Once bytes are held, later chunks carry on even after `start_by` has passed.

An uploader that lost a response can resend. If it resends a chunk the mirror already appended, the offset no longer matches, and the mirror answers `bad_offset` with the real count. The uploader then resumes from that count. The mirror never appends the same bytes twice.

### Responses

A chunk that is accepted but is not the last:

```
200 {"received": 1048576, "complete": false}
```

The last chunk, after the file is in place:

```
201 {"complete": true, "received": 43, "path": "maps/New Map (v1.0) é.sd7", "reported": true}
```

`path` is the file's place under the mirror root. `reported` is `true` when the mirror listed the file and the hub answered its report with a 2xx status. If it is `false`, the file is still in place. The next sync lists it and reports it. The hub should not treat `reported: false` as a failed upload.

### Finishing an upload

When the last chunk arrives, the mirror takes the disk lock and does this.

1. Hash the partial file. If the hash is not the token's `sha256`, delete the partial and answer `hash_mismatch`. The uploader must start again from offset 0.
2. List the target folder. If any entry has the same name in any letter case, delete the partial and answer `exists`. This holds even when the bytes are the same. The hub treats names as equal ignoring case, so the mirror does too.
3. Move the file into `maps/` or `games/` under its original name.
4. Record the upload id as used.
5. Add the file to `listing.json` (section 5) and send an `upload` report to the hub (section 7). A failure in this step does not fail the upload.

If the mirror cannot take the disk lock, read the target folder or move the file, it deletes the partial and answers `write_failed`. Any other failure before the file is in place also deletes the partial, and the answer is `internal_error`. After either, the uploader starts again from offset 0.

A `GET` that answers `received` equal to `size` with `complete` set to `false` means a finish was cut short. The uploader sends any non-empty chunk, and the mirror finishes the upload from the bytes it holds (step 5 above).

### Errors

An error response has the shape `{"error": "<code>"}`. `bad_offset` also carries `"received"`, the number of bytes the mirror really holds, so the uploader can resume.

| Code | Status | Meaning |
| --- | --- | --- |
| `bad_request` | 400 | A header, the offset or the `chunk` part is missing, malformed or unreadable. |
| `bad_token` | 403 | The token does not parse, its signature does not verify or its payload breaks a rule. |
| `wrong_mirror` | 403 | The token is valid but for another mirror. |
| `expired` | 410 | A first chunk arrived after `start_by`. |
| `removed` | 410 | The hash or filename is on the catalogue's removal list. |
| `used` | 409 | This upload id has already finished. |
| `bad_offset` | 409 | `X-Mirror-Offset` is not the number of bytes held. The body has `received`. |
| `exists` | 409 | A file with this name, in any letter case, is already in the folder. |
| `too_large` | 413 | The chunk would take the file past `size`. |
| `hash_mismatch` | 422 | The finished file's SHA-256 is not the token's. The partial is deleted. |
| `write_failed` | 500 | The mirror could not seek or write the chunk, or finishing failed because it could not take the disk lock, read the target folder or move the file. A failure to lock the partial file is `internal_error` instead. |
| `method_not_allowed` | 405 | The method is not `GET` or `POST`. |
| `not_configured` | 503 | `private/config.php` is missing or invalid. |
| `internal_error` | 500 | Any other failure. The reason goes to the server log and never to the response. |

`bad_offset` on a `POST` with an unfinished upload is the normal way to resume. The other 4xx codes are final for that token, except `bad_request`, which the uploader may retry after fixing the request.

Unfinished partial files are deleted by `sync.php` once they are older than the `partial_lifetime` config value.

## 4. limits.php

`GET limits.php` tells the hub and uploaders the largest chunk one request can carry. It needs no token and holds no secrets.

```
200 {"v": 1, "max_chunk_bytes": 536870654}
```

The mirror works the figure out from PHP's own settings.

- If `file_uploads` is off, or `upload_max_filesize` is 0 or malformed, uploads are disabled.
- If `post_max_size` is 0, or is malformed so that PHP reads it as 0, which means no limit, `max_chunk_bytes` is `upload_max_filesize`.
- Otherwise it is the smaller of `upload_max_filesize` and `post_max_size` minus the envelope.
- If that result is below 1, uploads are disabled.

The envelope is 258 bytes. It is the size of a request body that carries the `chunk` part with no data and the longest allowed boundary of 70 characters. The figure is exact for a request built as section 3 describes.

Other responses:

| Status | Body | When |
| --- | --- | --- |
| 405 | `{"error": "method_not_allowed"}` | Any method but `GET`. The response has `Allow: GET`. |
| 503 | `{"error": "uploads_disabled"}` | The rules above leave no usable chunk size. |
| 500 | `{"error": "internal_error"}` | Any other failure. |

The values depend on the web server's PHP settings, which can differ from the command line. The hub should read `limits.php` over HTTP and not assume a number. The mirror has no fixed chunk size.

Fixture: `fixtures/limits.json`, made from `upload_max_filesize` and `post_max_size` both set to 512M.

## 5. listing.json

Each mirror keeps `listing.json` at its root, next to `upload.php`. It lists every archive on disk. The hub and peer mirrors read it over HTTP at `{base_url}/listing.json`.

### Format

```json
{"v": 1, "generated_at": 1794787260, "files": [
  {"filename": "Test Game v1.sdz", "kind": "game", "size": 39, "sha256": "e0923c55...", "mtime": 1794780000}
]}
```

| Key | Type | Rule |
| --- | --- | --- |
| `v` | integer | `1`. |
| `generated_at` | integer | When the mirror wrote the document. |
| `files` | list | One entry for each archive. May be empty. |

Each entry in `files` has these keys.

| Key | Type | Rule |
| --- | --- | --- |
| `filename` | string | A valid archive filename (section 2). |
| `kind` | string | `map` or `game`. |
| `size` | integer | At least 1. |
| `sha256` | string | 64 lowercase hex characters. |
| `mtime` | integer | The file's modification time. |

### How the mirror builds it

- It scans `maps/` and `games/`, one level deep. Subfolders are not read.
- It lists a regular file only if its name is a valid archive filename.
- It leaves out zero byte files. No real archive is empty, and one entry with `size` 0 would make the whole document invalid. A zero byte file never appears in a listing, so it never shows as unknown in a report.
- It sorts entries by `kind` and then by `filename` in byte order.
- It reuses a stored `sha256` when a file's size and `mtime` are unchanged, and hashes the file otherwise.

The mirror rewrites the listing at the start and end of every sync, and adds a file as soon as an upload or a peer download finishes. A listing on disk that fails the rules below counts as missing, and the mirror rebuilds it.

### What makes a listing valid

The mirror calls a listing valid, and so usable, only when all of these hold. Peer listings go through the same check.

- The top level is a JSON object.
- `v` is the integer `1`.
- `generated_at` is an integer.
- `files` is a list, meaning a JSON array. It may be empty.
- Every entry is an object whose `filename`, `kind`, `size`, `sha256` and `mtime` follow the table above.

Unknown keys, at the top level or in an entry, are allowed. One bad entry makes the whole document invalid, and the mirror then ignores the whole document.

### Peer listing size cap

During a sync, a mirror downloads each peer's `listing.json` to find who holds a missing archive. It reads at most a set number of bytes and skips a peer whose document is longer.

The cap is the largest document that can hold `2 x A + 1` entries, where `A` is the number of archives in the held catalogue. It comes from `Listing::maxDocBytes`. That function counts a 255 byte filename in which every byte takes 6 bytes of JSON escaping, the longest kind, 19 digit integers and a 64 character hash. Each entry costs at most 1691 bytes and the document wrapper costs 53 bytes. For a catalogue of 2 archives the cap is 8508 bytes.

The policy behind the factor of 2 is that a peer's listing may hold as many unknown files as there are catalogue archives. A peer that has uploaded more fresh files than that, which the catalogue does not list yet, is skipped, with a log line, until the catalogue catches up. A peer over the cap, or with a missing or invalid listing, never stops the sync. The mirror moves on to the next peer.

Fixture: `fixtures/listing.json`.

## 6. Catalogue and signature

The catalogue is the hub's signed list of mirrors, archives and removals. The hub serves two files.

- `GET {hub_url}/api/v1/catalogue` returns the catalogue JSON.
- `GET {hub_url}/api/v1/catalogue.sig` returns the base64url signature, with no trailing newline.

Both must answer `200`. Any other status, such as an HTML error page, is a failed fetch and the mirror keeps the catalogue it holds.

The mirror stores the two files exactly as received, as `catalogue.json` and `catalogue.sig` at its root. Peers fetch them from there at `{base_url}/catalogue.json` and `{base_url}/catalogue.sig`. If the hub fetch fails, or the hub's catalogue is rejected for any reason except `not_newer`, the mirror tries each other mirror in the catalogue it holds. A `not_newer` answer from the hub ends the refresh, because the mirror is already current. A peer's copy must still verify against hub keys the mirror trusts, so a peer cannot forge a catalogue.

### Format

```json
{
  "v": 1,
  "seq": 1,
  "generated_at": 1794787200,
  "mirrors":  [{"id": "...", "base_url": "...", "public_key": "..."}],
  "archives": [{"filename": "...", "kind": "map", "sha256": "...", "size": 38,
                "internal_name": "...", "version": "...",
                "shortname": null, "author": null, "description": null}],
  "removed":  [{"sha256": "...", "filename": "...", "reason": "...", "removed_at": 1794700800}],
  "next_key": {"public_key": "...", "sig": "..."}
}
```

`next_key` is optional. The other keys are required.

### Shape rules

The mirror enforces exactly these rules. A catalogue that breaks one is rejected as `malformed`.

Top level:

- `v` is the integer `1`.
- `seq` is an integer of at least 1. It rises with every new catalogue.
- `generated_at` is an integer.
- `mirrors`, `archives` and `removed` are each a list (a JSON array) of objects. A list may be empty. Write an empty list as `[]`.

Each `mirrors` entry:

- `id` matches the mirror id rule in section 1.
- `base_url` starts with `http://` or `https://`, has a host, may have a path, has no query, no fragment, no whitespace and no trailing slash. Mirrors are expected at the domain root, and the shipped `.htaccess` assumes it. A mirror in a subfolder works only if its operator edits the path in that file. The mirror reaches its peers at `{base_url}/catalogue.json`, `{base_url}/listing.json`, and `{base_url}/maps/<name>` or `{base_url}/games/<name>` for archives. `<name>` is the filename percent-encoded with PHP's `rawurlencode`, so `Test Map (v1.2) é.sd7` becomes `Test%20Map%20%28v1.2%29%20%C3%A9.sd7`.
- `public_key` is base64url and decodes to 32 bytes.

Each `archives` entry:

- `filename` is a valid archive filename.
- `kind` is `map` or `game`.
- `sha256` is 64 lowercase hex characters.
- `size` is an integer of at least 1.
- `internal_name` and `version` are strings.
- `shortname`, `author` and `description` must be present, and each is a string or `null`.

Each `removed` entry:

- `sha256` is 64 lowercase hex characters.
- `filename` is a non-empty string. It need not be a valid archive filename.
- `reason` is a string.
- `removed_at` is an integer.

Unknown keys are allowed, at the top level and inside entries. The hub can add fields without breaking mirrors. Because the whole document is signed, a mirror cannot act on an unsigned extra field.

A removal matches a file by its hash or by its filename in any letter case. The mirror deletes matching files during sync and refuses matching uploads.

### Verification order

The mirror verifies a catalogue in this order. The first failure rejects it. A rejected catalogue is never stored.

1. Parse the JSON. If it is not a JSON object, reject as `malformed`.
2. If the document has a `next_key` key, check the endorsement. A key that is present but `null` fails, so leave the key out when there is nothing to endorse. Reject as `bad_key_endorsement` unless all of these hold.
   - `next_key` is an object whose `public_key` and `sig` are strings.
   - Both decode as base64url, to 32 bytes and 64 bytes.
   - `sig` verifies over the 32 raw public key bytes, with the hub key context, under a key the mirror already trusts.
3. Decode the signature file as base64url, and check it over the catalogue bytes with the catalogue context. It must verify under a trusted key, or under the `next_key` key from step 2. Otherwise reject as `bad_signature`.
4. If the mirror holds a catalogue, and `seq` is an integer, `seq` must be greater than the held one. An equal `seq` is also rejected. Otherwise reject as `not_newer`.
5. Check the shape rules above. Otherwise reject as `malformed`.

After an accepted catalogue with a `next_key`, the mirror saves that key in `private/state/hub_keys.json` and trusts it from then on. It saves the key before it stores the catalogue, so a mirror never reports a `catalogue_seq` while still refusing tokens signed by that catalogue's new key.

The mirror records the accepted `seq` in `private/state/catalogue_seq` and checks step 4 against that file, not against the public `catalogue.json`. If the state file is damaged, or is missing while `catalogue.json` cannot be read, the mirror refuses every catalogue until its operator repairs the files. It does not fall back to accepting any seq.

### Key rotation

To move the hub to a new key, the hub operator does this.

1. Make the new key pair.
2. Sign the new public key's 32 raw bytes with the current hub key and the hub key context. This is the endorsement.
3. Publish catalogues with `next_key` set to the new public key and that endorsement. The hub may sign these catalogues with either key. A mirror that holds only the old key accepts both, because step 2 of the verification order adds the endorsed key to the keys it checks.

The hub must keep `next_key` in every catalogue until every mirror reports a catalogue sequence signed by the new key. A mirror that missed the catalogue which introduced the key can still verify a later one, but only if that later catalogue carries the endorsement. If the hub drops `next_key` too early, such a mirror cannot verify a catalogue signed by the new key, and it stays on its old catalogue until its operator edits its config by hand. Each mirror's `catalogue_seq` in its report (section 7) tells the hub which catalogue it holds. Compare it with the sequence numbers the hub signed with the new key.

A mirror stores the new key as soon as it accepts any catalogue that carries a valid `next_key`, whichever key signed that catalogue. Until then it rejects a token signed with the new key as `bad_token`. The hub should keep signing tokens with the old key for a mirror until that mirror reports a `catalogue_seq` at or above the first catalogue that carried the `next_key`.

An old hub key stays trusted by every mirror after rotation, with no retirement. Treat an old key's secret as live for as long as any mirror might hold it.

Fixtures:

- `fixtures/catalogue.json` and `fixtures/catalogue.sig`. Sequence 1, signed by the hub key, with two mirrors, one map, one game and one removal.
- `fixtures/catalogue-rotated.json` and `fixtures/catalogue-rotated.sig`. Sequence 2, signed by the rotated key, with a `next_key` that the hub key endorses. A mirror that trusts only the hub key accepts it.

## 7. Mirror report

A mirror tells the hub what it holds. It sends a report after every finished upload and at the end of every sync.

### Request

```
POST {hub_url}/api/v1/mirrors/report
Content-Type: application/json
```

The body is a JSON object with two keys.

| Key | Value |
| --- | --- |
| `payload` | The payload JSON text, base64url encoded. |
| `sig` | base64url of the signature over the decoded payload bytes, with the report context, by the mirror key. |

The hub verifies `sig` over the decoded payload bytes with the public key it has for the mirror named in `payload`. It must read `mirror` out of the payload first, then pick that mirror's key. It must not trust the payload until the signature verifies.

### Payload

| Key | Type | Meaning |
| --- | --- | --- |
| `v` | integer | `1`. |
| `mirror` | string | The mirror id. |
| `event` | string | `upload` or `sync`. |
| `upload` | string or null | The token's upload id for an `upload` event. `null` for `sync`. |
| `sent_at` | integer | When the mirror sent the report. |
| `catalogue_seq` | integer or null | The `seq` of the catalogue the mirror holds. `null` if it holds none. |
| `catalogue_generated_at` | integer or null | The `generated_at` of that catalogue, or `null`. |
| `held` | list of strings | The SHA-256 of every file in the mirror's listing. Each hash appears once, sorted as plain strings. |
| `unknown` | list of objects | Every listed file whose hash is not in the held catalogue's `archives`. |

Each `unknown` entry has `filename`, `kind`, `size` and `sha256`, taken from the listing. A file counts as known by its hash alone, so the same bytes under another name are not unknown.

An empty `held` or `unknown` list is `[]`. A mirror with no catalogue yet reports every file as unknown.

The mirror reports from its listing, not from a fresh scan. After an upload finishes, the listing already holds the new file. This is why the upload report and the sync report come from one source.

### Response

The mirror treats any 2xx status as success and ignores the body. Any other status, or no answer, is a failure.

- After an upload, a failure sets `reported` to `false` in the upload response. The mirror does not retry. The next sync sends the report again.
- After a sync, a failure makes `sync.php` log the failure and exit with status 1.

The report format has no replay protection beyond `sent_at`. If the hub wants to refuse stale reports, it decides how.

Fixtures: `fixtures/report-sync.json` and `fixtures/report-upload.json`. Both are request bodies. Both are signed by the mirror key and report the first catalogue. The listing holds one file that catalogue does not list, so each report has one `unknown` entry.

## 8. Fixtures

`fixtures/` holds signed example files made by `php bin/make-fixtures.php [outdir]`. The default folder is `fixtures/`. The script is deterministic. It uses fixed key seeds and fixed times, Ed25519 signatures are deterministic, and all JSON goes through the mirror's own encoder. Running it again gives the same bytes, and `tests/Unit/FixturesTest.php` checks that.

| File | Holds |
| --- | --- |
| `keys.json` | The test keys, as base64url seeds, secret keys and public keys, with a `note` that they are for tests only. |
| `token.txt` | A signed upload token for the mirror `jj-darkstars`. |
| `token.payload.json` | The decoded payload of that token. |
| `catalogue.json`, `catalogue.sig` | Sequence 1, signed by the hub key. |
| `catalogue-rotated.json`, `catalogue-rotated.sig` | Sequence 2, signed by the rotated key, with `next_key`. |
| `listing.json` | A mirror listing with three files. |
| `report-sync.json`, `report-upload.json` | Signed report request bodies. |
| `limits.json` | A `limits.php` answer for 512M upload and post limits. |

Times are in Unix seconds. `1794787200` is the generated time of the first catalogue, 2026-11-16 00:00:00 UTC. The token's `start_by` is one hour later.

The hub's tests should keep copies of these files. When a format changes, the mirror's commit changes this file and the fixtures together. The hub's copies must then be updated, and the hub's tests will show any difference in behaviour.

Never use the fixture keys outside tests. The secret keys are published in this repository.
