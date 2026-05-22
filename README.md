# §14a EnWG Modul 3 – Netzentgelte

Community-gepflegtes Repository mit den zeitvariablen Netzentgelten aller deutschen Verteilnetzbetreiber gemäß **§14a EnWG Modul 3**.

> [!TIP]
> Fertige Web-Ansicht: **[mastr.github.io/dynamic_energy_fees](https://mastr.github.io/dynamic_energy_fees)**

---

## Warum dieses Repository?

Seit April 2025 müssen alle ~860 deutschen Verteilnetzbetreiber zeitvariable Netzentgelte (Modul 3) anbieten. Die Tarife sind zwar öffentlich auf den Preisblättern der Netzbetreiber, aber nicht maschinenlesbar. Dieses Projekt sammelt sie in einem strukturierten, community-gepflegten Format – damit niemand das Rad neu erfinden muss.

**Anwendungsfälle:**
- Home Assistant / HEMS-Automatisierung
- Eigene Kostenoptimierung

---

## Datenstruktur

Die Dateien sind nach Land in Unterordnern abgelegt:

```
operators/
  de/        # ~860 deutsche Verteilnetzbetreiber
    syna.yaml
    bayernwerk.yaml
    …
  at/        # österreichische Netzbetreiber
  ch/        # Schweizer Netzbetreiber
```

Jeder Netzbetreiber hat eine eigene YAML-Datei (z.B. `operators/de/syna.yaml`):

```yaml
id: syna
name: "SYNA GmbH (Süwag Netz AG)"
bdew_code: "9907697000009"
website: "https://www.syna.de"
regions:
  - Hessen

tariffs:
  "2026":
    Q1:
      - from: "00:00"
        to: "06:00"
        tariff: NT           # Niedertarif
        price_ct_kwh_net: 2.61
      - from: "06:00"
        to: "17:00"
        tariff: ST           # Standardtarif
        price_ct_kwh_net: 8.71
      - from: "17:00"
        to: "22:00"
        tariff: HT           # Hochtarif
        price_ct_kwh_net: 14.68
      - from: "22:00"
        to: "24:00"
        tariff: NT
        price_ct_kwh_net: 2.61
    Q2: ...
    Q3: ...
    Q4: ...
```

### Validierungsregeln

Beim Pull-Request läuft automatisch `scripts/validate.mjs` und prüft:

| Regel | Quelle |
|-------|--------|
| Zeitfenster lückenlos 00:00–24:00 | –  |
| HT muss ≥ 2 h/Tag aktiv sein | §14a BK8-22/010-A |
| NT muss 10–40 % von ST betragen | §14a BK8-22/010-A |
| HT darf maximal 2× ST betragen | §14a BK8-22/010-A |
| `id` muss mit Dateiname übereinstimmen | – |
| Datei liegt unter `operators/<country>/` | – |

---

## Netzbetreiber ergänzen

1. `operators/<country>/<kürzel>.yaml` anlegen, z.B. `operators/de/stadtwerke-musterstadt.yaml`
2. Pull-Request öffnen
3. Validation-Workflow prüft die Datei automatisch
4. Nach Merge: Seite wird automatisch neu gebaut und deployed

---

## Lokale Entwicklung

```bash
npm install
npm run validate   # Alle YAML-Dateien validieren
npm run build      # dist/ aufbauen
npm run dev        # Validieren + Bauen + lokaler Server
```

---

## Mitmachen

Issues mit dem Label **`missing-tariff`** werden jeden Oktober automatisch für Netzbetreiber geöffnet, die noch keine Daten für das Folgejahr haben. Einfach das Issue nehmen, Preisblatt des Netzbetreibers aufrufen und die Daten eintragen!

---

## Lizenz

MIT – Daten stammen aus den öffentlichen Preisblättern der Netzbetreiber.
