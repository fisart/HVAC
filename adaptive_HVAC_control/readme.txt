Modul: Adaptive HVAC Control
Funktionsumfang
Dieses Modul ist die intelligente Steuerung des HVAC-Systems. Mittels eines Q-Learning-Algorithmus (Reinforcement Learning) lernt es, die optimale Leistungs- und Lüfterstufe der Klimaanlage zu finden, um die aktiven Räume möglichst effizient zu kühlen. Dabei werden folgende Faktoren berücksichtigt:
Kühlfortschritt: Wie schnell sinkt die Temperatur in den Räumen?
Energieverbrauch: Eine neue, physikalisch fundierte Belohnungsfunktion modelliert die nicht-lineare Effizienz von Inverter-Kompressoren und die kubische Leistungsaufnahme von Lüftern, um den realen Energieverbrauch besser abzubilden.
Systemschutz: Aktive Vermeidung von Vereisung des Wärmetauschers.
Komfort: Vermeidung von Überkühlung (nur im Standalone-Modus).
Voraussetzungen
IP-Symcon Version 5.0 oder höher
Kompatibilität
Das Modul ist darauf ausgelegt, mit dem Zoning_and_Demand_Manager-Modul zusammenzuarbeiten (Kooperativer Modus). Ein Standalone-Modus ist ebenfalls vorhanden.
Modul-URL
https://github.com/fisart/HVAC/new/main/adaptive_HVAC_control
Einrichtung und Lernprozess
Die Einrichtung erfolgt in zwei Phasen: einer Lernphase und einer stabilen Betriebsphase.
Grundkonfiguration: Verknüpfen Sie alle Kern-Variablen (AC Active Link, Sensoren, etc.).
Aktionsraum definieren: Tragen Sie die empfohlenen Werte für CustomPowerLevels und CustomFanSpeeds ein (siehe Tabelle unten).
Lernphase starten:
Setzen Sie die Hyperparameter auf die empfohlenen Werte für die Lernphase (Alpha: 0.1, Epsilon Decay Rate: 0.001).
Klicken Sie unbedingt auf den Button "Lernen zurücksetzen". Dies löscht die alte Wissensdatenbank und startet den Lernprozess mit den neuen Einstellungen von Grund auf.
Beobachten: Lassen Sie das System für mehrere Tage bis Wochen laufen. Sie können in der Q-Tabellen-Visualisierung zusehen, wie der Agent Wissen sammelt.
Stabile Phase (Optional): Sobald die Regelung zufriedenstellend funktioniert, können die Hyperparameter auf konservativere Werte gesetzt werden (Alpha: 0.01, Decay Rate: 0.005), um das gelernte Verhalten zu stabilisieren.
Wichtige Parameter im Detail
Parameter	Beschreibung	Empfehlung / Hinweis
CustomPowerLevels	Wichtigste Neuerung! Definiert die diskreten Leistungsstufen für den Kompressor. Erlaubt eine an die Hardware angepasste, effizientere Regelung.	30,55,75,100 <br>Diese Werte bilden die realen Betriebszonen eines Inverters ab: <br>- 30: Erhaltungsbetrieb (effizientes Halten der Temp.) <br>- 55: Hoheffizienz-Betrieb (optimaler Dauerlauf) <br>- 75: Leistungsbetrieb (schnelles Kühlen) <br>- 100: Boost-Betrieb (maximale Leistung, ineffizient)
CustomFanSpeeds	Definiert die diskreten Lüfterstufen.	30,60,90 (Beispiel) <br> Reduziert den Aktionsraum und beschleunigt das Lernen. Passen Sie die Werte an die realen Stufen Ihrer Innengeräte an.
Alpha (Lernrate)	Wie stark reagiert der Agent auf neue Informationen?	Lernphase: 0.1 (schnelles Lernen) <br> Stabiler Betrieb: 0.01 (konservativ)
Epsilon Decay Rate	Wie schnell hört der Agent auf, neue Aktionen auszuprobieren ("neugierig" zu sein)?	Lernphase: 0.001 (lange Erkundungsphase) <br> Stabiler Betrieb: 0.005 (schnelle Konvergenz)
MaxPowerDelta / MaxFanDelta	Dämpfungsparameter. Begrenzen die maximale Änderung der Leistung/Lüfterstufe pro Schritt, um die Regelung zu beruhigen und die Hardware zu schonen.	40 (Standard) <br> Dieser Wert ist optimal für die empfohlenen Stufen, da er Sprünge um eine Stufe erlaubt, aber wilde Sprünge über zwei Stufen verhindert.
TimerInterval	Abtastrate des Regelkreises.	120 Sekunden (Standard) <br> Dies ist der optimale Kompromiss zwischen Reaktionsfähigkeit und der Berücksichtigung der thermischen Trägheit eines Raumes. Eine Änderung ist nicht empfohlen.
Verfügbare Aktionen (Buttons)
Lernen zurücksetzen: Löscht die gesamte gelernte Q-Tabelle und setzt die Lernparameter zurück. Muss nach jeder Änderung an den CustomPowerLevels oder CustomFanSpeeds ausgeführt werden, da sich der Aktionsraum ändert.
Visualisierung aktualisieren: Lädt die HTML-Visualisierung der Q-Tabelle manuell neu, ohne auf den nächsten Timer-Durchlauf zu warten. Hilfreich zur Beobachtung des Lernfortschritts.
