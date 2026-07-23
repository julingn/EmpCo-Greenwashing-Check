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

## Später
- ⬜ **PDF auslesen als Quelle** (statt/zusätzlich zur URL) — Upload eines PDF, Textextraktion + Prüfung wie bei URLs
- ⬜ **Verlaufsvergleich / Trend pro URL** — jeden Prüflauf als Snapshot im Archiv; bei erneuter Prüfung derselben URL Findings gegen früheren Lauf matchen (Schlüssel: rule_id + normalisierter Ausschnitt) → **behoben / neu / unverändert** + Trend (Verbesserung/Verschlechterung vs. Datum). Ziel: nach Seitenüberarbeitung positive/negative Entwicklung sichtbar machen.
- ⬜ Bilder/OCR (Umweltaussagen/Siegel in Grafiken)
- ⬜ TLD-Crawl (Tiefe 1/2/ganze Domain)
- ⬜ Stufe 3b: Tone-of-Voice-Agenten nach der Umformulierung
- ⬜ Lernfunktion: aus akzeptierten Änderungen neue Regeln/Trainingsbeispiele
- ⬜ Historie / Archiv mehrerer Prüfläufe
- ⬜ Suchmetriken/Filter für Content-Bausteine

## Notiz
Detailfakten in `MUST_READ.md`, Anforderungen in `ANFORDERUNGEN.md`, Design in `DESIGN_SYSTEM.md`.
