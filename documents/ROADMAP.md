# EmpCo – Greenwashing Prüfung — Roadmap

## Vorbereitung (erledigt)
- ✅ Projektordner + Workspace, Doku, MVV-Markenhandbuch als Design-Basis
- ✅ Anforderungen abgestimmt (`ANFORDERUNGEN.md`)
- ✅ Regelset vorliegend (empco_rules.xlsx, 23 Regeln) + BDEW-Anwendungshilfe

## Schritt 1 — Fundament (erledigt, live)
- ✅ Grundgerüst (PHP 8.3, PostgreSQL, Railway) + Auto-Deploy
- ✅ MVV-Design (Manrope, Tokens)
- ✅ Login (Eingabe + Admin)
- ✅ DB-Schema (rules, analyses, pages, findings, reformulations, training_examples, settings)
- ✅ Regel-Import (xlsx **und** CSV) + Regeln tabellarisch editierbar im Admin
- ✅ KI-Redakteur-Prompt im Admin editierbar

## Schritt 2 — Analyse-Engine (als Nächstes)
- ⬜ Eine URL auslesen (Text + Code/HTML)
- ⬜ Prüfung gegen Regeln: Trigger-Begriffe + KI-Kontextbewertung
- ⬜ Prüf-Status anzeigen (Text / Code / JS / OCR)
- ⬜ Findings speichern

## Schritt 3 — Ergebnisse
- ⬜ Findings strukturiert (Kategorie, Ampel konform/prüfen/Verstoß)
- ⬜ Ignorieren / Erledigt je Finding
- ⬜ Export Excel/CSV

## Schritt 4 — Umformulierung
- ⬜ Vorschläge manuell + KI, akzeptieren/verwerfen

## Admin-Ausbau (geplant)
- ⬜ **Sidebar-Navigation im Admin** — Bereiche getrennt gelistet und einzeln erreichbar
  - ⬜ **Regeln** (Liste/Editor, Import) als eigener Menüpunkt
  - ⬜ **KI-Redakteure** als eigener Menüpunkt — mehrere Redakteure separat gelistet & konfigurierbar (je Redakteur eigener Prompt)
  - ⬜ ggf. weitere Bereiche (z. B. Prüf-Archiv, Einstellungen)
- ⬜ Grundlage für mehrere spezialisierte KI-Redakteure (z. B. je Kategorie oder Tone-of-Voice)

## Später
- ⬜ **PDF auslesen als Quelle** (statt/zusätzlich zur URL) — Upload eines PDF, Textextraktion + Prüfung wie bei URLs
- ⬜ **Verlaufsvergleich / Trend pro URL** — jeden Prüflauf als Snapshot im Archiv; bei erneuter Prüfung derselben URL Findings gegen früheren Lauf matchen (Schlüssel: rule_id + normalisierter Ausschnitt) → **behoben / neu / unverändert** + Trend (Verbesserung/Verschlechterung vs. Datum). Ziel: nach Seitenüberarbeitung positive/negative Entwicklung sichtbar machen.
- ⬜ Bilder/OCR (Umweltaussagen/Siegel in Grafiken)
- ✅ **TLD-Crawl (Tiefe 1/2/ganze Domain)** — inkrementeller Crawler in `process_step` (Phase 1 lesen, Phase 2 KI-Bewertung), Same-Site-Filter (ohne www), Query/Fragment-Normalisierung, Seiten-Obergrenzen (Tiefe1=20, Tiefe2=40, Domain=60). Offen: Subdomains werden noch als fremde Seite behandelt; JS-gerenderte Links werden nicht gefunden.
- ⬜ Stufe 3b: Tone-of-Voice-Agenten nach der Umformulierung
- ⬜ Lernfunktion: aus akzeptierten Änderungen neue Regeln/Trainingsbeispiele
- ⬜ **Fehltreffer-Lernen (Trigger-Kontext)** — Trigger matchen bewusst als Substring (damit z. B. „Vergrünung" erkannt wird). Dadurch entstehen Fehltreffer wie „grün" in „**Grün**de". Diese als Trainingssignal nutzen:
  - Wenn die KI (oder der User via „Ignorieren"/„Fehltreffer") eine Fundstelle als Fehltreffer einstuft, das Paar **(rule_id + Trigger-Begriff + normalisierter Ausschnitt/Wort)** speichern (`training_examples`).
  - Bei späteren Analysen bekannte Fehltreffer als Kontext in den KI-Prompt einspeisen → konsistente Einstufung („‚Gründe' ist beim Trigger ‚grün' ein Fehltreffer").
  - Optional: aus wiederkehrenden Fehltreffern eine Ausschluss-/Kontextregel je Trigger ableiten.
  - **Wichtig:** Erfassung der Fehltreffer-Signale möglichst früh starten, damit später Trainingsdaten vorhanden sind.
- ⬜ Historie / Archiv mehrerer Prüfläufe
- ⬜ Suchmetriken/Filter für Content-Bausteine

## Notiz
Detailfakten in `MUST_READ.md`, Anforderungen in `ANFORDERUNGEN.md`, Design in `DESIGN_SYSTEM.md`.
