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

    // Fundstelle finden (über Textknoten hinweg), hervorheben, mittig scrollen
    await page.evaluate((snip) => {
      const clean = s => (s || '').replace(/[\u2026]/g, ' ').replace(/\s+/g, ' ').trim();
      const full = clean(snip);
      if (full.length < 6) { return false; }
      const words = full.split(' ').filter(Boolean);

      // Kandidaten-Phrasen: spezifisch (lang) → allgemeiner (kurz)
      const cand = [full.slice(0, 140)];
      for (const len of [12, 9, 7, 5, 4]) {
        if (words.length >= len) {
          const mid = Math.max(0, Math.floor((words.length - len) / 2));
          cand.push(words.slice(mid, mid + len).join(' '));
          cand.push(words.slice(0, len).join(' '));
        }
      }
      const seen = new Set();
      const list = cand.filter(c => c && c.length >= 6 && !seen.has(c) && (seen.add(c), true));

      const drawAndCenter = (range) => {
        const rects = range.getClientRects();
        if (!rects || !rects.length) { return false; }
        const sx = window.scrollX, sy = window.scrollY;
        let top = Infinity, height = 0, any = false;
        for (const r of rects) {
          if (r.width < 1 && r.height < 1) { continue; }
          any = true;
          const box = document.createElement('div');
          box.style.cssText = 'position:absolute;z-index:2147483647;pointer-events:none;border:3px solid #E90C3C;background:rgba(233,12,60,0.18);border-radius:3px';
          box.style.left = (r.left + sx - 2) + 'px';
          box.style.top = (r.top + sy - 2) + 'px';
          box.style.width = (r.width + 4) + 'px';
          box.style.height = (r.height + 4) + 'px';
          document.body.appendChild(box);
          top = Math.min(top, r.top + sy);
          height = Math.max(height, r.height);
        }
        if (!any) { return false; }
        window.scrollTo(0, Math.max(0, top - (window.innerHeight / 2) + height / 2));
        return true;
      };

      const sel = window.getSelection();
      for (const c of list) {
        sel.removeAllRanges();
        let found = false;
        try { found = window.find(c, false, false, true, false, false, false); } catch (_) { }
        if (found && sel.rangeCount > 0) {
          const range = sel.getRangeAt(0).cloneRange();
          sel.removeAllRanges();
          if (drawAndCenter(range)) { return true; }
        }
      }

      // Fallback: einzelner Textknoten enthält eine kurze Phrase
      const targetShort = (words.slice(0, 6).join(' ') || full).toLowerCase();
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
      let node;
      while ((node = walker.nextNode())) {
        const t = (node.nodeValue || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (t.length >= 6 && targetShort.length >= 6 && t.includes(targetShort)) {
          const el = node.parentElement;
          if (!el) { continue; }
          try { el.scrollIntoView({ block: 'center' }); } catch (_) { }
          el.style.outline = '3px solid #E90C3C';
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
