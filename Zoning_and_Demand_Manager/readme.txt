# Modul: Zoning & Demand Manager

### Funktionsumfang
Dieses Modul agiert als Master-Regel-Engine für ein Mehrzonen-HVAC-System. Es steuert, welche Räume wann gekühlt werden dürfen, basierend auf:
- Aktueller und Ziel-Temperatur
- Fenster/Tür-Kontakten (über Links in einer Kategorie)
- Systemweiten Sperren (z.B. wenn die Heizung aktiv ist)
- Einem optionalen Standalone-Modus mit fixen Leistungsstufen

Das Modul steuert direkt die Luftklappen der einzelnen Zonen und signalisiert den Kühlbedarf an das adaptive Modul.

### Voraussetzungen
- IP-Symcon Version 5.0 oder höher

### Kompatibilität
Das Modul arbeitet entweder eigenständig (Standalone) oder in Kooperation mit dem `adaptive_HVAC_control`-Modul. Es steuert beliebige Aktor-Variablen (Boolean/Integer/Float) für Luftklappen und die Hauptanlage.

### Modul-URL
https://github.com/fisart/HVAC/tree/main/Zoning_and_Demand_Manager

### Einstellmöglichkeiten & PHP-Befehle
Alle Einstellungen werden direkt im Konfigurationsformular der Instanz vorgenommen. Eine detaillierte Beschreibung aller Parameter befindet sich in der Haupt-Dokumentationsdatei der Bibliothek. Über den "Run Zoning Check"-Button kann die Logik manuell ausgelöst werden.
