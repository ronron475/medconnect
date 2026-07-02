# Deployment Guide — Hospital Referral Module

## Folder structure to upload
```
hospital-referral/
├── .htaccess
├── config.php
├── index.php
├── referral_modal.php
├── api/
│   └── nearest_hospital.php
├── css/
│   └── referral.css
└── js/
    └── referral.js
```

---

## Option A — Shared Hosting via FTP (cPanel / Hostinger / GoDaddy)

1. Open FileZilla (or your FTP client)
2. Connect using your host's FTP credentials
3. Navigate to `public_html/` (or `www/` depending on your host)
4. Upload the entire `hospital-referral/` folder
5. Done — visit `https://yourdomain.com/hospital-referral/`

> If you want it at the root instead of a subdirectory, upload the **contents**
> of the folder directly into `public_html/` instead.

---

## Option B — cPanel File Manager

1. Log in to cPanel → File Manager
2. Navigate to `public_html/`
3. Click Upload → upload all files maintaining the folder structure
4. Alternatively: zip the `hospital-referral/` folder locally, upload the zip,
   then Extract it inside File Manager

---

## Option C — Git deploy (VPS / DigitalOcean / Railway / etc.)

```bash
git add hospital-referral/
git commit -m "Add hospital referral module"
git push origin main
```

Then on server:
```bash
git pull origin main
```

---

## Requirements on the live server

| Requirement | Why |
|---|---|
| PHP 8.0+ | Uses `never` return type and named args |
| cURL extension | Calls Nominatim and OSRM APIs |
| `allow_url_fopen` ON | Fallback if cURL unavailable |
| HTTPS (SSL certificate) | Browser geolocation requires HTTPS |
| Apache mod_rewrite | For HTTPS redirect in .htaccess |

> Most shared hosts (Hostinger, GoDaddy, Namecheap) have all of these enabled by default.

---

## HTTPS / SSL (most important)

**Chrome and Firefox require HTTPS for geolocation to work on live servers.**

- Most shared hosts give you a free SSL via **Let's Encrypt** in cPanel → SSL/TLS → Let's Encrypt
- Once SSL is active, the `.htaccess` in this module will automatically redirect HTTP → HTTPS
- The `config.php` detects HTTPS and enables secure session cookies automatically

---

## Integration into your existing registration system

In your existing registration PHP page, add these two things:

**1. Before `</body>`:**
```php
<?php include 'hospital-referral/referral_modal.php'; ?>
```

**2. In your OCR result handler:**
```javascript
// Call this when your OCR result returns is_bago_resident = false
if (ocrResult.is_bago_resident === false) {
    window.BagoReferral.show();
}
```

---

## Testing after deployment

1. Visit `https://yourdomain.com/hospital-referral/`
2. Allow location when browser prompts
3. The modal should find the nearest hospital and show the map
4. Click "Go to Hospital" to verify Google Maps opens with directions
5. Test on mobile (Android Chrome + iOS Safari)

---

## Common issues on live servers

| Problem | Fix |
|---|---|
| Geolocation blocked | Ensure HTTPS is active — it's required by all browsers |
| Map tiles not loading | Check if host blocks outbound connections to `tile.openstreetmap.org` |
| Hospital not found | Your host may block `nominatim.openstreetmap.org` — contact host to whitelist it |
| 500 error on API | Check PHP error log in cPanel → Logs → Error Log |
| CSS/JS 404 | Ensure file paths are correct — `config.php` handles this automatically |
