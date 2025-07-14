# Modul: Adaptive HVAC Control

### Funktionsumfang
Dieses Modul ist die intelligente Steuerung des HVAC-Systems. Mittels eines Q-Learning-Algorithmus (Reinforcement Learning) lernt es, die optimale Leistungs- und Lüfterstufe der Klimaanlage zu finden, um die aktiven Räume möglichst effizient zu kühlen. Dabei werden folgende Faktoren berücksichtigt:
- Fortschritt bei der Kühlung
- Energieverbrauch
- Vermeidung von Vereisung des Wärmetauschers
- Vermeidung von Überkühlung (nur im Standalone-Modus)

### Voraussetzungen
- IP-Symcon Version 5.0 oder höher

### Kompatibilität
Das Modul ist darauf ausgelegt, mit dem `Zoning_and_Demand_Manager`-Modul zusammenzuarbeiten (Kooperativer Modus). Ein Standalone-Modus ist ebenfalls vorhanden.

### Modul-URL
https://github.com/fisart/HVAC/new/main/adaptive_HVAC_control

### Einstellmöglichkeiten & PHP-Befehle
Alle Einstellungen werden direkt im Konfigurationsformular der Instanz vorgenommen. Eine detaillierte Beschreibung aller Parameter befindet sich in der Haupt-Dokumentationsdatei der Bibliothek. Über den "Reset Learning"-Button kann die gelernte Q-Tabelle zurückgesetzt werden, was nach Konfigurationsänderungen empfohlen wird.
