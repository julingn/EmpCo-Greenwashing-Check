/**
 * preview_shot.mjs — Screenshot der Fundstelle einer Textstelle (Headless Chromium)
 *
 * Aufruf: node preview_shot.mjs <url> <snippetFile> <output.png> [triggerTerm]
 *
 * Rendert die Seite, schließt Cookie-Banner, sucht den Snippet-Text im DOM
 * (knoten-übergreifend, mehrstufig), hebt ihn hervor, scrollt ihn in die Mitte
 * und macht einen Viewport-Screenshot. Ist ein Trigger-Begriff angegeben, wird
 * die exakte Fundstelle darin fokussiert markiert und zentriert.
 *
 * Exit 0 = Erfolg (PNG geschrieben) · Exit 1 = Fehler (stderr)
 */

import puppeteer from 'puppeteer-core';
import { existsSync, readFileSync } from 'fs';

const [, , url, snipFile, outFile, triggerTerm = ''] = process.argv;
if (!url || !snipFile || !outFile) {
  process.stderr.write('Usage: node preview_shot.mjs <url> <snippetFile> <output.png> [triggerTerm]\n');
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

    // Fundstelle finden (knoten-übergreifend), hervorheben, mittig scrollen
    await page.evaluate((snip, term) => {
      // Normalisierung analog zur PHP-Extraktion (strip_tags + Whitespace-Kollaps):
      // Kleinschreibung, „…" → Leerzeichen, Whitespace zu einfachem Space.
      const normStr = s => (s || '')
        .replace(/[\u2026]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

      const full = normStr(snip);
      if (full.length < 4) { return false; }
      const normTerm = normStr(term);

      // 1) Sichtbaren Text der ganzen Seite zu EINEM normalisierten String
      //    zusammenziehen und dabei jede Zeichenposition auf {node, offset}
      //    zurück-mappen. So werden Treffer über mehrere Textknoten hinweg
      //    (Inline-Tags, Zeilenumbrüche) gefunden.
      const SKIP = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'NAV', 'SVG', 'HEAD', 'TEMPLATE']);
      let text = '';
      const posMap = []; // posMap[i] = { node, offset } für text[i]
      let prevSpace = true;

      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
          const el = node.parentElement;
          if (!el) { return NodeFilter.FILTER_REJECT; }
          if (el.closest('script,style,noscript,nav,svg,template')) { return NodeFilter.FILTER_REJECT; }
          if (SKIP.has(el.tagName)) { return NodeFilter.FILTER_REJECT; }
          const cs = window.getComputedStyle(el);
          if (cs.display === 'none' || cs.visibility === 'hidden') { return NodeFilter.FILTER_REJECT; }
          return NodeFilter.FILTER_ACCEPT;
        },
      });

      let n;
      while ((n = walker.nextNode())) {
        const s = n.nodeValue || '';
        for (let i = 0; i < s.length; i++) {
          const ch = s[i];
          if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r' || ch === '\f' || ch === '\u00a0') {
            if (!prevSpace) { text += ' '; posMap.push({ node: n, offset: i }); prevSpace = true; }
          } else {
            text += ch.toLowerCase(); posMap.push({ node: n, offset: i }); prevSpace = false;
          }
        }
      }
      if (!text) { return false; }

      // 2) Mehrstufige Kandidaten-Phrasen: spezifisch (lang) → allgemeiner (kurz).
      const words = full.split(' ').filter(Boolean);
      const cand = [full, full.slice(0, 160)];
      for (const len of [16, 12, 9, 7, 5, 4]) {
        if (words.length >= len) {
          const mid = Math.max(0, Math.floor((words.length - len) / 2));
          cand.push(words.slice(mid, mid + len).join(' '));   // Mitte
          cand.push(words.slice(0, len).join(' '));           // Anfang
          cand.push(words.slice(words.length - len).join(' ')); // Ende
        }
      }
      if (normTerm.length >= 3) { cand.push(normTerm); }

      const seen = new Set();
      const phrases = cand.filter(c => c && c.length >= 3 && !seen.has(c) && (seen.add(c), true));

      // 3) Ersten Treffer im zusammengezogenen Text suchen.
      let mStart = -1, mLen = 0;
      for (const p of phrases) {
        const idx = text.indexOf(p);
        if (idx >= 0) { mStart = idx; mLen = p.length; break; }
      }
      if (mStart < 0) { return false; }

      // Range aus Zeichen-Indizes bauen (setEnd exklusiv → letzter Index + 1).
      const rangeFrom = (a, b) => {
        const s = posMap[a], e = posMap[Math.min(b, posMap.length - 1)];
        const r = document.createRange();
        try {
          r.setStart(s.node, s.offset);
          const endOff = Math.min(e.offset + 1, (e.node.nodeValue || '').length);
          r.setEnd(e.node, endOff);
        } catch (_) { return null; }
        return r;
      };

      const matchRange = rangeFrom(mStart, mStart + mLen - 1);
      if (!matchRange) { return false; }

      // 4) Exakte Trigger-Stelle innerhalb des Treffers (für engere Markierung + Zentrierung).
      let termRange = null;
      if (normTerm.length >= 3) {
        const tIdx = text.indexOf(normTerm, mStart);
        if (tIdx >= 0 && tIdx < mStart + mLen) {
          termRange = rangeFrom(tIdx, tIdx + normTerm.length - 1);
        }
      }

      // 5) Markieren (Overlay-Boxen, verändern das Layout nicht) + zentrieren.
      const sx = window.scrollX, sy = window.scrollY;
      const drawBox = (r, strong) => {
        const rects = r.getClientRects();
        let top = Infinity, bottom = -Infinity, any = false;
        for (const rc of rects) {
          if (rc.width < 1 && rc.height < 1) { continue; }
          any = true;
          const box = document.createElement('div');
          box.style.cssText = 'position:absolute;z-index:2147483647;pointer-events:none;border-radius:3px;'
            + (strong
              ? 'border:3px solid #E90C3C;background:rgba(233,12,60,0.28)'
              : 'border:2px solid rgba(233,12,60,0.55);background:rgba(233,12,60,0.12)');
          box.style.left = (rc.left + sx - 2) + 'px';
          box.style.top = (rc.top + sy - 2) + 'px';
          box.style.width = (rc.width + 4) + 'px';
          box.style.height = (rc.height + 4) + 'px';
          document.body.appendChild(box);
          top = Math.min(top, rc.top + sy);
          bottom = Math.max(bottom, rc.top + sy + rc.height);
        }
        return any ? { top, bottom } : null;
      };

      // Ganzen Kontext-Treffer dezent, die Trigger-Stelle kräftig markieren.
      const ctxBox = drawBox(matchRange, !termRange);
      const focusBox = termRange ? drawBox(termRange, true) : null;
      const box = focusBox || ctxBox;
      if (!box) { return false; }

      const center = (box.top + box.bottom) / 2;
      window.scrollTo(0, Math.max(0, center - window.innerHeight / 2));
      return true;
    }, snippet, triggerTerm);

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
