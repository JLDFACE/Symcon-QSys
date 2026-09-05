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
Quellenumschaltung als Integer-Variable mit sprechenden Namen. Q-SYS kennt dafür
**zwei** Bausteine, das Modul beherrscht beide — umschaltbar über **Betriebsart**:

- **Router** (`router_with_output`): ein Auswahl-Control je Ausgang, z. B.
  `select.1`. Der Wert ist die Eingangsnummer und wird direkt gelesen/geschrieben.
  Die Quellennamen werden in der Liste gepflegt.
- **Selector** (`selector`): der aktive Eintrag steckt im Control `selector` als
  JSON, die komplette Optionsliste in dessen `Choices`. Das Modul **abonniert nur
  dieses eine Control** und baut die Quellennamen daraus selbst auf — im Design
  gepflegte Bezeichnungen erscheinen also automatisch in Symcon. Geschrieben wird
  auf `selector.<Index>`; das ist am Core exklusiv, ein Schreibvorgang genügt.

Nach außen ist die Variable in beiden Fällen **1-basiert** (Selector-Index 0 =
Quelle 1) — dieselbe Zuordnung, die Q-SYS zwischen `label.0` und Router-Eingang 1
verwendet. Der Configurator erkennt beide Komponententypen und legt die Instanz
mit der passenden Betriebsart an.

### QSys EQ
Zeichnet den Frequenzgang einer `equalizer_parametric`-Komponente als **Kurve**
(SVG in einer `~HTMLBox`-Variablen) und macht **Frequenz, Gain, Q und Bypass** bedienbar —
jeweils für das über `Band` gewählte Band. Damit kommt die Visu mit fünf
Bedienelementen aus statt mit einem Satz pro Band; die Kurve hebt das gewählte
Band hervor, und die Bandauswahl zeigt die Frequenz im Klartext („4 · 690 Hz“) —
auch nachdem man sie verstellt hat. Dazu Master-Gain, Bypass und Mute der ganzen Komponente.

Die Bandzahl wird automatisch erkannt. `q.factor` wird direkt gelesen und
geschrieben — der Core rechnet die Bandbreite selbst nach. Die Kurve ist
**berechnet**, nicht gemessen: sie zeigt die eingestellten Filterparameter, nicht
das reale Signal. Gezeichnet wird der **analoge Idealverlauf** — derselbe Bezug,
den auch der Q-SYS Designer verwendet. Ein digitaler Biquad bei 48 kHz weicht
nahe Nyquist deutlich ab: ein Band bei 15,6 kHz mit Q 1,43 und +9 dB liegt dort
bei 19 kHz nur noch bei +2,3 statt +6,7 dB.

**IPSView:** dort werden HTMLBox-Variablen nicht dargestellt. Das Modul liefert
die Kurve deshalb zusätzlich unter `/hook/qsys_eq<InstanceID>` als eigenständige
Seite aus — in IPSView ein **WebView-Element** auf
`http://<SymBox>:3777/hook/qsys_eq<InstanceID>` zeigen lassen. Die fertige
Adresse steht im Konfigurationsformular der Instanz. Die Seite lädt alle 3 s nur
das SVG nach, damit die Kurve mitläuft ohne zu flackern.

### QSys Snapshot
Snapshot-Bank laden/speichern (`Snapshot.Load`/`Save`, Bank + Nummer + Ramp).

### QSys Trigger
Momentane Tasten (Page, Bell, Mute-All …): ein Klick sendet `Value = 1`.

### QSys Configurator
Liest das **laufende Design** live aus (`Component.GetComponents` /
`Component.GetControls`) und legt per Klick passende Instanzen an — **Gain**-,
**Router**-, **Selector**- und **EQ**-Komponenten werden erkannt und passend
vorbelegt (beim Selector mit den Quellennamen aus den `label.N`-Controls, beim
EQ mit der Bandzahl), jedes einzelne Control ist als generisches *QSys Control*
anlegbar. Der Configurator wird **unter einen QSys Core** gehängt
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
   Control-/Gain-/Router-/EQ-/Snapshot-/Trigger-Instanzen von Hand anlegen

### Hinweis SymBox (Caching)
Bei Problemen nach Updates: Repository entfernen → SymBox neu starten →
Repository erneut hinzufügen.

---

## Tests

### Offline (ohne Symcon, ohne Hardware)
Stubbt die Symcon-API, laedt die echte Core-Klasse und treibt sie mit realen
QRC-Frames:

```bash
php tests/qrc_test.php
```

Geprueft werden Framing/Buffering, Dispatch (Poll -> Fan-out, StatusGet,
Component.Get), Change-Normalisierung, ChangeGroup-/AutoPoll-Aufbau und der
Forward-Pfad, dazu beide Router-Betriebsarten (Router und Selector inkl.
Aufbau der Quellenliste aus den Choices) und die Filtermathematik des EQ
(Peaking/Shelf-Biquads, Summenkurve, Grenzfaelle). Stand: **62 Pruefungen, 0 Fehler**.

### Gegen einen echten Core -- lesend
```bash
php tests/live_core_test.php [host] [port]     # Default: core-demo 1710
```

Faehrt die echte Core-Klasse an einem echten Socket: Begruessung
(`EngineStatus`), `StatusGet`, Abo -> ChangeGroup -> Push-Fan-out, Erst-Sync und
das **NoOp-Keepalive ueber 65 s** (der Core trennt sonst nach 60 s). Schreibt
nichts. Laeuft rund 80 s. Stand: **12 Pruefungen, 0 Fehler**.

### Gegen einen echten Core -- schreibend
```bash
php tests/live_write_test.php [host] [port]
```

Treibt die echten Kindmodule ueber den echten Core: `Component.Set` mit `Value`,
`Position` und `Ramp`, Mute, der generische Control-Pfad, die Quellenumschaltung
in **beiden** Betriebsarten und ein Trigger.
**Veraendert Werte am Core** -- liest dafuer alle Ausgangswerte
vorher ein und stellt sie am Ende wieder her, auch bei Abbruch. Snapshots bleiben
unangetastet. Die Komponentennamen im Skript stammen aus dem Testdesign und
muessen fuer ein anderes Design angepasst werden. Stand: **14 Pruefungen, 0 Fehler**.

Verifiziert gegen einen **Core 24f**, Design `SKUZ_FACE_190826`, 142 Named
Components / 4259 Controls.

## Autor

**FACE GmbH** — entwickelt für den professionellen Einsatz in Medien- und
Gebäudetechnik.
