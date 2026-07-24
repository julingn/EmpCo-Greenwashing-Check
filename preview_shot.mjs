/**
 * preview_shot.mjs — Screenshot der Fundstelle einer Textstelle (Headless Chromium)
 *
 * Aufruf: node preview_shot.mjs <url> <snippetFile> <output.png>
 *
 * Rendert die Seite, schließt Cookie-Banner, sucht den Snippet-Text im DOM,
 * hebt ihn hervor, scrollt ihn in die Mitte und macht einen Viewport-Screenshot.
 *
 * Exit 0 = Erfolg (PNG geschrieben) · Exit 1 = Fehler (stderr)
 */

import puppeteer from 'puppeteer-core';
import { existsSync, readFileSync } from 'fs';

const [, , url, snipFile, outFile] = process.argv;
if (!url || !snipFile || !outFile) {
  process.stderr.write('Usage: node preview_shot.mjs <url> <snippetFile> <output.png>\n');
  process.exit(1);
}

let snippet = '';
try { snippet = readFileSync(snipFile, 'utf8'); } catch (_) { /* leer */ }

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

const ACCEPT_SELECTORS = [
  '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll',
  '#CybotCookiebotDialogBodyButtonAccept',
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
      args: [
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-extensions',
        '--window-size=1200,850',
        '--lang=de-DE,de',
        '--disable-features=TranslateUI',
      ],
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 800 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'de-DE,de;q=0.9' });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

    // Cookie-Banner schließen (spezifisch + generisch)
    for (const sel of ACCEPT_SELECTORS) {
      try { const el = await page.$(sel); if (el) { await el.click(); break; } } catch (_) { /* weiter */ }
    }
    await page.evaluate(() => {
      const pats = [/alle\s+(akzeptieren|zulassen|erlauben)/i, /accept\s+all/i, /zustimmen/i, /einverstanden/i, /allow\s+all/i, /alles\s+akzeptieren/i];
      const els = [...document.querySelectorAll('button, a[role="button"], [role="button"], input[type="submit"]')];
      for (const el of els) {
        const t = (el.innerText || el.value || '').trim();
        if (!t) continue;
        const r = el.getBoundingClientRect();
        if (!r.width || !r.height) continue;
        for (const p of pats) { if (p.test(t)) { el.click(); return; } }
      }
    });
    await new Promise(r => setTimeout(r, 900));

    // Fundstelle finden, hervorheben, mittig scrollen
    await page.evaluate((snip) => {
      const norm = s => (s || '').replace(/[…]/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
      let target = norm(snip);
      if (target.length > 80) target = target.slice(0, 80);
      if (target.length < 6) return false;
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
      let node;
      while ((node = walker.nextNode())) {
        const t = norm(node.nodeValue);
        if (t.length >= 6 && t.includes(target)) {
          const el = node.parentElement;
          if (!el) continue;
          try { el.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (_) { }
          el.style.outline = '3px solid #E90C3C';
          el.style.outlineOffset = '2px';
          el.style.backgroundColor = 'rgba(233,12,60,0.14)';
          return true;
        }
      }
      return false;
    }, snippet);

    await new Promise(r => setTimeout(r, 300));
    await page.screenshot({ path: outFile, type: 'png' }); // sichtbarer Bereich
    await browser.close();
    process.exit(0);
  } catch (err) {
    if (browser) await browser.close().catch(() => { });
    process.stderr.write(((err && err.message) || String(err)) + '\n');
    process.exit(1);
  }
})();
