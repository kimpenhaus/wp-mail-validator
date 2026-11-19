# Plugin page assets

Sources of the banner and icon in `.wordpress-org/`, which the deploy workflow uploads to the SVN `assets/` folder with every release. This folder is not shipped.

`social.html` composes `.github/social-preview.png`, the repository's social preview for link previews (1280 × 640), from the icon and two of the screenshots. GitHub takes it only by hand, so a changed one is uploaded under *Settings → General → Social preview*.

Render the PNGs again after changing `icon.svg`, `banner.html`, `social.html` or the screenshots:

```bash
docker run --rm --init --platform linux/amd64 \
  -v "$PWD/assets-src":/src -v "$PWD/.wordpress-org":/out -v "$PWD/.github":/github \
  -v "$PWD/assets-src/render.js":/home/pptruser/render.js:ro \
  ghcr.io/puppeteer/puppeteer:latest node /home/pptruser/render.js
cp assets-src/icon.svg .wordpress-org/icon.svg
```

The screenshots are taken from a WordPress test site (settings page in wp-admin at 1280 px width; Contact Form 7 and the registration form with `jane@mailinator.com`). Their captions are in `readme.txt` under `== Screenshots ==`.
