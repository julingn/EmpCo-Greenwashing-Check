# EmpCo – Greenwashing Prüfung — Anforderungen (Spezifikation)
> **Umsetzungsstand:** Alle Stufen (Analyse → Ergebnisse → Belegen/Umformulieren → Lernen → Archiv) sind **live**. Aktueller Feature-/Offen-Stand: siehe `ROADMAP.md` und `MUST_READ.md`. Dieses Dokument beschreibt die ursprüngliche Spezifikation.
> Rechtsrahmen: EmpCo-Richtlinie (EU) 2024/825 (ändert UCPD + CRD) — Schutz vor irreführenden Umweltaussagen, Transparenz zu Haltbarkeit/Reparierbarkeit/Nachhaltigkeit.

## Gesamtbild
Mehrstufiger Prozess: **Analyse → strukturierte Ergebnisse → Umformulierung → Lernen/Archiv.**

---

## Stufe 1 — Analyse von Inhalten
**Eingabequellen (eine Prüfung startet mit einer davon):**
- **URL** (einzelne Seite)
- **TLD / Domain** — mit **Umfang-Optionen**: `TLD ganz` · `Tiefe 1` · `Tiefe 2` · `nur exakte URL`
- **Dokument** (PDF-Upload)

**Zu prüfende Inhaltsarten (vollständig, nicht nur Fließtext):**
- Text
- Code (z. B. HTML-Attribute, strukturierte Daten)
- Tooltips
- Sternchentexte / Fußnoten
- Bilder → **OCR/KI-Vision** (Umweltaussagen/Siegel in Grafiken)

**Prüf-Status transparent machen:** In der Analyse kenntlich machen, welche Teilprüfungen erfolgreich durchgelaufen sind — **Text-Suche, Code, JS, OCR** (je Quelle/Seite als Status-Anzeige).

**Prüfgrundlage (empco_rules.xlsx):**
- 23 Regeln in 7 Kategorien. Jedes Finding = Treffer einer Regel → Kategorie + Regelbezug.
- Rechtsquellen: EmpCo-Richtlinie (EU) 2024/825 + BDEW-Anwendungshilfe UWG-Novelle 2026 (`documents/2026-03-19_BDEW-Anwendungshilfe_UWG_Novelle_2026.pdf`).

---

## Stufe 2 — Ergebnisdarstellung
- **Strukturierte Abbildung** der Analyseergebnisse (nach Kategorie/Kriterium, je Finding: Fundstelle, Inhaltsart, Bewertung).
- **Export** der Daten als **Excel/CSV**.
- Findings verwalten:
  - **Ignorieren** (aus Anzeige nehmen)
  - **Erledigt** markieren
- (Analog zum LAT-Content-Finder: Status je Finding, Export enthält weiterhin alle Findings.)

---

## Stufe 3 — Umformulierung
- Für **markierte** Findings: Vorschläge für konforme Umformulierungen.
- Zwei Wege:
  - **Manuell** (Redakteur formuliert selbst)
  - **KI-basiert** (Vorschlag generieren)
- Akzeptieren/Verwerfen je Vorschlag.
- **KI-Agent:** Start mit **einem** KI-Redakteur. Dessen **Prompt muss im Tool bearbeitbar** sein (wie LAT/okr-builder Agent-Registry). Weitere/spezialisierte Redakteure (z. B. je Kategorie) erst bei Bedarf.

## Stufe 3b — Tone of Voice (mittelfristig)
- Neu formulierte Inhalte sollen zusätzlich durch die **Tone-of-Voice-Agenten** laufen.
- → mittelfristig ein **weiterer Schritt** in der Inhaltserstellung: Finding → konforme Umformulierung → Tone-of-Voice-Feinschliff.

---

## Stufe 4 — Lernen / „KI-Redakteure"
- **Akzeptierte Änderungen** werden gespeichert und zum Training der eigenen KI-Redakteure genutzt.
- Ziel: künftige Prüfung **ähnlicher Inhalte** schneller/treffsicherer.
- Umsetzungsidee: akzeptierte (Finding → Umformulierung)-Paare als Beispiel-/Regelbasis; bei neuer Analyse als Kontext einspeisen. (Detailkonzept später.)

---

## Archiv & Datenbank
- **Archiv** aller Prüfläufe mit Datenbank (PostgreSQL).
- Speichert: Prüfläufe, Quellen, Findings + Status, Umformulierungen, akzeptierte Trainingsbeispiele, Regelset-Versionen.

---

## Regel-Datenmodell (aus empco_rules.xlsx)
Spalten je Regel:
- `rule_id` (z. B. `EMPCO-001-PAUSCHAL-UMWELT`)
- `category` (7 Kategorien: pauschalaussage, klimaneutralitaet_kompensation, teil_zu_gesamt, vergleichende_aussage, gesetzeskonformitaet_als_usp, eigene_siegel, zukunftsversprechen)
- `description` (was die Regel prüft)
- `trigger_terms` (kommaseparierte Signalbegriffe, z. B. „umweltfreundlich, ökologisch, öko …")
- `example_violation` (Negativbeispiel)
- `example_ok` (konformes Positivbeispiel)
- `law_reference` (z. B. „UCPD Annex I 4a; § 5 UWG / Anhang UWG")

→ Regeln in DB importieren, **im Admin pflegbar**. Analyse = Treffer auf `trigger_terms` + KI-Bewertung im Kontext (nicht nur reiner Wort-Match).

---

## Geklärt (23.07.2026)
1. **Regeln/Recht:** empco_rules.xlsx (23 Regeln) + BDEW-Anwendungshilfe-PDF liegen vor.
2. **TLD-Umfang:** Optionen `TLD ganz` / `Tiefe 1` / `Tiefe 2` / `nur exakte URL`.
3. **Bilder:** ja, OCR/Vision — Prüf-Status (Text/Code/JS/OCR) in der Analyse anzeigen.
4. **Export:** Excel/CSV.
5. **KI-Redakteur:** mit einem starten, Prompt im Tool editierbar; ggf. später mehr.
6. **Tone of Voice:** mittelfristig als zusätzlicher Schritt nach der Umformulierung.

## Noch offen (klein)
- **Zugang/Login:** ja — wie okr-builder (Eingabe-Login + Admin-Login).
- **KI-Anbieter:** OpenAI (wie LAT/okr-builder).
- **Content-Sprachen:** Deutsch **und ggf. Englisch** → Analyse + Umformulierung sprachbewusst (DE/EN).

> **Spezifikation abgeschlossen (23.07.2026).** Bereit zum Bau auf „los" des Users.

---

## Technik-Rahmen (geplant, analog okr-builder)
- Eigenständig: eigenes GitHub-Repo (julingn) + eigener Railway-Service.
- Stack: PHP 8.3 + PostgreSQL + KI. Für URL/TLD-Crawl + PDF/Bild ggf. Node/Puppeteer + Vision-API (wie LAT Content-Finder).
- Railway-Stolpersteine beachten (Start command via `sh -c`, `$PORT` nur in Shell).
