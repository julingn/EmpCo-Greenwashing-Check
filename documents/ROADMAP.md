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

## Schritt 2 — Analyse-Engine (erledigt, live)
- ✅ Eine URL auslesen (Text + Code/HTML)
- ✅ Prüfung gegen Regeln: Trigger-Begriffe + KI-Kontextbewertung
- ✅ Prüf-Status anzeigen (Text / Code aktiv · JS / OCR folgen später)
- ✅ Findings speichern

## Schritt 3 — Ergebnisse (erledigt, live)
- ✅ Findings strukturiert (Kategorie, Ampel Verstoß/Prüfen/Hinweis) + Donut-Übersicht
- ✅ Ignorieren / Erledigt je Finding
- ✅ Export Excel/CSV (inkl. Seite/Fundort je Finding)

## Schritt 4 — Nachweisen & Umformulieren (2-Wege-Prozess)
Nach Identifikation eines kritischen Findings gibt es zwei Wege: **(1) belegen** (Nachweis vorhanden) oder **(2) umformulieren**.
- ✅ **Stufe A — Beleg-Bibliothek** im Admin (`evidence`): CRUD wie Regeln, Typ (Zertifikat/Rechtsgrundlage/Methodik/freigegebene Aussage), Verknüpfung über Kategorie/Regel-ID, Quelle/Link, Gültig-bis.
- ✅ **Stufe B — Nachweis-Check je Finding:** on-demand pro Finding (Button „Nachweis prüfen"). Belege werden gezielt über **Regel-ID-Liste ODER Kategorie** gematcht; ohne Treffer direkt *nicht belegbar* (kein KI-Aufruf), mit Treffer entscheidet die KI zwischen *belegbar / belegt_anpassen / nicht_belegbar* (JSON). Ergebnis + Begründung wird am Finding gespeichert und in der Ergebnis-Ansicht angezeigt.
- ✅ **Beispiel-Bibliothek (Few-Shot)** im Admin (`training_examples`): Vorher/Nachher-Beispiele je Kategorie/Regel (Mehrfach-Regel-Verknüpfung), vorbefüllt mit **rechtlich fundierten** Beispielen aus VKU-FAQ + BDEW-Ökostrom-Gutachten (u. a. „Ökostrom → 100 % Strom aus erneuerbaren Energien", Drei-Schritt-Ansatz). Basis für Stufe C und D.
- ✅ **Stufe C — Umformulierung:** on-demand Button „Umformulieren" je Finding. Exakt-Match-Kurzschluss (wortgleiche Fundstelle → geprüfter „Nachher"-Text 1:1), sonst KI (Redakteur-Prompt) mit passenden **Beispielen** (Few-Shot) + **Belegen** als Kontext. Vorschlag editierbar; Übernehmen/Verwerfen; Speicherung in `reformulations`.
- ⬜ **Stufe D — Lernfunktion:** akzeptierte Ergebnisse → `training_examples`.
- Mensch bleibt in der Schleife: Tool schlägt vor, User akzeptiert/verwirft.

## Admin-Ausbau (geplant)
- ✅ **Sidebar-Navigation im Admin** (LAT-Stil, global) — Bereiche getrennt gelistet und einzeln erreichbar
  - ✅ **Regeln** (Liste/Editor, Import) als eigener Menüpunkt
  - ✅ **Belege** — Beleg-Bibliothek (Nachweis-Weg, Stufe A)
  - ✅ **Beispiele** — Vorher/Nachher-Bibliothek (Few-Shot, Basis für Stufe C/D)
  - ✅ **KI-Redakteure** als eigener Menüpunkt — mehrere Redakteure separat gelistet & konfigurierbar (je Redakteur eigener Prompt)
  - ✅ **Einstellungen** — Admin-Bereich zum Pflegen konkreter **Sitemaps** (werden beim Crawl je Domain genutzt)
  - ⬜ ggf. weitere Bereiche (z. B. Prüf-Archiv)
- ⬜ Grundlage für mehrere spezialisierte KI-Redakteure (z. B. je Kategorie oder Tone-of-Voice)

## Später
- ⬜ **PDF auslesen als Quelle** (statt/zusätzlich zur URL) — Upload eines PDF, Textextraktion + Prüfung wie bei URLs
- ⬜ **Verlaufsvergleich / Trend pro URL** — jeden Prüflauf als Snapshot im Archiv; bei erneuter Prüfung derselben URL Findings gegen früheren Lauf matchen (Schlüssel: rule_id + normalisierter Ausschnitt) → **behoben / neu / unverändert** + Trend (Verbesserung/Verschlechterung vs. Datum). Ziel: nach Seitenüberarbeitung positive/negative Entwicklung sichtbar machen.
- ⬜ Bilder/OCR (Umweltaussagen/Siegel in Grafiken)
- ✅ **TLD-Crawl (Tiefe 1/2/ganze Domain)** — inkrementeller Crawler in `process_step` (Phase 1 lesen, Phase 2 KI-Bewertung). Tiefe = **relative Pfad-Tiefe unter der Ausgangs-URL** (z. B. Seed `/gas` → Tiefe 1 nur `/gas/*`, Tiefe 2 bis `/gas/*/*`), „Ganze Domain" = alle Seiten des Hosts. Seiten-Erkennung per Seiten-Links **plus Sitemap** (robots.txt + `/sitemap.xml` + Admin-gepflegte Sitemaps, inkl. Sitemap-Index). Same-Site-Filter (ohne www), Query/Fragment-Normalisierung, Seiten-Obergrenzen (T1=25, T2=50, Domain=80). Offen: Subdomains gelten als fremde Seite; JS-gerenderte Links werden nur über die Sitemap gefunden.
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
