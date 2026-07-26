# EmpCo – Greenwashing Prüfung — Design System
**Design-Basis:** MVV Corporate Design Manual (`documents/MVV_Markenhandbuch.pdf`, © 2023, git-ignored).
Gleiche Marke wie LAT und OKR-Builder. Beim Bau die bewährten Tokens/Komponenten aus okr-builder (`app/layout.php`) übernehmen.

> Bei jeder Design-Änderung dieses Dokument aktualisieren. Nur Design-Tokens (CSS-Variablen) verwenden — keine hartkodierten Farben.

> **Umgesetzte UI (live, `app/layout.php`):** globale **App-Shell mit fixer Sidebar** (LAT-Stil; Sektionen Analyse / Verwaltung / System, aktiver Akzentbalken), Login-Seiten als schmale Bare-Ansicht. Komponenten u. a.: Cards, Buttons (Pill), Alerts, Badges/`sev-chip`, **Donut-Übersicht** (SVG, mehrsegmentig), aufklappbare `details.rule`-Editoren, **Checklist** (Mehrfachauswahl), **Remedy**- und **Reform**-Karten (Ergebnis), **Preview**-Popover (Hover-Screenshot).

---

## 1 · Marke (MVV Corporate Design Manual)
- **Hausfarben:** Weiß (führend) + **Technical Blue `#0049EC`** (Pantone 2387)
- **Primäre Interaktionsfarbe = Technical Blue** — alle Buttons, Links, CTAs. Hover `#263FCC`, Fläche hell `#E8EFFD`, Border `#BACEFA`
- **Hero Gradient** (nur besonders wichtige CTAs): `#8FEBA4 → #51F8A4` (Mint → Grün)
- **Sekundärfarben** (nur Tags/Labels/Icons): Energizing Purple `#B266EA`, Grass Green `#1ED05C`, Sky Blue `#40C5EF`, Cool Grey `#B6C5CD`
- **Red `#E90C3C` — NUR Fehlermeldungen**
- **Hausschrift:** Circular XX (lizenzpflichtig) → freie Entsprechung **Manrope** (Google Fonts OFL, markennah)
- **Icons:** 24×24px, 1.5px Kontur, einfarbig, min. WCAG 2.0 AA

---

## 2 · Empfohlene Design-Tokens (aus okr-builder übernehmen)
| Token | Hex | Verwendung |
|-------|-----|------------|
| `--accent` | `#0049EC` | CTAs, aktive Zustände (MVV Technical Blue) |
| `--accent-dark` | `#263FCC` | Button-Hover |
| `--accent-bg` | `#E8EFFD` | Accent-Flächen, Chips |
| `--accent-border` | `#BACEFA` | Accent-Border |
| `--bg` / `--card` | `#F7F9FC` / `#FFFFFF` | Seite / Card |
| `--text` / `--text2` / `--text3` | `#0F172A` / `#475569` / `#94A3B8` | Text-Hierarchie |
| `--border` / `--border2` | `#E2E8F0` / `#CBD5E1` | Trennlinien / Inputs |
| `--green` | `#12A150` | Konform / Erfolg (textsicher) |
| `--red` | `#E90C3C` | Verstoß / Fehler (MVV Red) |
| `--amber` | `#D97706` | Warnung / Prüfen (Eigenwert) |
| `--purple` / `--sky` / `--grass` / `--cool-grey` | `#8E3FD4` / `#40C5EF` / `#1ED05C` / `#B6C5CD` | Tags/Labels/Icons |

**Domänen-Hinweis Greenwashing-Befunde:** Ampel-Logik anbieten — Konform = `--green`, Prüfen/Risiko = `--amber`, Verstoß = `--red`. Umweltbezug NICHT über durchgängiges Grün transportieren (sonst Verwechslung mit „konform"); MVV-Grün (`--grass`) nur für Tags/Icons.

---

## 3 · Typografie
- **Manrope** (Google Fonts OFL) mit Tabular-Figures (`font-feature-settings:'tnum'`).
- h1 26px/800, h2 20px/700, Body 15px/400, Hint 13px.

---

## 4 · Komponenten (bei Bau aus okr-builder übernehmen)
- Button/`.btn` (Pill 999px, `--accent`), Card (`--radius-lg`, `--shadow`), Input/Textarea (Focus-Ring `--accent-bg`), Alert (`.ok`/`.err`), Multiselect (`.ms` mit Chips), Chip (`.chip` mit `×`).

---

## 5 · Regeln
- Nur `var(--*)` — keine hartkodierten Farben.
- Technical Blue = einzige primäre Interaktionsfarbe. Red nur für Fehler/Verstoß.
- Neue Komponenten hier dokumentieren.
