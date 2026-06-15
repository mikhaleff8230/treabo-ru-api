# Treabo Cloudflare R2 Uploads

Treabo can store user-uploaded files in Cloudflare R2 and serve them through a CDN custom domain.

## Cloudflare Setup

1. Create an R2 bucket, for example `treabo-uploads`.
2. Create an R2 API token with read/write access to this bucket.
3. Copy:
   - Access Key ID
   - Secret Access Key
   - Account ID
4. Connect a public custom domain to the bucket, for example:
   - `cdn.treabo.md`
   - `cdn.sancan.ru`
5. In Cloudflare cache settings, enable caching for public image files. For aggressive caching, add a Cache Rule for the CDN domain.

## Laravel `.env`

Local development can keep using the local public disk:

```env
MEDIA_DISK=public
PROFFI_UPLOAD_DISK=public
PROFFI_UPLOAD_PREFIX=proffi
```

Production should use R2:

```env
MEDIA_DISK=r2
PROFFI_UPLOAD_DISK=r2
PROFFI_UPLOAD_PREFIX=proffi

CLOUDFLARE_R2_ACCESS_KEY_ID=
CLOUDFLARE_R2_SECRET_ACCESS_KEY=
CLOUDFLARE_R2_BUCKET=treabo-uploads
CLOUDFLARE_R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
CLOUDFLARE_R2_PUBLIC_URL=https://cdn.example.com
CLOUDFLARE_R2_REGION=auto
CLOUDFLARE_R2_USE_PATH_STYLE_ENDPOINT=true
```

`MEDIA_DISK` is used by the Marvel/admin attachment uploader. `PROFFI_UPLOAD_DISK` is used by Treabo task/chat upload endpoints.

After changing `.env` on the server:

```bash
php artisan config:clear
php artisan cache:clear
```

## API Response

`POST /api/uploads` returns:

```json
{
  "disk": "r2",
  "path": "proffi/tasks/2026/06/file.jpg",
  "url": "https://cdn.example.com/proffi/tasks/2026/06/file.jpg",
  "mime": "image/jpeg",
  "size": 123456
}
```

The old `path` field remains for compatibility. New clients should prefer `url`.
