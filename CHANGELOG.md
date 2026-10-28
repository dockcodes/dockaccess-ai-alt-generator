# Changelog

## 1.0.0 - unreleased

First version of the PHP core for the dockaccess.org alt generator.

- `AltGenerator::generate()`, `prepare()` and `usage()` for `POST /api/generate` and `GET /api/user`.
- Image verification by content for JPEG, PNG, GIF, WebP, AVIF and HEIC, with a full structural parse,
  embedded PHP and PHAR stub detection, and dimension, pixel and frame limits.
- Sanitization of every upload: GD re-encoding, or metadata stripping where GD cannot decode.
- Reading files only from allowed directories, with symlink and swap detection.
- Account key masking and redaction from exceptions, dumps and serialization.
- cURL transport: HTTPS only, no redirects, bounded timeouts and response size, retries only before sending.
- `PlainText::clean()` for alts that arrive outside the generator.
- `bin/bundle.php`: copies the library into a plugin under a prefixed namespace, with an autoloader,
  an optional direct access guard and a manifest of file hashes. Every bundled PHP file names the package,
  its version and its license (MIT) in a header; `--index` adds the directory `index.php` files PrestaShop
  requires.
- Typed exceptions for local refusals, authentication, daily and per-minute limits, rejected images,
  server and transport errors.
