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


## Referenz der öffentlichen Methoden

Alle Aufrufe erwarten als erstes Argument die Instanz-ID von Protected Action. Die `PRACT_`-Funktionen sind die von IP-Symcon bereitgestellten Modulaufrufe. PINs immer als **String** übergeben, damit führende Nullen erhalten bleiben.

| Funktion | Parameter nach der Instanz-ID | Rückgabe | Beschreibung |
| --- | --- | --- | --- |
| `PRACT_RegisterAction` | `string $PIN, int $ObjectID, array $Parameters = []` | `bool` | Scriptinhalt samt Parametern verschlüsselt importieren oder ein anderes Objekt als Visualisierungsziel registrieren. Eine bestehende PIN wird überschrieben. |
| `PRACT_Unregister` | `string $PIN` | `bool` | Aktion dieser PIN entfernen; `false`, falls nicht vorhanden oder ungültig. |
| `PRACT_RegisterSuperPIN` | `string $PIN` | `bool` | Super-PIN für die Wiederherstellung registrieren oder ersetzen. |
| `PRACT_InputDigit` | `int $Digit` | `bool` | Ziffer (`0`–`9`) anhängen; `false` bei Sperre, ungültiger Ziffer oder vollem Eingabepuffer. |
| `PRACT_Clear` | keine | `void` | PIN-Eingabe und temporäre Meldung löschen. |
| `PRACT_Enter` | keine | `bool` | PIN bestätigen. `true` bei erfolgreich ausgeführter Script-Aktion oder Entsperrung; Navigation erfordert die eingebaute Kachel. |
| `PRACT_BeginRecovery` | keine | `bool` | Super-PIN-Eingabe starten; nur bei permanenter Sperre und registriertem Super-PIN. |
| `PRACT_CancelRecovery` | keine | `void` | Wiederherstellung abbrechen, ohne zu entsperren. |
| `PRACT_Reset` | keine | `void` | **Alle Aktionen und den Super-PIN unwiderruflich löschen**, Sperren und Zähler zurücksetzen. |
| `PRACT_GetState` | keine | `string` | JSON mit aktuellem Status, Anzahl Aktionen und Fehlversuchen liefern. |

`PRACT_InternalTimer()` ist ein technischer Timer-Callback und **keine Anwenderschnittstelle**. `RequestAction()` und `GetVisualizationTile()` werden von Symcon intern für die Kachel verwendet.

### Eigenes PIN-Tastenfeld bauen

Statt der eingebauten Kachel können eigene Schaltflächen in einer Visualisierung oder externe Tasten verwendet werden. Jede Ziffer ruft `PRACT_InputDigit()` auf, die Löschtaste `PRACT_Clear()` und die Bestätigungstaste `PRACT_Enter()`:

```php
// Script für eigene Bedienelemente; Herkunft von $Taste anpassen.
$InstanzID = 12345;
$Taste = (string) $_IPS['VALUE']; // Beispiel: '0'..'9', 'C', 'E'

if (preg_match('/^[0-9]$/', $Taste) === 1) {
    PRACT_InputDigit($InstanzID, (int) $Taste);
} elseif ($Taste === 'C') {
    PRACT_Clear($InstanzID);
} elseif ($Taste === 'E') {
    PRACT_Enter($InstanzID);
}
```

Das Beispiel setzt voraus, dass das eigene Bedienelement ein Zeichen in `$_IPS['VALUE']` übergibt. Bei anderen Visualisierungen oder Ereignissen muss die Herkunft angepasst werden. Die PIN **nicht** in einer Klartext-Variable zusammensetzen oder protokollieren. Protected Action sammelt die Ziffern intern, löscht die Eingabe nach der Bestätigung und berücksichtigt Eingabe-Timeout sowie Sperrregeln. Eine eigene PIN-Prüfung ist nicht erforderlich.

**Wichtige Einschränkung:** Ein eigenes PHP-Tastenfeld kann verschlüsselte **Script-Aktionen** ausführen, aber kein **Visualisierungsziel** öffnen. Dafür benötigt Protected Action die Client-Zuordnung der eingebauten Kachel. Bei einer gültigen Ziel-PIN liefert `PRACT_Enter()` `false` und den Status `VISUALIZATION_REQUIRED`.

### Status für eigene Visualisierungen

`PRACT_GetState($InstanzID)` liefert JSON mit folgenden Feldern:

| Feld | Bedeutung |
| --- | --- |
| `Message` | Stabiler Meldungscode (siehe oben). |
| `InputActive` | Mindestens eine Ziffer eingegeben; weder Ziffern noch Anzahl werden veröffentlicht. |
| `Locked` | PIN-Eingabe aktuell gesperrt. |
| `LockUntil` | Unix-Zeitstempel für Ende einer temporären Sperre, sonst `0`. |
| `PermanentlyLocked` | Dauerhafte Sperre aktiv. |
| `RecoveryMode` | Super-PIN-Eingabe aktiv. |
| `ServerTime` | Unix-Zeitstempel der Abfrage. |
| `RegisteredActions` | Anzahl registrierter Aktionen. |
| `FailedAttempts` | Fehlversuche bei regulärer PIN-Eingabe. |
| `RecoveryDefined` | Super-PIN registriert. |

Die String-Variable `Status` (Ident `Status`) enthält die ersten sieben Felder, nicht aber die letzten drei administrativen Angaben. Beispiel:

```php
$Status = json_decode(PRACT_GetState(12345), true, 512, JSON_THROW_ON_ERROR);
if ($Status['Locked']) {
    // Eigene Zifferntasten sperren oder eine Meldung anzeigen.
}
```

### Konfigurationsparameter

| Einstellung | Standard | Bedeutung |
| --- | ---: | --- |
| Minimale PIN-Länge | 4 | Mindestlänge einer gültigen PIN. |
| Maximale PIN-Länge | 16 | Höchstlänge einer gültigen PIN. |
| Verzögerung nach Fehlversuchen | 3 | Schwelle für temporäre Sperren. |
| Basisverzögerung | 20 s | Basisdauer einer Sperre. |
| Permanente Sperre nach Fehlversuchen | 5 | Schwelle für dauerhafte Sperre. |
| Eingabe-Timeout | 20 s | Unvollständige Eingabe nach Inaktivität löschen. |
| Wiederherstellungsversuche | 3 | Erlaubte falsche Super-PIN-Eingaben. |
| Anzeigedauer temporärer Meldungen | 3 s | Dauer einer Rückmeldung. |
| Ziffern mischen | aus | Ziffernanordnung in der eingebauten Kachel mischen. |
| Mischintervall bei Inaktivität | 0 s | Periodisches Neumischen (`0` deaktiviert). |

## Sicherheitsgrenzen

Verschlüsselt werden importierter Scripttext, Parameter und Visualisierungsziel-IDs. Externe Symcon-Objekte, Dateien, Logs und APIs sind nicht geschützt. Administratoren mit Zugriff auf IP-Symcon können die PIN-Eingabe manipulieren. Kurze PINs bleiben bei Zugriff auf verschlüsselte Daten grundsätzlich offline angreifbar, auch wenn Argon2id solche Versuche erschwert. Protected Action ist keine vollständige Zutritts- oder Alarmanlage.
