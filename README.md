# survos/imagor-bundle

Signed URLs for the Survos imagor build ([survos-sites/imagor](https://github.com/survos-sites/imagor),
deployed at `imagor.survos.com`): presets, crops, and `meta/` (thumbhash,
blurhash, average color). The imagor counterpart of `survos/imgproxy-bundle`;
both can run side by side while apps migrate.

## Configuration

Everything is injected from `config/packages/survos_imagor.yaml`:

```yaml
survos_imagor:
    host: '%env(IMAGOR_HOST)%'          # default
    secret: '%env(IMAGOR_SECRET)%'      # default; = IMAGOR_SECRET on the server
    buckets: [museado, ink-rappnews]    # read in place as {bucket}/{key}; mirror the server's buckets.yaml
    s3_endpoint: '%env(S3_ENDPOINT)%'   # default; our storage's https URLs → {bucket}/{key}
    # source_bucket: museado            # where the server caches external originals
    # source_prefix: imagor/src
    # signer_truncate: 40
    # presets: { name: imagor-options } # REPLACES the defaults — keep in sync with the server
```

Default presets match the server's `IMAGOR_PRESETS` (and imgproxy's sizes):
`tiny`, `thumb`, `observe`, `display`, `archive`, `card`, `large`. Only `tiny`
and `thumb` (plus crops) are stored server-side; a preset string that drifts
from the server still renders, it just never caches.

## Use

```twig
<img src="{{ asset.url|imagor('thumb') }}">
<img src="{{ 's3://ink-rappnews/…/0001.jp2'|imagor_crop(1000, 800, 2000, 2000, 1000) }}">
```

```php
$imagor->preset($source, 'display');
$imagor->crop($source, x: 1000, y: 800, w: 2000, h: 2000, width: 1000);
$meta = $imagor->meta($source);           // ImagorMeta: width, height, format, exif,
$meta->thumbhash; $meta->averageHex();    //   thumbhash, blurhash, averageColor
$imagor->cachedSourceUrl($httpsUrl);      // s3://museado/imagor/src/aa/bb/sha1(url), or null for our buckets
```

```bash
bin/console imagor:url https://example.org/a.jpg thumb
bin/console imagor:url s3://ink-rappnews/…/0001.jp2 --meta
```

## Sources

| source | sent as | server |
|---|---|---|
| `s3://{bucket}/{key}` in `buckets` | `{bucket}/{key}` | read in place, never copied (JP2 works) |
| `https://{endpoint}/{bucket}/{key}`, `https://{bucket}.{endpoint}/{key}` | rewritten to `{bucket}/{key}` | same |
| any other `http(s)://` | fully percent-encoded | fetched once, original kept at `cachedSourceUrl()` |
| `s3://` in another bucket | not supported (`supports()` false) — keep it on imgproxy | |

## Not in imagor (still imgproxy Pro)

`/info` palettes (`dominant_colors`), perceptual hash, checksums, `detect_objects`,
`classify`, IIIF. Keep `survos/imgproxy-bundle` for those until replaced.
