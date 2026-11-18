# Plugin page assets

Sources of the banner and icon in `.wordpress-org/`, which the deploy workflow uploads to the SVN `assets/` folder with every release. This folder is not shipped.

Render the PNGs again after changing `icon.svg` or `banner.html`:

```bash
docker run --rm --init --platform linux/amd64 \
  -v "$PWD/assets-src":/src -v "$PWD/.wordpress-org":/out \
  -v "$PWD/assets-src/render.js":/home/pptruser/render.js:ro \
  ghcr.io/puppeteer/puppeteer:latest node /home/pptruser/render.js
cp assets-src/icon.svg .wordpress-org/icon.svg
```

The screenshots are taken from a WordPress test site (settings page in wp-admin at 1280 px width; Contact Form 7 and the registration form with `jane@mailinator.com`). Their captions are in `readme.txt` under `== Screenshots ==`.
