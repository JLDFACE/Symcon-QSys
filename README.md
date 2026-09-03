# Symcon QSys Control

IP-Symcon Modul zur Steuerung eines **Q-SYS Core** (QSC) über **QRC**
(Q-SYS Remote Control) — JSON-RPC 2.0 über TCP-Port **1710**, jede Nachricht
`\0`-terminiert. Optimiert für den stabilen Dauerbetrieb auf der **SymBox**.

Aufbau und Bedienphilosophie folgen dem FACE-Hausstil (wie `Symcon-Bose`):
eine zentrale **Device-Instanz** auf einer Client-Socket, darunter beliebig
viele **Control-Kinder** und ein **Configurator** zum automatischen Anlegen.

---

## Voraussetzungen im Q-SYS Designer

- Die zu steuernden Bausteine als **Named Components** anlegen und auf
  **Script Access = All** (bzw. External) setzen. Nur so sind sie über QRC
  erreichbar.
- Externe Steuerung am Core zulassen. Verlangt der Core eine PIN/Benutzer-
  verwaltung, User/Passwort in der Core-Instanz eintragen (Logon erfolgt automatisch).
- QRC kann **kein** Design authoring — es liest/schreibt nur Controls. UCIs und
  das Design selbst bleiben Designer-Handarbeit.

---

## Modulübersicht

### QSys Core (Device)
Zentrale Geräteinstanz auf einer Client-Socket (Port 1710).
- Framing (`\0` + JSON) in beide Richtungen, Teilframe-Pufferung
- optionaler **Logon**, **StatusGet** (Design-Name, Engine-Status), Online-Status
- **NoOp-Keepalive** (30 s) — der Core trennt sonst nach 60 s Stille
- verwaltet **eine ChangeGroup** mit **AutoPoll**: Kinder abonnieren Controls,
  der Core **pusht** Änderungen → Fan-out an die Kinder (kein Sekunden-Polling)
- stabile Reconnect-/Holdoff-Mechanik (aus dem Bose-Device übernommen)

### QSys Control (generisch)
Universal-Baustein: bindet **eine** Variable an ein Control.
Komponente leer = *Named Control* (`Control.Set`), sonst *Component-Control*.
Typ wählbar (Zahl / Schalter / Text), optional mit Ramp.

### QSys Gain
Lautstärke/Pegel einer Gain-Komponente: **dB**, **%** (über die Fader-Position,
taper-korrekt) und **Mute**, mit **Ramp** für flüssige Fades. Optionaler
KNX-Relativ-Dimm-Block (Start/Stop + Richtung).

### QSys Router
Quellen-/Router-Umschaltung als Integer-Selector mit sprechenden Quellen-Namen
(z. B. Router-Control `select.1`).

### QSys Snapshot
Snapshot-Bank laden/speichern (`Snapshot.Load`/`Save`, Bank + Nummer + Ramp).

### QSys Trigger
Momentane Tasten (Page, Bell, Mute-All …): ein Klick sendet `Value = 1`.

### QSys Configurator
Liest das **laufende Design** live aus (`Component.GetComponents` /
`Component.GetControls`) und legt per Klick passende Instanzen an — Gain-,
Router-, Snapshot-Komponenten werden erkannt, jedes Control ist als generisches
*QSys Control* anlegbar. Der Configurator wird **unter einen QSys Core** gehängt
und nutzt dessen Host/Port über eine eigene, kurzlebige Abfrageverbindung.

---

## Push statt Polling

Anders als bei seriellen Protokollen muss hier **nicht** zyklisch gepollt
werden: Kinder melden ihr Interesse an einzelnen Controls am Core an, der Core
fasst alles in **einer** ChangeGroup zusammen und lässt sich per **AutoPoll**
vom Core beliefern. Änderungen im Designer landen so nahezu sofort in Symcon,
ohne Dauerlast. Variablen werden nur bei tatsächlicher Wertänderung geschrieben.

---

## Netzwerk & Port

- Verbindung über eine **Client-Socket-Instanz**
- Der Port wird beim ersten `ApplyChanges` automatisch auf **1710** gesetzt,
  sofern noch keiner eingetragen ist; Host und Port bleiben frei änderbar.

---

## Installation

1. IP-Symcon → **Modulverwaltung → Repositories** → dieses Repository hinzufügen
2. Instanz **QSys Core** anlegen (Client-Socket + Host des Cores)
3. **QSys Configurator** unter den Core hängen und das Design auslesen — oder
   Control-/Gain-/Router-/Snapshot-/Trigger-Instanzen von Hand anlegen

### Hinweis SymBox (Caching)
Bei Problemen nach Updates: Repository entfernen → SymBox neu starten →
Repository erneut hinzufügen.

---

## Tests

Lokaler Harness ohne Symcon (nur `php` nötig) — stubbt die Symcon-API, lädt die
echte Core-Klasse und treibt sie mit realen QRC-Frames:

```bash
php tests/qrc_test.php
```

Geprüft werden Framing/Buffering, Dispatch (Poll → Fan-out, StatusGet, Component.Get),
Change-Normalisierung, ChangeGroup-/AutoPoll-Aufbau und der Forward-Pfad.
Stand: 22 Prüfungen, 0 Fehler.

### Verifikation gegen einen Core
Ohne Hardware genügt der **Q-SYS Designer im Emulate-Modus** (QRC auf
`localhost:1710`): Named Components mit Script Access anlegen, Core-Instanz auf
den Host zeigen — `Status`/`Design` müssen erscheinen; Configurator öffnen →
Komponentenliste; Gain-Instanz anlegen, Fader bewegen und im Designer gegenprüfen;
im Designer einen Wert ändern → Push landet in Symcon.

---

## Autor

**FACE GmbH** — entwickelt für den professionellen Einsatz in Medien- und
Gebäudetechnik.
