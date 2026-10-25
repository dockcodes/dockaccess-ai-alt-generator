# Security

This package runs inside other people's websites and sends their files to an external service.
It treats every input as hostile: the image file, its name, the path it is read from, the
configuration an integration passes in, and the API's answers.

## What is protected

### 1. Only genuine images are sent

An attacker who can upload a file to a site, or a site owner who picks the wrong file, must not
be able to make the package send anything other than a picture.

- **Content decides, not the name.** The format is sniffed from magic bytes; the extension and the
  MIME type a browser reported are ignored. Accepted formats: JPEG, PNG, GIF, WebP, AVIF, HEIC.
  SVG is never accepted, as it is a document that can carry scripts.
- **Full structural parse.** Every PNG chunk with its CRC, every JPEG segment up to the end of the
  entropy-coded data and EOI, every GIF block and sub-block, every WebP RIFF chunk, every
  ISOBMFF box. A file whose structure does not hold together, or which ends before its end marker,
  is refused. The number of parts walked is bounded.
- **Embedded code.** `<?php` and `__halt_compiler` (a PHAR stub) are searched for, case-insensitively,
  anywhere in the file, before parsing and again in the output. A valid JPEG that is also a PHAR
  archive is refused.
- **Decompression bombs.** Width, height, pixel count and frame count are read from the headers and
  checked against the policy before anything is decoded. GD is only used when the decoded bitmap
  fits into the memory PHP has left, because running out of memory is a fatal error no caller can catch.
- **Size.** Files are refused above the policy limit (at most the API's 10 MiB) before they are read
  whole; a file read from disk is read with a hard cap even if it grows while being read.

### 2. Nothing besides the pixels leaves

The output is always a new file:

- **Re-encoding (default when GD is available).** GD decodes the image, the EXIF orientation is
  applied to the pixels, the image is scaled down to the policy's longest side and written as JPEG
  (opaque) or WebP/PNG (transparent). Only bytes written by GD are sent. Any warning from GD during
  decoding or encoding rejects the result.
- **Stripping (without GD, or in `MODE_STRIP`).** The file is rebuilt from the parts already
  verified by the parser that carry pixels: for JPEG the tables, frame and scans, a JFIF segment
  rebuilt from its fields without its thumbnail, an Adobe segment rebuilt from its fields, and a
  minimal EXIF block holding only the orientation; for PNG the critical chunks and the colour chunks
  (`tRNS`, `gAMA`, `cHRM`, `sRGB`, `sBIT`, `pHYs`) only with the length the specification gives them;
  for GIF the image blocks and standard graphic control blocks; for WebP the image chunks, with the
  EXIF, XMP and ICC flags cleared. Comments, text chunks, EXIF, XMP, IPTC, ICC profiles,
  application blocks and everything after the end marker are dropped.
- The output is parsed again with the same checks before it is sent.

What stripping cannot do: the compressed pixel data of a JPEG, PNG, GIF or WebP is opaque bytes,
and someone crafting a file can choose them. Stripping guarantees that the file is a well-formed
image without metadata, without a PHP open tag and without a PHAR stub, but not that every byte was
produced by an encoder. PHP's short tag `<?=` is not searched for, because three bytes occur by
chance in compressed data - roughly once per 16 MB - and would refuse genuine photos. Use
`ImagePolicy::MODE_REENCODE` where only encoder-written bytes may leave the server.

### 3. Files outside the allowed directories are not read

`ImageSource::fromFile()` exists so that a path coming from a request (`../../wp-config.php`) cannot
turn the generator into a file-reading primitive.

- At least one allowed directory must be named; each is resolved with `realpath()`.
- The file's real path, with symlinks resolved, must lie inside an allowed directory
  (`/var/www/uploads-old` is not inside `/var/www/uploads`).
- Stream wrappers and URLs (`phar://`, `php://`, `http://`, `data:`) and NUL bytes are refused.
- The opened file must be a regular file whose inode matches the checked path, so a symlink
  swapped in between the check and the read is detected.
- `fromUpload()` accepts only files PHP stored for the current request (`is_uploaded_file()`).

### 4. The account key does not leak

- The key is held in a `Secret`, XOR-masked with a random pad, and only revealed when the request
  is built. It prints as `[redacted]` in string casts, `var_dump()`, `print_r()`, `json_encode()`;
  serializing it throws. On PHP 8.2+ it is marked `#[\SensitiveParameter]`, so it is left out of
  stack traces.
- Every exception message passes through a redactor that removes the key in raw and URL-encoded
  form and strips query strings from URLs. API messages are additionally cleaned to plain text.
- The proxy URL, which may contain credentials, is marked `#[\SensitiveParameter]` and hidden from
  dumps of the configuration. Transport errors use cURL's generic texts (`curl_strerror()`), which
  contain neither the URL nor the proxy.
- The key is never placed in the uploaded file, its name or a header.

The API takes the key as a `key` query parameter. The query travels inside TLS, so it is not
visible on the network, but it can end up in the access logs of the API's own reverse proxy.

### 5. The connection

- HTTPS only, with peer and host name verification, TLS 1.2 or newer, protocols restricted to
  HTTPS. A failed handshake is an error, never a fallback to plain HTTP.
- Redirects are never followed: the key and the image go only to the configured host.
- Connect and total timeouts are always set; a response body above 1 MiB or headers above 64 KiB
  abort the transfer.
- Only failures before anything was sent (DNS, connection refused, connect timeout) are retried,
  so an image is never submitted twice and the daily limit is not used twice.

### 6. Answers from the API

- JSON is decoded with a depth limit; identifiers must be UUIDs; alts must be non-empty strings.
- Alts are cleaned to one line of plain text: invalid UTF-8, markup, control characters,
  bidirectional overrides and isolates ("Trojan Source"), zero-width characters and BOMs are removed,
  and the length is capped.
- This is not HTML escaping. The integration still escapes the alt for the context it is printed in.

## What this package does not do

- It does not protect the site from the uploaded file itself. Whether the CMS stores a PHP file
  disguised as an image, and whether the web server would execute it, is the platform's concern.
- It is not a malware scanner. It refuses what is not a well-formed image; it does not judge what
  an image shows.
- It does not store anything: no temporary files, no cache, no logs.

## Reporting a vulnerability

Please report vulnerabilities privately to Dock sp. z o.o. (https://dock.eu) rather than in a
public issue. Include the package version, the PHP version and, if possible, a file or a script
that reproduces the problem.
