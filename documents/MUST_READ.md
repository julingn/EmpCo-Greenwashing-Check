# EmpCo – Greenwashing Prüfung — Must Read
> Zentrale Projektfakten. Nach jedem relevanten Schritt/Deploy aktualisieren.

## Status
- **Live auf Railway** (Auto-Deploy von GitHub `main`, Repo `julingn/EmpCo-Greenwashing-Check`). Der aktive Builder ist das **Dockerfile**.
- Kernfunktion vollständig umgesetzt: **Analyse → Ergebnisse → 2-Wege-Prozess (Belegen / Umformulieren) → Lernfunktion**.
- Ordner: `C:\Users\U18716\EmpCo - Greenwashing Prüfung` (Multi-Root-Workspace neben LAT + okr-builder).

## Zweck
- Content-Finder / Greenwashing-Prüfung nach **EmpCo-Richtlinie (EU) 2024/825** (UWG-Novelle, neue Umweltwerbe-Vorgaben ab 27.09.2026).
- Prüft Werbeaussagen auf irreführende Umweltaussagen; schlägt **Belege** oder **konforme Umformulierungen** vor.

## Was das Tool kann (live)
### Analyse
- Quelle: **URL** (exakt / Tiefe 1 / Tiefe 2 / ganze Domain) **oder PDF-Upload** (Text via `pdftotext`).
- Crawl: inkrementell; Tiefe = **relative Pfad-Tiefe** unter der Ausgangs-URL; Seiten-Erkennung per Links **+ Sitemap** (robots.txt/`sitemap.xml` + Admin-gepflegte Sitemaps).
- Optionale Umschalter je Analyse: **JS-Rendering** (Headless-Chromium) und **OCR** (Tesseract, Text in Bildern/Siegeln).
- Prüfung: Trigger-Begriffe (Substring) → Kandidaten → **KI-Kontextbewertung** (OpenAI). Fortschritt zweiphasig (Lesen → Prüfen).
### Ergebnisse
- Findings mit Ampel (Verstoß/Prüfen/Hinweis) + **Donut-Übersicht**; Ignorieren/Erledigt; **CSV-Export** (inkl. Seite/Fundort).
- **Preview:** Hover-Screenshot der Fundstelle (headless gerendert, Stelle via `window.find` markiert).
- **Prüf-Archiv** (`archive.php`): alle Läufe auflisten, öffnen, löschen.
### 2-Wege-Prozess (Schritt 4)
- **Belegen (A/B):** Beleg-Bibliothek im Admin + **Nachweis-Check** je Finding (belegbar / belegt_anpassen / nicht_belegbar).
- **Umformulieren (C):** Button je Finding; Exakt-Match-Kurzschluss auf geprüfte Beispiele, sonst KI mit **Few-Shot-Beispielen + Belegen**. Danach optional Button **„Tonalität anpassen“** (Stufe 3b, manuell, MVV Brand Voice): erzeugt eine tonale Fassung. **Beide Fassungen bleiben erhalten** (`text` konform + `tov_text` Brand Voice) und werden getrennt angezeigt; der Nutzer übernimmt eine. `agents_used` dokumentiert die beteiligten Redakteure. Vorschläge editierbar, Übernehmen/Verwerfen.
- **Lernen (D):** akzeptierte Umformulierung → gelerntes `training_example` (Herkunft `learned`); **Un-Learn** per Löschen im Admin.

## Admin (Sidebar, passwortgeschützt)
- **Verwaltung:** Regeln (Import xlsx/CSV + Editor) · Belege · Beispiele (kuratiert + gelernt)
- **System:** KI-Redakteure (Prompt je Agent) · Einstellungen (Sitemaps)

## Technik
- Stack: **PHP 8.3 + PostgreSQL + OpenAI**. Headless **Chromium/Puppeteer** (Preview + JS-Rendering), **poppler-utils** (PDF), **tesseract** deu+eng (OCR).
- Deploy über **Dockerfile** (installiert chromium, nodejs/npm, poppler-utils, tesseract). Start: `php -d upload_max_filesize=25M … -S 0.0.0.0:$PORT -t . router.php`.
- Wichtige Dateien: `index.php` (Eingabe) · `results.php` (Ergebnisse) · `archive.php` · `admin.php` · `preview.php` + `preview_shot.mjs` · `render.mjs` · `export.php` · `analyze_step.php`; `app/`: `config.php`, `db.php`, `analyzer.php`, `ai.php`, `layout.php`.
- **ENV (Railway):** `DATABASE_URL`, `ADMIN_PASSWORD`, ggf. `APP_PASSWORD`, `OPENAI_API_KEY` (`OPENAI_MODEL` default `gpt-4o`), optional `ANTHROPIC_API_KEY`.

## DB-Schema (in `db_init()` angelegt/migriert)
`rules` · `analyses` (+`use_js`/`use_ocr`) · `pages` (+`status`/`depth`) · `candidates` · `findings` (+`remedy_path`/`remedy_evidence`/`remedy_note`) · `reformulations` (+`agents_used`/`tov_text`) · `training_examples` (+`source`/`finding_id`) · `evidence` · `sitemaps` · `agents` (Redakteure: `reformulator` + `tone_of_voice`) · `settings`

## Wichtige gelernte Fallstricke
- **PDO + PostgreSQL Boolean:** PHP-`false` wird zu `''` → Boolean-Bind **immer als `(int)` (0/1)** übergeben (sonst `22P02`).
- Railway: Start-Command nicht mit `VAR=wert` beginnen; `$PORT` nur in der Shell auflösbar.
- Trigger matchen als **Substring** (damit z. B. „Vergrünung“ gefunden wird) → Fehltreffer („grün“ in „Gründe“) bewusst; KI filtert, Fehltreffer-Lernen ist geplant.

## Offen / bekannt (Details in `ROADMAP.md`)
- Preview-Treffer neu: knoten-übergreifender normalisierter Text-Index + zeichengenaues Rück-Mapping, mehrstufige Teilstring-Suche, Trigger-Begriff wird gezielt markiert/zentriert (`preview.php` übergibt ihn an `preview_shot.mjs`). Fuzzy-Toleranz (Tippfehler/OCR) noch offen.
- Verlaufsvergleich/Trend pro URL · Fehltreffer-Lernen · Tone-of-Voice-Agenten · Suchmetriken/Filter · Subdomains im Crawl · OCR-Feintuning.

## Doku
Anforderungen in `ANFORDERUNGEN.md`, Roadmap in `ROADMAP.md`, Design in `DESIGN_SYSTEM.md`.
