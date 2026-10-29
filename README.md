# ProtectedAction

ProtectedAction runs stored actions after a PIN or pattern has been entered. It can execute Symcon scripts or open a destination in the visualization. Stored actions are encrypted. One use case is protecting access to the visualization's editing controls.

## Setup

### 1. Configure the instance

Install ProtectedAction from the Module Store and create an instance. Choose and apply these two settings before registering the first action:

- **Input mode:** PIN for the numeric keypad or Pattern for the drawing pad. Each instance uses one of these modes. You can register different PINs or patterns for different actions.
- **Code binding:** **Require re-registration after module code changes** is enabled by default. It provides additional protection: if the module code changes, existing actions and the Super PIN can no longer be decrypted. After a module update that changes the code, reset and re-register them. With code binding disabled, registrations survive compatible module updates. An update to Symcon itself is not what this setting refers to. When the module code has changed, the instance shows **Re-registration required**, and correct input does not count as a failed attempt.

**Both settings are locked after the first action or Super PIN registration.** To change them, reset the instance with `PRACT_Reset()`, apply the new settings and register all actions and the Super PIN again. Reset deletes the existing registrations.

### 2. Create an action script

Create and test a normal Symcon script containing the desired action. For example, it could toggle the visualization's editing controls or switch on a light.

Then assign that script to a PIN or pattern. The easiest way is the instance configuration: under **Register action**, enter the code twice, choose the script or navigation target, optionally enter script parameters as a JSON object such as `{"Scene": 2}`, and select **Register action**. The code is not stored in the configuration or in any script. **Super PIN** and **Reset** work the same way.

Alternatively, use a separate registration script. In the examples below, `12345` is the ProtectedAction instance and `67890` is the tested action script. Replace these IDs with your own.

#### Register an action with a PIN

For an instance configured for **PIN**:

```php
var_dump(PRACT_RegisterAction(12345, '482619', 67890, []));
```

A PIN must contain 4 to 16 digits.

Entering PIN `482619` now runs the action. `true` confirms successful registration. If it returns `false`, check the settings, access code and script ID.

#### Register an action with a pattern

For an instance configured for **Pattern**, use the same method and pass the visited points as a string:

```php
var_dump(PRACT_RegisterAction(12345, '13971', 67890, []));
```

Points are numbered from top left to bottom right:

| Left | Middle | Right |
| --- | --- | --- |
| 1 | 2 | 3 |
| 4 | 5 | 6 |
| 7 | 8 | 9 |

`13971` describes a closed square. ProtectedAction automatically inserts intermediate points where a straight connection passes over them. For example, `13` also includes `2`. You may return to a previous point, but cannot repeat the same point consecutively. Zero is not a valid pattern point.

A pattern contains 2 to 16 point visits, including automatically inserted intermediate points.

#### What is stored?

Registration copies the complete action script into encrypted storage. The original script is no longer needed afterwards. Once the protected action has been tested successfully, you can delete the original script if its contents should not remain openly accessible in the system. ProtectedAction does not delete it automatically.

Later changes to the original script do not affect the registered action. Register the action again to import changes. Reusing an access code replaces its previous action.

The final argument, `[]`, is for script parameters. If your script needs parameters, supply an array here; the running script receives those values through `$_IPS`. Always pass `[]` when no parameters are needed.

In addition, the running script always receives `$_IPS['SENDER'] = 'ProtectedAction'` and the ID of the ProtectedAction instance in `$_IPS['INSTANCE']` and `$_IPS['SELF']`. These keys take precedence over registered parameters of the same name.

#### Show script output

With **Show script output on the tile**, or `PRACT_RegisterActionEx()` with `$ShowOutput = true`, ProtectedAction waits for the script to finish and shows its output (for example from `echo`) on the tile for a few seconds. Output is limited to 200 characters and appears only on the client where the code was entered. Technically, it is transmitted to all connected clients like a navigation target. While such a script is running, the instance accepts no other input, so keep it short. Without this option, scripts run in the background as before.

**After testing, also remove the PIN or pattern and sensitive parameters from the registration script.**

### 3. Alternatively, register a navigation target

Instead of a script ID, pass the object ID of the visualization destination, such as a category:

```php
var_dump(PRACT_RegisterAction(12345, '739184', 54321, []));
```

This example uses PIN `739184`. In pattern mode, use a valid point sequence such as `'159'` instead. `54321` is the target ID. Keep the parameter array empty for navigation targets.

ProtectedAction detects whether the object is a script or a navigation target. After successful input, the destination opens only on the client where the access code was entered.

A navigation target may also be hidden. This allows navigation to categories that are normally not visible in the visualization.

### 4. Set up the Super PIN

Register a separate Super PIN for unlocking the instance later:

```php
var_dump(PRACT_RegisterSuperPIN(12345, '8073519264'));
```

The Super PIN is always numeric, even in pattern mode. Choose a sufficiently long, separate code and keep it safe. Remove it from the registration script afterwards. Registration alone does not start recovery.

The Super PIN must contain 4 to 16 digits. **It can be registered only once.** Otherwise, anyone with access to the system could lift a permanent lock simply by defining a new Super PIN. If you forget the Super PIN, reset the instance with `PRACT_Reset()` and register all actions again.

## Operation

Use the ProtectedAction instance as a tile in the visualization. It displays a numeric keypad or a square drawing pad, depending on the configured input mode.

### Input and execution

- **PIN:** Tap or click the digits. **C** clears the current input; **E** submits it. Digit positions can optionally be shuffled.
- **Pattern:** Touch with your finger or pen, or hold the left mouse button, and trace the registered point sequence. Lifting your finger or pen, or releasing the mouse button, submits it. A fading trail helps you follow the movement.

A matching access code decrypts the stored action. ProtectedAction executes the stored script contents or opens the registered navigation target. The tile on which the code was entered displays the result. Other tiles show only shared states such as locks.

Incomplete input is discarded after the configured input timeout. An empty **E**, a single tap on the drawing area and a cancelled drawing gesture do not count as failed attempts.

### Failed attempts and unlocking

Incorrect access codes count as failed attempts. Initially, input is temporarily locked. After the configured number of consecutive failures, ProtectedAction remains permanently locked. A successful regular entry resets the failure counter.

To unlock it from a script, pass the Super PIN:

```php
var_dump(PRACT_Unlock(12345, '8073519264'));
```

No preceding `PRACT_BeginRecovery()` call is required. A correct Super PIN unlocks the instance and resets the failure counters. Incorrect Super PINs also have a configurable attempt limit. Interactive recovery through the numeric keypad is described in the API section.

### Other settings

| Setting | Default | Effect |
| --- | --- | --- |
| Start delay after failed attempts | 3 | Start temporary lockouts at this failed attempt. |
| Base delay | 20 seconds | Lock for 20 seconds at the first delayed attempt, 40 at the next, and so on. |
| Permanently lock after failed attempts | 5 | Apply permanent lockout at this failed attempt. |
| Input timeout | 20 seconds | Discard incomplete input after inactivity. |
| Recovery attempts | 3 | Allowed incorrect Super PIN entries per recovery round. |
| Display duration for temporary messages | 3 seconds | How long feedback remains visible. |
| Scramble digits | Off | Shuffle keypad digits. In pattern mode, this applies only to the keypad for Super PIN entry. |
| Scramble interval while idle | 0 seconds | Periodically reshuffle the keypad; `0` disables the interval. |

### Scope of protection

ProtectedAction protects stored actions with encryption, failure lockouts and optional code binding. It is intended as a convenience safeguard and does not replace Symcon access controls. An administrator of the Symcon system can also modify the input process. Other system objects and files are not protected by the module. In particular, the original action script remains readable until it is deleted.

## API

Every call takes the ProtectedAction instance ID as its first argument. Always pass PINs and patterns as **strings**, preserving leading zeroes. The following tables list only the remaining arguments.

### Manage actions and the Super PIN

| Method | Additional arguments | Return | Description |
| --- | --- | --- | --- |
| `PRACT_RegisterAction` | `string $PIN, int $ObjectID, array $Parameters` | `bool` | Import an action script with parameters or register a navigation target. `$PIN` contains a PIN or pattern according to the instance mode. Pass `[]` for unused parameters. Reusing the access code replaces the action. |
| `PRACT_RegisterActionEx` | `string $PIN, int $ObjectID, array $Parameters, bool $ShowOutput` | `bool` | Like `PRACT_RegisterAction`, optionally showing the script output on the tile. `$ShowOutput` is only allowed for scripts. |
| `PRACT_Unregister` | `string $PIN` | `bool` | Remove the action assigned to the PIN or pattern. Returns `false` if the code is invalid or unregistered. |
| `PRACT_RegisterSuperPIN` | `string $PIN` | `bool` | Register the numeric Super PIN once. Returns `false` if a Super PIN already exists; a new one requires a reset. |
| `PRACT_Reset` | None | `void` | Delete all actions and the Super PIN, reset locks and counters, and allow input mode and code binding to be changed. |

### Unlocking and interactive recovery

| Method | Additional arguments | Return | Description |
| --- | --- | --- | --- |
| `PRACT_Unlock` | `string $SuperPIN` | `bool` | Directly unlock a permanently locked instance. No preceding `BeginRecovery()` required. Returns `true` only on successful recovery. |
| `PRACT_BeginRecovery` | None | `bool` | Start a new recovery round and enable the keypad for Super PIN entry. Requires permanent lockout and a registered Super PIN. |
| `PRACT_CancelRecovery` | None | `void` | End interactive recovery without unlocking. Previous recovery failures are preserved. |

For interactive recovery, call `PRACT_BeginRecovery(12345)`, enter the Super PIN on the keypad and confirm with **E**.

`Unlock()` and interactive entry share the same recovery counter. Once the recovery attempt limit is exhausted, even a correct Super PIN is rejected. The administrator can explicitly start a new round with `PRACT_BeginRecovery()`. Repeated `Unlock()` calls do not reset the counter.

If the instance is not permanently locked or has no registered Super PIN, `Unlock()` returns `false` without consuming an attempt.

### Custom controls and status

| Method | Additional arguments | Return | Description |
| --- | --- | --- | --- |
| `PRACT_InputDigit` | `int $Digit` | `bool` | Append a digit. Pattern mode accepts points `1` to `9`, without consecutive repetitions. Super PIN entry accepts digits `0` to `9`. Returns `false` for locked, invalid or overlong input. |
| `PRACT_Clear` | None | `void` | Clear the current input and temporary feedback. |
| `PRACT_Enter` | None | `bool` | Submit input. Returns `true` on successful script execution or recovery. |
| `PRACT_GetState` | None | `string` | Return JSON containing status, registration count and further instance details. |

Custom buttons can send digits through `PRACT_InputDigit()`, clear them with `PRACT_Clear()` and submit them with `PRACT_Enter()`. The same lockout rules apply.

**Navigation targets require the built-in tile.** A PHP call to `PRACT_Enter()` cannot be associated with a visualization client. A valid access code for a navigation target then returns `false` with `VISUALIZATION_REQUIRED`.

The **Status** variable contains JSON with these fields:

| Field | Meaning |
| --- | --- |
| `Message` | Message code, listed below. |
| `InputActive` | Input from `PRACT_InputDigit()` is present in the server-side buffer. Input on the built-in tile stays on the client until it is submitted. |
| `Locked` | Input is currently locked. |
| `LockUntil` | Unix timestamp for the end of a temporary lockout, otherwise `0`. |
| `PermanentlyLocked` | Permanent lockout is active. |
| `RecoveryMode` | Interactive Super PIN entry is active. |
| `ServerTime` | Current server time as a Unix timestamp. |

`PRACT_GetState()` additionally returns:

| Field | Meaning |
| --- | --- |
| `RegisteredActions` | Number of registered actions. |
| `FailedAttempts` | Number of regular failed attempts. |
| `RecoveryDefined` | A Super PIN is registered. |
| `InputMode` | `0` for PIN, `1` for pattern. |
| `InputModeLocked` | Changing input mode requires a reset. |
| `CodeBound` | Code binding is enabled. |
| `CodeBindingLocked` | Changing code binding requires a reset. |
| `ReregistrationRequired` | The module code has changed. Reset and register again. |

Message codes are independent of the display language:

| Code | Meaning |
| --- | --- |
| `READY` | Ready for input. |
| `INPUT_ACTIVE` | Input is present. |
| `PIN_INVALID` | Invalid PIN or pattern. |
| `ACTION_EXECUTED` | Action executed successfully. |
| `ACTION_FAILED` | Access code recognized, but the action failed. |
| `TEMPORARILY_LOCKED` | Temporarily locked. |
| `PERMANENTLY_LOCKED` | Permanently locked. |
| `RECOVERY_ACTIVE` | Super PIN entry is enabled. |
| `SUPER_PIN_INVALID` | Invalid Super PIN. |
| `UNLOCKED` | Successfully unlocked. |
| `VISUALIZATION_REQUIRED` | Use the built-in tile for this navigation target. |
| `REREGISTRATION_REQUIRED` | The module code has changed. Reset and register again. |

Status output contains no access codes, script contents, parameters or target IDs. Result messages for tile input are sent only to the tile on which the code was entered; the `Status` variable and `PRACT_GetState()` still contain them for custom controls. During successful navigation, the target ID is transmitted over the visualization channel and is technically visible to other connected clients.

`PRACT_InternalTimer()` is an internal timer callback. The `PRACT_UI…()` methods serve the configuration form. `RequestAction()`, `GetConfigurationForm()` and `GetVisualizationTile()` are used by Symcon itself.
