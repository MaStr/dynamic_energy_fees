## Neuer Netzbetreiber / New operator

**Name:** <!-- z.B. SYNA GmbH (Süwag Netz AG) -->
**Datei:** <!-- z.B. operators/de/syna.yaml -->
**Preisblatt (Quelle):** <!-- URL zum offiziellen Preisblatt -->

---

### Checkliste

**Pflichtfelder**
- [ ] `id` stimmt mit dem Dateinamen überein (ohne `.yaml`)
- [ ] `name`, `website` sind ausgefüllt
- [ ] `bdew_code` ist eingetragen (falls bekannt)
- [ ] `regions` enthält mindestens ein Bundesland / einen Kanton

**Tarifstruktur**
- [ ] Alle vier Quartale vorhanden (Q1–Q4)
- [ ] Zeitfenster lückenlos von `00:00` bis `24:00` pro Quartal
- [ ] Preise sind **Netto** in **ct/kWh**

**Nur für 🇩🇪 DE (§14a BK8-22/010-A)**
- [ ] NT-Preis beträgt 10–40 % des ST-Preises
- [ ] HT-Preis beträgt maximal 2× ST
- [ ] HT ist mindestens 2 h/Tag aktiv (falls HT vorhanden)

**Datei**
- [ ] Datei liegt unter `operators/<country>/` (z.B. `operators/de/`)
- [ ] Daten für das aktuelle Jahr **und** das Folgejahr eingetragen (falls schon bekannt)

---

### Validierung

Der CI-Workflow prüft alle Regeln automatisch. Bei Fehlern bitte die Logs im Reiter **Checks** aufrufen.
