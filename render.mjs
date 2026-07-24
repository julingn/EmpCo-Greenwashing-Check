/**
 * render.mjs — Rendert eine Seite mit Headless Chromium (JS ausgeführt) und
 * liefert JSON: { text, attrs, links, images }.
 *
 * Aufruf: node render.mjs <url>
 * Exit 0 = Erfolg (JSON auf stdout) · Exit 1 = Fehler (stderr)
 */

import puppeteer from 'puppeteer-core';
import { existsSync } from 'fs';

const [, , url] = process.argv;
if (!url) { process.stderr.write('Usage: node render.mjs <url>\n'); process.exit(1); }

const candidates = [
  process.env.PUPPETEER_EXECUTABLE_PATH,
  process.env.CHROMIUM_PATH,
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
];
const execPath = candidates.find(p => p && existsSync(p));
if (!execPath) { process.stderr.write('Chromium nicht gefunden\n'); process.exit(1); }

const ACCEPT = [
  '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll',
  '#onetrust-accept-btn-handler',
  '[data-testid="uc-accept-all-button"]',
  '#didomi-notice-agree-button',
  '.cc-btn.cc-allow',
  '#borlabs-cookie-btn-accept-all',
];

(async () => {
  let browser;
  try {
    browser = await puppeteer.launch({
      executablePath: execPath,
      headless: true,
      args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--lang=de-DE,de'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'de-DE,de;q=0.9' });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

    for (const sel of ACCEPT) {
      try { const el = await page.$(sel); if (el) { await el.click(); break; } } catch (_) { }
    }
    await page.evaluate(() => {
      const pats = [/alle\s+(akzeptieren|zulassen|erlauben)/i, /accept\s+all/i, /zustimmen/i, /einverstanden/i, /allow\s+all/i];
      const els = [...document.querySelectorAll('button, a[role="button"], [role="button"]')];
      for (const el of els) { const t = (el.innerText || '').trim(); if (!t) continue; const r = el.getBoundingClientRect(); if (!r.width || !r.height) continue; for (const p of pats) { if (p.test(t)) { el.click(); return; } } }
    });
    await new Promise(r => setTimeout(r, 1000));

    const data = await page.evaluate(() => {
      const clone = document.body.cloneNode(true);
      clone.querySelectorAll('script, style, noscript, svg, nav').forEach(e => e.remove());
      const text = (clone.innerText || '').replace(/\s+/g, ' ').trim();
      const attrs = [...document.querySelectorAll('[title],[alt],[aria-label]')]
        .map(e => (e.getAttribute('title') || e.getAttribute('alt') || e.getAttribute('aria-label') || '').trim())
        .filter(s => s.length >= 3 && s.length <= 300);
      const links = [...document.querySelectorAll('a[href]')].map(a => a.href).filter(h => /^https?:/i.test(h));
      const images = [...document.querySelectorAll('img')]
        .filter(im => im.naturalWidth >= 80 && im.naturalHeight >= 80)
        .map(im => im.currentSrc || im.src)
        .filter(s => /^https?:/i.test(s));
      return { text, attrs: [...new Set(attrs)], links: [...new Set(links)], images: [...new Set(images)] };
    });

    process.stdout.write(JSON.stringify(data));
    await browser.close();
    process.exit(0);
  } catch (err) {
    if (browser) await browser.close().catch(() => { });
    process.stderr.write(((err && err.message) || String(err)) + '\n');
    process.exit(1);
  }
})();
