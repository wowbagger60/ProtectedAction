# Protected Action

Protected Action führt eine verschlüsselt hinterlegte Aktion erst nach Eingabe der zugehörigen PIN aus. Möglich sind importierte Symcon-Scripte und Visualisierungsziele.

## Wichtig bei jedem Update

`module.php` und `module.html` fliessen in die Schlüsselableitung ein. **Nach jedem Update müssen alle Aktionen und der Super-PIN neu registriert werden.** Eine Migration ist bewusst ausgeschlossen.

```php
PRACT_Reset(12345);
```

Danach alle Aktionen und den Super-PIN neu registrieren.

## Script-Aktion registrieren

Zuerst ein Symcon-Script erstellen und testen. Dann dessen Inhalt und die Parameter verschlüsselt importieren:

```php
PRACT_RegisterAction(12345, '123456', 67890, [
    'OBJECT_ID' => 54321,
    'HIDDEN' => false
]);
```

Das Quellscript wird beim Registrieren kopiert; spätere Änderungen wirken sich nicht aus. Nach dem Test das Quellscript sowie PIN und Parameter aus dem Registrierungsscript entfernen.

## Visualisierungsziel registrieren

```php
PRACT_RegisterAction(12345, '654321', 54321);
```

Bei einem Objekt, das kein Script ist, wird die Objekt-ID als Visualisierungsziel verschlüsselt gespeichert. Nach korrekter PIN-Eingabe in der Kachel öffnet sich das Ziel nur auf dem betreffenden Visualisierungsclient. Ein Aufruf über `PRACT_Enter()` aus PHP kann nicht navigieren, weil dort kein Client zugeordnet ist (`VISUALIZATION_REQUIRED`). Ein ausgeblendetes Objekt ist kein Ersatz für Zugriffskontrolle.

Eine erneute Registrierung derselben PIN ersetzt die bisherige Aktion. Zum Entfernen:

```php
PRACT_Unregister(12345, '123456');
```

## Super-PIN und Wiederherstellung

```php
PRACT_RegisterSuperPIN(12345, '1234567890');
PRACT_BeginRecovery(12345);
```

Den Super-PIN anschliessend über das Tastenfeld eingeben. Eine erfolgreiche Wiederherstellung setzt die Fehlversuchszähler zurück. Mit `PRACT_CancelRecovery()` wird der Vorgang abgebrochen.

## Kachel und Status

Das PIN-Tastenfeld verwendet immer den verfügbaren **Content-Bereich** der Kachel. Eine zusätzliche Konfigurationsoption gibt es nicht.

Ein eigenes Tastenfeld kann diese API verwenden:

```php
PRACT_InputDigit(12345, 7);
PRACT_Clear(12345);
PRACT_Enter(12345);
```

Die String-Variable `Status` liefert JSON mit maschinenlesbaren Codes: `READY`, `INPUT_ACTIVE`, `PIN_INVALID`, `ACTION_EXECUTED`, `ACTION_FAILED`, `VISUALIZATION_REQUIRED`, `TEMPORARILY_LOCKED`, `PERMANENTLY_LOCKED`, `RECOVERY_ACTIVE`, `SUPER_PIN_INVALID`, `UNLOCKED`.

Die Statusvariable enthält weder PIN noch Scripttext, Parameter oder Ziel-ID. Zustandsabhängige Aktionen (Toggle) müssen im importierten Script implementiert werden.

## Sicherheitsgrenzen

Verschlüsselt werden importierter Scripttext, Parameter und Visualisierungsziel-IDs. Externe Symcon-Objekte, Dateien, Logs und APIs sind nicht geschützt. Administratoren mit Zugriff auf IP-Symcon können die PIN-Eingabe manipulieren. Kurze PINs bleiben bei Zugriff auf verschlüsselte Daten grundsätzlich offline angreifbar, auch wenn Argon2id solche Versuche erschwert. Protected Action ist keine vollständige Zutritts- oder Alarmanlage.
