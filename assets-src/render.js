const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox', '--allow-file-access-from-files'] });
  const page = await browser.newPage();
  const jobs = [
    ['banner.html', 772, 250, 1, 'banner-772x250.png'],
    ['banner.html', 772, 250, 2, 'banner-1544x500.png'],
    ['icon.html', 256, 256, 1, 'icon-256x256.png'],
    ['icon.html', 256, 256, 0.5, 'icon-128x128.png'],
  ];
  for (const [file, w, h, scale, out] of jobs) {
    await page.setViewport({ width: w, height: h, deviceScaleFactor: scale });
    await page.goto('file:///src/' + file, { waitUntil: 'networkidle0' });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: '/out/' + out, omitBackground: true, clip: { x: 0, y: 0, width: w, height: h } });
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
