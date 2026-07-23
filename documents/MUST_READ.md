# EmpCo – Greenwashing Prüfung — Must Read
> Zentrale Projektfakten. Nach jedem relevanten Schritt/Deploy aktualisieren.

## Status
- **Noch nicht gebaut.** Ordner + Doku angelegt; Bau wartet auf Freigabe des Users.
- Ordner: `C:\Users\U18716\EmpCo - Greenwashing Prüfung` (Multi-Root-Workspace neben LAT + okr-builder).

## Zweck
- Content-Finder / Greenwashing-Prüfung nach **EmpCo-Richtlinie (EU) 2024/825** (Empowering Consumers for the Green Transition).
- Rechtsrahmen: ändert die Richtlinie über unlautere Geschäftspraktiken (UCPD) + Verbraucherrechte-Richtlinie (CRD).
- Kernanliegen: Schutz vor irreführenden Umweltaussagen („Greenwashing"), Transparenz zu Haltbarkeit, Reparierbarkeit und Nachhaltigkeit.

## Funktionsumfang (MVP)
1. **Analyse:** Content-Bausteine finden und gegen ein Regelset (EmpCo/UCPD/CRD) bewerten/kennzeichnen.
2. **Reformulierung:** neue, konforme Formulierungen nach Regelset erzeugen.
- **Lernfunktion: NICHT im MVP** — kommt später (aus manuellen Korrekturen neue Regeln ableiten).

## Entscheidungen (Stand: offen/festgelegt)
- Regelset-Start: **User liefert eigenes Regelset** (nicht Copilot-generiert, nicht leer).
- Lernfunktion: erst später.
- Eingabeart (Text einfügen vs. URL crawlen): **NOCH OFFEN** — vor Bau klären.

## Technik-Plan (analog okr-builder)
- Eigenständiges Projekt: eigenes GitHub-Repo (julingn) + eigener Railway-Service.
- Stack: PHP 8.3 + PostgreSQL + KI (OpenAI/Anthropic). Deploy-Dateien: Dockerfile / nixpacks.toml / Procfile / router.php.
- **Railway-Stolpersteine (aus okr-builder gelernt):**
  - Start command darf NICHT mit `VAR=wert` beginnen → sonst „executable not found".
  - `$PORT` nur in Shell auflösbar → Start command `sh -c "php -S 0.0.0.0:$PORT -t . router.php"` oder Feld leeren (Dockerfile-CMD greift).
- ENV: `DATABASE_URL` (`${{Postgres.DATABASE_URL}}`), `ADMIN_PASSWORD`, ggf. `APP_PASSWORD`, `OPENAI_API_KEY`/`ANTHROPIC_API_KEY`.

## Nächster Schritt bei Freigabe
1. Eingabeart festlegen (Text/URL/beides).
2. User-Regelset einsammeln → als Startdaten in DB.
3. Scaffold wie okr-builder, dann GitHub + Railway.
