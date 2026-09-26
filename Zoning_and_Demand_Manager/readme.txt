# Modul: Zoning & Demand Manager

### Funktionsumfang
Dieses Modul agiert als Master-Regel-Engine für ein Mehrzonen-HVAC-System. Es steuert, welche Räume wann gekühlt werden dürfen, basierend auf:
- Aktueller und Ziel-Temperatur
- Fenster/Tür-Kontakten (über Links in einer Kategorie)
- Systemweiten Sperren (z.B. wenn die Heizung aktiv ist)
- Einem optionalen Standalone-Modus mit fixen Leistungsstufen

Das Modul steuert direkt die Luftklappen der einzelnen Zonen und signalisiert den Kühlbedarf an das adaptive Modul.

### Winterstatus
Im Feld `Winter status` wird die Boolean-Variable `IS_WINTER` ausgewählt.
Bei `true` lässt dieses Modul die gemeinsam genutzten Geräte vollständig in Ruhe:
Es sendet weder Ein- noch Aus-Befehle an Anlage, Lüfter und Klappen und
verändert keine Raumausgänge. Das gilt auch für Befehle des Orchestrators und
für den separaten Spulentemperatur-Trigger. Der letzte Gerätezustand wird beim
Wechsel in den Winter nicht durch dieses Modul zurückgesetzt. Wenn der Status
wieder `false` wird, läuft unmittelbar eine neue Zonenprüfung.

Eine konfigurierte, aber ungültige Objekt-ID oder eine Variable, die nicht vom
Typ Boolean ist, blockiert die Kühlung ebenfalls. Bei ID `0` bleibt die
bisherige Steuerung zur Kompatibilität mit bestehenden Instanzen aktiv. Die
ID muss daher für den Schutz im Konfigurationsformular eingetragen werden.

### Maximale AC-Leistung bei nur einem Raum (ab Version 1.6.0)
Für jeden Raum kann eine maximale AC-Leistung zwischen 1 und 100 Prozent
eingestellt werden. Die Grenze wird ausschließlich im Standalone-Modus und nur
dann angewendet, wenn genau dieser eine Raum Kühlung anfordert.

Das Modul verwendet immer den kleineren Wert aus der aktuell eingestellten
Standalone-AC-Leistung und der Raumobergrenze. Eine niedrigere Leistung wird
deshalb niemals angehoben. Fordern zwei oder mehr Räume Kühlung an, gilt wieder
die normale Standalone-AC-Leistung ohne raumbezogene Begrenzung. Bei bestehenden
Raumkonfigurationen ohne dieses Feld wird automatisch 100 Prozent verwendet.

Der Diagnosestatus zeigt die angeforderte Leistung, die wirksame Leistung, den
allein aktiven Raum, seine Obergrenze und ob tatsächlich begrenzt wurde. Die
Raumobergrenzen sind als Teil der Raumtabelle automatisch im JSON-Backup enthalten.

### Kühlungsmodus je Raum (ab Version 1.5.0)
Die konfigurierte Modusvariable ist ein dauerhafter Bedienbefehl:

- `1` = Aus: keine Kühlung
- `2` = Einmalige Kühlung: kühlt bis zur Zieltemperatur und setzt den Modus danach auf `1`
- `3` = Automatik: schaltet mit Hysterese ein und bei Erreichen der Zieltemperatur aus; der Modus bleibt `3`

Ein geöffnetes Fenster oder eine geöffnete Tür unterbricht die Kühlung nur
vorübergehend. Der Modus `2` oder `3` bleibt erhalten. Wenn dieselbe Variable
als Moduseingang und Demand-Ausgang eingetragen ist, wird sie nicht mit einem
momentanen Ausgangswert überschrieben. Der tatsächliche Kühlbedarf wird intern
und über die Phaseninformation abgebildet.

### Konfigurationssicherung (ab Version 1.5.0)
Im Aktionsbereich kann die vollständige Modulkonfiguration als JSON exportiert
und wieder importiert werden. Die Sicherung enthält insbesondere alle
Verknüpfungen und die komplette Raumtabelle. Laufzeitzustände, Hysteresemerker
und Diagnosewerte werden nicht gesichert. Nach einem Import sollten die
Objekt-IDs und Raumzuordnungen überprüft werden.

### Diagnosestatus (ab Version 1.4.0)
Unterhalb der Modulinstanz wird die Stringvariable `Zoning decision status`
angelegt. Sie enthält den letzten entscheidungsrelevanten Zustand als gut
lesbares JSON. Erfasst werden unter anderem:

- Gesamtentscheidung und Begründung
- Betriebsart, Master-Sperren und Spulenschutz
- Ist-/Solltemperatur und Temperaturdifferenz je Raum
- Raumbetriebsart, stabiler Fensterstatus und Hysteresezustand
- Kühlbedarf, Klappenbefehl, Demand- und Phasenausgabe je Raum
- Systembefehl sowie aggregierte Werte für die adaptive Regelung

Die Variable wird nur aktualisiert, wenn sich der Zustand oder eine Entscheidung
ändert. Der Zeitstempel `lastChanged` zeigt den Zeitpunkt dieser Änderung. Das
vermeidet unnötige Variablen- und Archivschreibvorgänge. Für eine Fehleranalyse
kann der komplette Inhalt der Variable kopiert und weitergegeben werden.

### Voraussetzungen
- IP-Symcon Version 5.0 oder höher

### Kompatibilität
Das Modul arbeitet entweder eigenständig (Standalone) oder in Kooperation mit dem `adaptive_HVAC_control`-Modul. Es steuert beliebige Aktor-Variablen (Boolean/Integer/Float) für Luftklappen und die Hauptanlage.

### Modul-URL
https://github.com/fisart/HVAC/tree/main/Zoning_and_Demand_Manager

### Einstellmöglichkeiten & PHP-Befehle
Alle Einstellungen werden direkt im Konfigurationsformular der Instanz vorgenommen. Eine detaillierte Beschreibung aller Parameter befindet sich in der Haupt-Dokumentationsdatei der Bibliothek. Über den "Run Zoning Check"-Button kann die Logik manuell ausgelöst werden.
