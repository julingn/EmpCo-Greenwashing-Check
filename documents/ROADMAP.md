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
- ✅ Prüf-Status anzeigen (Text / Code immer · JS-Rendering & OCR optional je Analyse)
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
- ✅ **Stufe D — Lernfunktion:** akzeptierte Umformulierungen werden automatisch als Beispiel in `training_examples` gespeichert (Herkunft `learned`, Upsert je Finding – kein Duplikat) und fließen als Few-Shot in künftige Umformulierungen ein.
  - ✅ **Un-Learn:** gelernte Beispiele sind im Admin („Beispiele“) als „gelernt“ gekennzeichnet und einzeln löschbar; Bearbeiten stuft sie auf „kuratiert“ hoch. Damit ist ein akzeptierter Vorschlag jederzeit wieder aus dem Trainingsgedächtnis entfernbar.
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
- ✅ **PDF auslesen als Quelle** — Upload eines PDF auf der Startseite (alternativ zur URL); Textextraktion via `pdftotext` (poppler-utils), dann Prüfung gegen die Regeln wie bei URLs (kein Crawl). Upload-Limit 25 MB.
- ⬜ **Verlaufsvergleich / Trend pro URL** — jeden Prüflauf als Snapshot im Archiv; bei erneuter Prüfung derselben URL Findings gegen früheren Lauf matchen (Schlüssel: rule_id + normalisierter Ausschnitt) → **behoben / neu / unverändert** + Trend (Verbesserung/Verschlechterung vs. Datum). Ziel: nach Seitenüberarbeitung positive/negative Entwicklung sichtbar machen.
- ✅ **Bilder/OCR (Umweltaussagen/Siegel in Grafiken)** — optionaler Umschalter je Analyse: Bilder/Siegel per **Tesseract** (deu+eng) auslesen (max. 8 Bilder, ab 80×80 px), Treffer als Inhaltsart „image“. Zusammen mit **JS-Rendering** (ebenfalls Umschalter): Seite wird headless gerendert, JS-Inhalte + JS-verlinkte Seiten werden erfasst.
- ✅ **TLD-Crawl (Tiefe 1/2/ganze Domain)** — inkrementeller Crawler in `process_step` (Phase 1 lesen, Phase 2 KI-Bewertung). Tiefe = **relative Pfad-Tiefe unter der Ausgangs-URL** (z. B. Seed `/gas` → Tiefe 1 nur `/gas/*`, Tiefe 2 bis `/gas/*/*`), „Ganze Domain" = alle Seiten des Hosts. Seiten-Erkennung per Seiten-Links **plus Sitemap** (robots.txt + `/sitemap.xml` + Admin-gepflegte Sitemaps, inkl. Sitemap-Index). Same-Site-Filter (ohne www), Query/Fragment-Normalisierung, Seiten-Obergrenzen (T1=25, T2=50, Domain=80). Offen: Subdomains gelten als fremde Seite; JS-gerenderte Links werden mit aktivem **JS-Rendering** (Umschalter) gefunden, sonst nur über die Sitemap.
- ✅ **Stufe 3b: Tone-of-Voice-Agent (manuell) nach der Umformulierung** — fester Agent `tone_of_voice` („Tonalitäts-Redakteur (Brand Voice)“, MVV-Markenstimme). Ablauf: erst **Umformulieren** (EmpCo-konform), dann optional Button **„Tonalität anpassen“** je Finding. **Beide Fassungen bleiben erhalten** und werden getrennt angezeigt: die EmpCo-konforme Basis (`text`) und die Brand-Voice-Fassung (`tov_text`) — der Nutzer übernimmt eine der beiden. Der ToV-Redakteur führt **keine** neuen Umweltaussagen/Fakten ein. Beim Übernehmen wird `agents_used` gesetzt (z. B. „EmpCo-Redakteur + Tonalität (Brand Voice)“). Button nur, wenn ToV-Agent aktiv; Prompt im Admin pflegbar.
- ⬜ Lernfunktion: aus akzeptierten Änderungen neue Regeln/Trainingsbeispiele
- ⬜ **Fehltreffer-Lernen (Trigger-Kontext)** — Trigger matchen bewusst als Substring (damit z. B. „Vergrünung" erkannt wird). Dadurch entstehen Fehltreffer wie „grün" in „**Grün**de". Diese als Trainingssignal nutzen:
  - Wenn die KI (oder der User via „Ignorieren"/„Fehltreffer") eine Fundstelle als Fehltreffer einstuft, das Paar **(rule_id + Trigger-Begriff + normalisierter Ausschnitt/Wort)** speichern (`training_examples`).
  - Bei späteren Analysen bekannte Fehltreffer als Kontext in den KI-Prompt einspeisen → konsistente Einstufung („‚Gründe' ist beim Trigger ‚grün' ein Fehltreffer").
  - Optional: aus wiederkehrenden Fehltreffern eine Ausschluss-/Kontextregel je Trigger ableiten.
  - **Wichtig:** Erfassung der Fehltreffer-Signale möglichst früh starten, damit später Trainingsdaten vorhanden sind.
- ✅ **Historie / Archiv mehrerer Prüfläufe** — Seite `archive.php` (Sidebar „Prüf-Archiv") listet alle Läufe (Quelle, Umfang, Seiten, JS/OCR, Status, Datum, Findings-Ampel); Ergebnis erneut öffnen oder Lauf löschen (kaskadiert).
- ✅ **Preview-Treffergenauigkeit** — Fundstellen-Vorschau neu aufgesetzt: statt `window.find` wird der **gesamte sichtbare Seitentext knoten-übergreifend zu einem normalisierten String** zusammengezogen (Kleinschreibung, „…"/Whitespace-Kollaps **analog zur PHP-`strip_tags`-Extraktion**), mit **zeichengenauem Rück-Mapping** auf DOM-Knoten. Treffer per **mehrstufiger Teilstring-Suche** (lange → kurze Phrasen: ganzer Snippet, Mitte/Anfang/Ende-Fenster, zuletzt Trigger-Begriff). Der **Trigger-Begriff** wird aus der Regel an das Skript übergeben (`preview.php` → `preview_shot.mjs`) und **innerhalb** des Kontext-Treffers **kräftig markiert + exakt zentriert** (Kontext dezent). So werden auch über Inline-Tags/Zeilenumbrüche verteilte Stellen korrekt getroffen. Offen bei Bedarf: echte Fuzzy-Toleranz (Tippfehler/OCR-Abweichungen).
- ⬜ Suchmetriken/Filter für Content-Bausteine
- ⬜ **Lernen (D) sauber von der Tonalität trennen** — beim Übernehmen einer Umformulierung wird aktuell die **gewählte** Fassung als Trainingsbeispiel gelernt. Wird die **Brand-Voice-Fassung** (`tov_text`) übernommen, fließt der tonal gefärbte Text als Few-Shot in künftige **EmpCo**-Umformulierungen ein und kann den Umformulierungs-Redakteur stilistisch mitprägen. To-do: beim Lernen immer die **EmpCo-konforme Basis** (`text`) als „Nachher" speichern (statt der ToV-Fassung), damit Compliance-Lernen und Tonalität getrennt bleiben.

## Notiz
Detailfakten in `MUST_READ.md`, Anforderungen in `ANFORDERUNGEN.md`, Design in `DESIGN_SYSTEM.md`.
