# Protected Action

Protected Action führt eine verschlüsselt hinterlegte Aktion erst nach Eingabe des zugehörigen PINs oder Musters aus. Möglich sind importierte Symcon-Scripte und Visualisierungsziele.

## Neu in 1.1 Build 1: Mustereingabe

Vor der Registrierung in der Instanz **Input mode → Pattern** wählen und die Änderungen übernehmen. Der Standard bleibt PIN. Der Eingabemodus ist ab der ersten registrierten Aktion **oder dem ersten Super-PIN** gesperrt. Auch das Entfernen der letzten Aktion hebt die Sperre nicht auf; ein Wechsel erfordert `PRACT_Reset()` und Neuregistrierung. Eine Änderung per API wird ebenfalls geprüft: Bei einem unzulässigen Wechsel blockiert das Modul Eingaben und neue Registrierungen. Dann den ursprünglichen Modus wiederherstellen oder ausdrücklich zurücksetzen.

Die Punktnummerierung läuft zeilenweise von links oben nach rechts unten:

| Links | Mitte | Rechts |
| --- | --- | --- |
| 1 | 2 | 3 |
| 4 | 5 | 6 |
| 7 | 8 | 9 |

Die bestehende API bleibt unverändert. Beispiel für ein geschlossenes Quadrat:

```php
var_dump(PRACT_RegisterAction(12345, '13971', 67890, []));
```

`67890` kann ein getestetes Script oder ein Visualisierungsziel sein. Für ein Ziel muss das Parameterarray leer sein. Der Zugangscode bleibt ein String; danach aus dem Registrierungsscript entfernen.

- Nur Punkte `1` bis `9`, keine `0`, Sterne oder direkt aufeinanderfolgenden Wiederholungen.
- Ein Punkt darf nach anderen Punkten erneut besucht werden, etwa `131`.
- Gerade Zwischenpunkte werden bei Registrierung und Prüfung serverseitig ergänzt: `13` wird `123`, `19` wird `159`, `13971` wird `123698741`. Für `16` und `15` wird nichts ergänzt. Die Ergänzung erfolgt auch bei zuvor besuchten Zwischenpunkten; dies unterscheidet sich vom Android-Muster.
- Die Längengrenzen gelten für die **ergänzte Punktfolge** (Muster mindestens 2, höchstens 16; Standard 4–16). Das Quadrat zählt daher neun Punktbesuche. Unterschiedlich geschriebene, aber identisch ergänzte Muster bezeichnen dieselbe Aktion.
- Maus: linke Taste gedrückt halten und zeichnen. Touch/Stift: aufsetzen und zeichnen. Loslassen prüft die vollständige Geste ohne E-Taste.
- Feste Trefferflächen, keine tolerante Erkennung. Zusätzliche erfasste Punkte machen die Eingabe ungültig.
- Die Punkte bleiben unverändert; keine Hervorhebung und keine sichtbare Punktfolge. Die Bewegungsspur verblasst nach etwa 650 ms und verschwindet beim Loslassen vollständig.
- Abgebrochene oder leere Gesten zählen nicht als Fehlversuch. Ein abgeschlossenes falsches oder zu langes Muster zählt genau einmal. Sperren gelten auch für Muster.
- Die Musterfolge bleibt während des Zeichnens lokal und wird erst beim Loslassen vollständig übermittelt. `InputActive` in der Server-Statusvariable erfasst daher keine laufende lokale Zeichengeste. Inaktivität über das konfigurierte Eingabe-Timeout verwirft die Geste.
- Recovery verwendet immer das Zahlenpad und den numerischen Super-PIN. Dieser hat eigene Längengrenzen (Standard 4–16), unabhängig von den Mustergrenzen. Die bestehende Scrambling-Einstellung gilt für das Recovery-Zahlenpad ebenfalls.

Es gibt keine zusätzliche öffentliche Registrierungsmethode. `PRACT_Unregister()` akzeptiert dieselbe Musterfolge wie `RegisterAction()`. Über `PRACT_InputDigit()` können im Mustermodus Punkte `1`–`9` eingegeben und mit `PRACT_Enter()` bestätigt werden; `0` und direkte Wiederholungen werden abgelehnt. Clientlokale Navigation bleibt auf die eingebaute Kachel beschränkt.

**Updatehinweis:** Dieser Build ändert PHP, Kachel und Verschlüsselungsformat. Alle bisherigen Aktionen und der Super-PIN müssen zurückgesetzt und neu registriert werden. Die geplante updatefeste Konfiguration ist noch nicht enthalten. PIN, Muster und Recovery werden bei der Schlüsselableitung getrennt behandelt.

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
PRACT_RegisterAction(12345, '654321', 54321, []);
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

Das PIN-Tastenfeld beziehungsweise Musterfeld verwendet immer den verfügbaren **Content-Bereich** der Kachel. Eine zusätzliche Konfigurationsoption gibt es nicht.

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
| `InputMode` | `0` = PIN, `1` = Muster. |
| `InputModeLocked` | Eingabemodus nur nach Reset änderbar. |

Die String-Variable `Status` (Ident `Status`) enthält die ersten sieben Felder, nicht aber die letzten fünf administrativen Angaben. Beispiel:

```php
$Status = json_decode(PRACT_GetState(12345), true, 512, JSON_THROW_ON_ERROR);
if ($Status['Locked']) {
    // Eigene Zifferntasten sperren oder eine Meldung anzeigen.
}
```

### Konfigurationsparameter

| Einstellung | Standard | Bedeutung |
| --- | ---: | --- |
| Eingabemodus | PIN (0) | PIN oder Muster (1); nach erster Registrierung gesperrt. |
| Minimale Zugangscode-Länge | 4 | PIN mindestens 4; Muster mindestens 2, nach Ergänzung der Zwischenpunkte. |
| Maximale Zugangscode-Länge | 16 | Höchstlänge; beim Muster einschliesslich ergänzter Zwischenpunkte. |
| Minimale Super-PIN-Länge | 4 | Numerischer Recovery-Zugang, unabhängig vom Eingabemodus. |
| Maximale Super-PIN-Länge | 16 | Eigene Höchstlänge des Super-PINs. |
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
