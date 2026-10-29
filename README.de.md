# ProtectedAction

ProtectedAction führt hinterlegte Aktionen nach Eingabe einer PIN oder eines Musters aus. Es kann Symcon-Scripte ausführen oder zu einem Ziel in der Visualisierung navigieren. Die hinterlegten Aktionen werden verschlüsselt gespeichert. Ein Anwendungsfall ist die geschützte Freigabe des Bearbeitungsmodus.

## Einrichtung

### 1. Instanz konfigurieren

ProtectedAction aus dem Module Store installieren und eine Instanz anlegen. Vor dem Registrieren der ersten Aktion zwei Einstellungen wählen und übernehmen:

- **Eingabemodus:** PIN für das Zahlenpad oder Muster für die Zeichenfläche. Eine Instanz verwendet einen dieser beiden Modi. Für mehrere Aktionen lassen sich verschiedene PINs beziehungsweise Muster hinterlegen.
- **Codebindung:** Die Option **Bei Änderungen am Modulcode Neuregistrierung verlangen** ist standardmässig eingeschaltet. Sie bietet zusätzlichen Schutz: Wird der Modulcode geändert, können die bisherigen Aktionen und der Super-PIN nicht mehr entschlüsselt werden. Nach einem Modulupdate mit solchen Änderungen müssen sie deshalb zurückgesetzt und neu registriert werden. Ohne Codebindung bleiben sie bei kompatiblen Modulupdates erhalten. Ein Update von Symcon selbst ist damit nicht gemeint. Hat sich der Modulcode geändert, zeigt die Instanz **Neuregistrierung erforderlich** an, und eine korrekte Eingabe zählt nicht als Fehlversuch.

**Nach der ersten Aktion oder Super-PIN-Registrierung sind beide Einstellungen gesperrt.** Zum Ändern die Instanz mit `PRACT_Reset()` zurücksetzen, die neuen Einstellungen übernehmen und alle Aktionen sowie den Super-PIN neu registrieren. Reset löscht die vorhandenen Registrierungen.

### 2. Aktionsscript erstellen

In Symcon ein Script mit der gewünschten Aktion erstellen und testen. Beispielsweise kann es den Bearbeitungsmodus der Visualisierung umschalten oder eine Beleuchtung einschalten.

Anschliessend dieses Script einer PIN oder einem Muster zuordnen. Am einfachsten geht das in der Instanzkonfiguration: Unter **Aktion registrieren** den Code zweimal eingeben, das Script oder Navigationsziel wählen, bei Bedarf Scriptparameter als JSON-Objekt wie `{"Szene": 2}` angeben und **Aktion registrieren** wählen. Der Code wird weder in der Konfiguration noch in einem Script gespeichert. **Super-PIN** und **Zurücksetzen** funktionieren genauso.

Alternativ ist ein separates Registrierungsscript möglich. In den folgenden Beispielen ist `12345` die ProtectedAction-Instanz und `67890` das zuvor getestete Aktionsscript. Die IDs durch die eigenen Werte ersetzen.

#### Aktion mit PIN registrieren

Wenn die Instanz auf **PIN** eingestellt ist:

```php
var_dump(PRACT_RegisterAction(12345, '482619', 67890, []));
```

Eine PIN muss 4 bis 16 Ziffern lang sein.

Nach Eingabe der PIN `482619` wird die Aktion ausgeführt. `true` bestätigt die erfolgreiche Registrierung; bei `false` die Einstellungen, den Zugangscode und die Script-ID prüfen.

#### Aktion mit Muster registrieren

Wenn die Instanz auf **Muster** eingestellt ist, dieselbe Methode verwenden und die besuchten Punkte als Zeichenfolge angeben:

```php
var_dump(PRACT_RegisterAction(12345, '13971', 67890, []));
```

Die Punkte sind von links oben nach rechts unten nummeriert:

| Links | Mitte | Rechts |
| --- | --- | --- |
| 1 | 2 | 3 |
| 4 | 5 | 6 |
| 7 | 8 | 9 |

`13971` beschreibt ein geschlossenes Quadrat. Falls eine gerade Verbindung über weitere Punkte führt, ergänzt ProtectedAction diese automatisch. So wird bei `13` auch die `2` berücksichtigt. Ein Punkt darf später erneut besucht werden, aber nicht zweimal direkt hintereinander. Die `0` ist im Muster nicht zulässig.

Ein Muster besteht aus 2 bis 16 Punktbesuchen, einschliesslich automatisch ergänzter Zwischenpunkte.

#### Was wird gespeichert?

Bei der Registrierung kopiert ProtectedAction den vollständigen Inhalt des Aktionsscripts und speichert ihn verschlüsselt. Das ursprüngliche Script wird danach nicht mehr benötigt und kann nach einem erfolgreichen Test gelöscht werden, wenn sein Inhalt nicht offen im System verbleiben soll. ProtectedAction löscht es nicht automatisch.

Spätere Änderungen am ursprünglichen Script wirken sich nicht auf die registrierte Aktion aus. Zum Übernehmen einer Änderung die Aktion erneut registrieren. Derselbe Zugangscode ersetzt die bisherige Aktion.

Das letzte Argument `[]` ist für Scriptparameter vorgesehen. Falls das Script Parameter benötigt, dort ein Array übergeben; die Werte stehen im ausgeführten Script über `$_IPS` zur Verfügung. Ohne Parameter immer `[]` angeben.

Zusätzlich erhält das ausgeführte Script immer `$_IPS['SENDER'] = 'ProtectedAction'` sowie die ID der ProtectedAction-Instanz in `$_IPS['INSTANCE']` und `$_IPS['SELF']`. Diese Schlüssel haben Vorrang vor gleichnamigen registrierten Parametern.

#### Scriptausgabe anzeigen

Mit **Scriptausgabe in der Kachel anzeigen** oder `PRACT_RegisterActionEx()` mit `$ShowOutput = true` wartet ProtectedAction, bis das Script beendet ist, und zeigt dessen Ausgabe (beispielsweise von `echo`) einige Sekunden in der Kachel an. Die Ausgabe ist auf 200 Zeichen begrenzt und erscheint nur auf dem Client, auf dem der Code eingegeben wurde. Technisch wird sie wie ein Navigationsziel an alle verbundenen Clients übertragen. Solange ein solches Script läuft, nimmt die Instanz keine weitere Eingabe an; es sollte deshalb kurz sein. Ohne diese Option laufen Scripte wie bisher im Hintergrund.

**PIN beziehungsweise Muster und vertrauliche Parameter nach dem Test auch aus dem Registrierungsscript entfernen.**

### 3. Alternativ ein Navigationsziel registrieren

Statt einer Script-ID die Objekt-ID des gewünschten Visualisierungsziels übergeben, beispielsweise eine Kategorie:

```php
var_dump(PRACT_RegisterAction(12345, '739184', 54321, []));
```

Dieses Beispiel verwendet die PIN `739184`. Im Mustermodus stattdessen eine gültige Punktfolge wie `'159'` verwenden. `54321` ist die Ziel-ID. Das Parameterarray bleibt bei Navigationszielen leer.

ProtectedAction erkennt anhand des Objekts, ob es ein Script oder ein Navigationsziel ist. Nach korrekter Eingabe öffnet sich das Ziel nur auf dem Client, auf dem der Zugangscode eingegeben wurde.

Ein Navigationsziel darf auch ausgeblendet (hidden) sein. Es ist also möglich, zu Kategorien zu navigieren, die normalerweise in der Visualisierung nicht sichtbar sind.

### 4. Super-PIN einrichten

Zum späteren Entsperren einen eigenen Super-PIN registrieren:

```php
var_dump(PRACT_RegisterSuperPIN(12345, '8073519264'));
```

Der Super-PIN ist immer numerisch, auch im Mustermodus. Einen ausreichend langen, separaten Code wählen und sicher aufbewahren. Anschliessend auch ihn aus dem Registrierungsscript entfernen. Die Registrierung allein startet noch keine Wiederherstellung.

Der Super-PIN muss 4 bis 16 Ziffern lang sein. **Er kann nur einmal registriert werden.** Sonst könnte jeder mit Zugriff auf das System eine dauerhafte Sperre aufheben, indem er einfach einen neuen Super-PIN definiert. Wer den Super-PIN vergisst, setzt die Instanz mit `PRACT_Reset()` zurück und registriert alle Aktionen neu.

## Funktion

Die ProtectedAction-Instanz in der Visualisierung als Kachel verwenden. Je nach Eingabemodus erscheint ein Zahlenpad oder eine quadratische Zeichenfläche.

### Eingabe und Ausführung

- **PIN:** Ziffern antippen oder anklicken. **C** löscht die bisherige Eingabe, **E** schickt sie ab. Auf Wunsch werden die Ziffernpositionen gemischt.
- **Muster:** Finger oder Stift aufsetzen beziehungsweise die linke Maustaste gedrückt halten und die Punkte in der registrierten Reihenfolge abfahren. Abheben oder Loslassen schickt das Muster ab. Eine verblassende Spur unterstützt die Orientierung.

Bei einem passenden Zugangscode entschlüsselt ProtectedAction die hinterlegte Aktion. Der gespeicherte Scriptinhalt wird ausgeführt oder das registrierte Navigationsziel geöffnet. Die Kachel, auf der der Code eingegeben wurde, zeigt das Ergebnis an. Andere Kacheln zeigen nur gemeinsame Zustände wie Sperren.

Eine unvollständige Eingabe wird nach dem konfigurierten Eingabe-Timeout verworfen. Ein leeres **E**, ein einzelner Tipp auf die Zeichenfläche und eine abgebrochene Zeichengeste zählen nicht als Fehlversuch.

### Fehlversuche und Entsperren

Falsche Zugangscodes zählen als Fehlversuche. Zunächst wird die Eingabe vorübergehend gesperrt. Nach der konfigurierten Anzahl aufeinanderfolgender Fehlversuche bleibt ProtectedAction dauerhaft gesperrt. Eine erfolgreiche reguläre Eingabe setzt den Fehlversuchszähler zurück.

Zum Entsperren aus einem Script den Super-PIN übergeben:

```php
var_dump(PRACT_Unlock(12345, '8073519264'));
```

Ein vorheriges `PRACT_BeginRecovery()` ist dafür nicht nötig. Bei korrektem Super-PIN wird die Instanz entsperrt und die Fehlversuchszähler werden zurückgesetzt. Auch falsche Super-PINs sind auf eine konfigurierbare Anzahl Versuche begrenzt. Die interaktive Wiederherstellung über das Zahlenpad ist im API-Abschnitt beschrieben.

### Weitere Einstellungen

| Einstellung | Standard | Wirkung |
| --- | --- | --- |
| Verzögerung nach Fehlversuchen | 3 | Ab diesem Fehlversuch vorübergehend sperren. |
| Basisverzögerung | 20 Sekunden | Beim ersten verzögerten Versuch 20 Sekunden sperren, beim nächsten 40 Sekunden usw. |
| Permanente Sperre nach Fehlversuchen | 5 | Ab diesem Fehlversuch dauerhaft sperren. |
| Eingabe-Timeout | 20 Sekunden | Unvollständige Eingabe nach Inaktivität verwerfen. |
| Wiederherstellungsversuche | 3 | Erlaubte falsche Super-PIN-Eingaben pro Recovery-Runde. |
| Anzeigedauer temporärer Meldungen | 3 Sekunden | Wie lange eine Rückmeldung sichtbar bleibt. |
| Ziffern mischen | Aus | Ziffernpositionen im Zahlenpad mischen. Im Mustermodus gilt das nur für das Zahlenpad der Super-PIN-Eingabe. |
| Mischintervall bei Inaktivität | 0 Sekunden | Zahlenpad regelmässig neu mischen; `0` deaktiviert das Intervall. |

### Schutzumfang

ProtectedAction schützt die hinterlegten Aktionen durch Verschlüsselung, Fehlversuchssperren und optional die Codebindung. Es ist als Komfortschutz gedacht und ersetzt nicht die Zugriffskontrolle von Symcon. Wer das Symcon-System administrieren kann, kann auch die Eingabe verändern. Andere Objekte und Dateien im System werden durch das Modul nicht geschützt. Insbesondere bleibt ein ursprüngliches Aktionsscript lesbar, solange es nicht gelöscht wurde.

## API

Alle Aufrufe erwarten als erstes Argument die Instanz-ID von ProtectedAction. PINs und Muster immer als **String** übergeben, damit führende Nullen erhalten bleiben. Die folgenden Tabellen nennen nur die weiteren Parameter.

### Aktionen und Super-PIN verwalten

| Methode | Weitere Parameter | Rückgabe | Beschreibung |
| --- | --- | --- | --- |
| `PRACT_RegisterAction` | `string $PIN, int $ObjectID, array $Parameters` | `bool` | Aktionsscript samt Parametern importieren oder ein Navigationsziel registrieren. `$PIN` enthält je nach Instanzmodus die PIN oder das Muster. Für unbenutzte Parameter `[]` übergeben. Derselbe Zugangscode ersetzt die bisherige Aktion. |
| `PRACT_RegisterActionEx` | `string $PIN, int $ObjectID, array $Parameters, bool $ShowOutput` | `bool` | Wie `PRACT_RegisterAction`, optional mit Anzeige der Scriptausgabe in der Kachel. `$ShowOutput` ist nur bei Scripten erlaubt. |
| `PRACT_Unregister` | `string $PIN` | `bool` | Aktion der PIN beziehungsweise des Musters entfernen. `false`, wenn der Code ungültig oder nicht registriert ist. |
| `PRACT_RegisterSuperPIN` | `string $PIN` | `bool` | Numerischen Super-PIN einmalig registrieren. `false`, wenn bereits ein Super-PIN existiert; ein neuer erfordert einen Reset. |
| `PRACT_Reset` | Keine | `void` | Alle Aktionen und den Super-PIN löschen, Sperren und Zähler zurücksetzen sowie Eingabemodus und Codebindung zur Änderung freigeben. |

### Entsperren und interaktive Recovery

| Methode | Weitere Parameter | Rückgabe | Beschreibung |
| --- | --- | --- | --- |
| `PRACT_Unlock` | `string $SuperPIN` | `bool` | Dauerhaft gesperrte Instanz direkt entsperren. Kein vorheriges `BeginRecovery()` nötig. `true` nur bei erfolgreicher Entsperrung. |
| `PRACT_BeginRecovery` | Keine | `bool` | Neue Recovery-Runde starten und das Zahlenpad für den Super-PIN freigeben. Nur bei permanenter Sperre und registriertem Super-PIN möglich. |
| `PRACT_CancelRecovery` | Keine | `void` | Interaktive Recovery beenden, ohne zu entsperren. Bisherige Recovery-Fehlversuche bleiben erhalten. |

Für die interaktive Wiederherstellung `PRACT_BeginRecovery(12345)` aufrufen, danach den Super-PIN im Zahlenpad eingeben und mit **E** bestätigen.

`Unlock()` und die interaktive Eingabe verwenden denselben Recovery-Zähler. Ist die Zahl der Wiederherstellungsversuche ausgeschöpft, wird auch ein korrekter Super-PIN abgelehnt. Der Administrator kann mit `PRACT_BeginRecovery()` ausdrücklich eine neue Recovery-Runde starten. Wiederholte `Unlock()`-Aufrufe setzen den Zähler nicht zurück.

Ist die Instanz nicht dauerhaft gesperrt oder kein Super-PIN registriert, liefert `Unlock()` `false`, ohne einen Versuch zu zählen.

### Eigene Bedienelemente und Status

| Methode | Weitere Parameter | Rückgabe | Beschreibung |
| --- | --- | --- | --- |
| `PRACT_InputDigit` | `int $Digit` | `bool` | Eine Ziffer anhängen. Im Mustermodus Punkte `1` bis `9`; keine direkten Wiederholungen. In der Super-PIN-Eingabe Ziffern `0` bis `9`. `false` bei gesperrter, ungültiger oder zu langer Eingabe. |
| `PRACT_Clear` | Keine | `void` | Laufende Eingabe und temporäre Meldung löschen. |
| `PRACT_Enter` | Keine | `bool` | Eingabe bestätigen. `true` bei erfolgreicher Scriptaktion oder Entsperrung. |
| `PRACT_GetState` | Keine | `string` | JSON mit Status, Anzahl Aktionen und weiteren Instanzangaben zurückgeben. |

Eigene Tasten können Ziffern mit `PRACT_InputDigit()` übergeben, mit `PRACT_Clear()` löschen und mit `PRACT_Enter()` bestätigen. Die Sperrregeln gelten auch für diese Eingaben.

**Navigationsziele benötigen die eingebaute Kachel.** Ein PHP-Aufruf von `PRACT_Enter()` kann keinem Visualisierungsclient zugeordnet werden. Ein gültiger Zugangscode für ein Navigationsziel liefert dann `false` mit der Meldung `VISUALIZATION_REQUIRED`.

Die Variable **Status** enthält JSON mit folgenden Feldern:

| Feld | Bedeutung |
| --- | --- |
| `Message` | Meldungscode, siehe unten. |
| `InputActive` | Eine Eingabe über `PRACT_InputDigit()` liegt im serverseitigen Puffer. Eingaben in der Kachel bleiben bis zum Absenden auf dem Client. |
| `Locked` | Eingabe aktuell gesperrt. |
| `LockUntil` | Ende einer vorübergehenden Sperre als Unix-Zeitstempel, sonst `0`. |
| `PermanentlyLocked` | Dauerhafte Sperre aktiv. |
| `RecoveryMode` | Interaktive Super-PIN-Eingabe aktiv. |
| `ServerTime` | Aktuelle Serverzeit als Unix-Zeitstempel. |

`PRACT_GetState()` liefert zusätzlich:

| Feld | Bedeutung |
| --- | --- |
| `RegisteredActions` | Anzahl registrierter Aktionen. |
| `FailedAttempts` | Anzahl regulärer Fehlversuche. |
| `RecoveryDefined` | Ein Super-PIN ist registriert. |
| `InputMode` | `0` für PIN, `1` für Muster. |
| `InputModeLocked` | Eingabemodus kann nur nach Reset geändert werden. |
| `CodeBound` | Codebindung eingeschaltet. |
| `CodeBindingLocked` | Codebindung kann nur nach Reset geändert werden. |
| `ReregistrationRequired` | Der Modulcode wurde geändert. Zurücksetzen und neu registrieren. |

Die Meldungscodes bleiben unabhängig von der Anzeigesprache gleich:

| Code | Bedeutung |
| --- | --- |
| `READY` | Bereit zur Eingabe. |
| `INPUT_ACTIVE` | Eingabe liegt vor. |
| `PIN_INVALID` | PIN oder Muster ungültig. |
| `ACTION_EXECUTED` | Aktion erfolgreich ausgeführt. |
| `ACTION_FAILED` | Zugangscode erkannt, Aktion jedoch fehlgeschlagen. |
| `TEMPORARILY_LOCKED` | Vorübergehend gesperrt. |
| `PERMANENTLY_LOCKED` | Dauerhaft gesperrt. |
| `RECOVERY_ACTIVE` | Super-PIN-Eingabe freigegeben. |
| `SUPER_PIN_INVALID` | Super-PIN ungültig. |
| `UNLOCKED` | Erfolgreich entsperrt. |
| `VISUALIZATION_REQUIRED` | Für dieses Navigationsziel die eingebaute Kachel verwenden. |
| `REREGISTRATION_REQUIRED` | Der Modulcode wurde geändert. Zurücksetzen und neu registrieren. |

Die Statusausgaben enthalten keine Zugangscodes, Scriptinhalte, Parameter oder Ziel-IDs. Ergebnismeldungen zu Eingaben in der Kachel gehen nur an die Kachel, auf der der Code eingegeben wurde; die Variable `Status` und `PRACT_GetState()` enthalten sie weiterhin für eigene Bedienelemente. Bei erfolgreicher Navigation wird die Ziel-ID über den Visualisierungskanal übertragen und ist dabei technisch auch für andere verbundene Clients sichtbar.

`PRACT_InternalTimer()` ist ein interner Timeraufruf. Die Methoden `PRACT_UI…()` dienen dem Konfigurationsformular. `RequestAction()`, `GetConfigurationForm()` und `GetVisualizationTile()` werden vom Symcon-System verwendet.
