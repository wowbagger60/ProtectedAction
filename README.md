# Protected Action

Protected Action executes an encrypted action only after a registered PIN or pattern has
been entered. An action can contain either an imported Symcon script or a
visualization target.

This module is not a replacement for IP-Symcon access controls or a complete alarm system.

## Setup and code binding

1. Select **PIN** or **Pattern** input mode.
2. Set **Require re-registration after module code changes**. It is **enabled by default**.
3. Apply the configuration, then register actions and the Super PIN.

| Code binding | Behaviour after module code changes |
| --- | --- |
| Enabled (default) | PHP and tile contents participate in key derivation. Changes invalidate existing registrations. Reset and re-register. |
| Disabled | Encrypted actions and the Super PIN survive compatible updates. Module contents are excluded from key derivation. |

**Input mode and code binding are locked after the first action or Super PIN registration.** Removing the final action does not release either lock. Changing either option requires an explicit reset:

```php
PRACT_Reset(12345);
```

Reset deletes all actions, the Super PIN, locks and counters. Select and apply the desired options before registering again. Changing a locked option through the API also blocks input and registration. Restore the original option value to continue using the existing registrations.

Without code binding, authenticated encryption remains in place, but protection against module code changes is intentionally removed. This is a convenience choice, for example for unlocking edit controls. Update resistance requires unchanged or explicitly compatible data formats and key derivation; it cannot guarantee compatibility with arbitrary future format changes.

### Upgrading to 1.1 Build 3

This build removes four length settings and introduces optional code binding with a new encryption format. **Registrations from earlier builds require a one-time reset and re-registration.** No automatic decryption or conversion is performed, and existing code-bound records are never silently treated as update-independent records.

For future compatible updates without re-registration, disable code binding **after reset and before the first registration**. The option cannot migrate or unlock existing code-bound data.

## Pattern input

Point numbering, from top left to bottom right:

| Left | Middle | Right |
| --- | --- | --- |
| 1 | 2 | 3 |
| 4 | 5 | 6 |
| 7 | 8 | 9 |

The existing registration API is unchanged. For a closed square:

```php
var_dump(PRACT_RegisterAction(12345, '13971', 67890, []));
```

The object may be a tested script or a visualization target; targets require an empty parameter array. Remove the code from the registration script afterwards.

- Digits `1`–`9` only; no zero, stars, or consecutive repeated points. Returning to a previously visited point is allowed (`131`).
- Exact straight-line midpoints are inserted server-side for registration and verification: `13` becomes `123`, `19` becomes `159`, and `13971` becomes `123698741`. `16` and `15` have no midpoint. Midpoints are inserted even if previously visited, unlike Android patterns.
- Registrations with the same expanded sequence replace the same action.
- Hold the left mouse button, touch, or pen and draw. Releasing submits the gesture, without an Enter button.
- Square drawing area, fixed hit areas, exact point sequence, no tolerant recognition. Additional detected points invalidate the pattern.
- No point highlighting or visible sequence. The trail fades over approximately 650 ms and is cleared on release.
- Cancelled and empty gestures are not failed attempts. A completed wrong or overlong gesture counts once.
- Partial gestures stay local; only the completed sequence is submitted. The server's `InputActive` field does not report a local gesture in progress. The input timeout cancels an inactive gesture.
- Recovery always displays a numeric keypad. Existing scrambling settings also apply to this recovery keypad.

`PRACT_Unregister()` accepts the same pattern as registration. In pattern mode, `PRACT_InputDigit()` accepts points `1`–`9`, rejects zero and consecutive repetitions, and `PRACT_Enter()` submits. Client-specific navigation still requires the built-in tile. PIN, pattern and recovery have separate key-derivation domains.

## Fixed code lengths

| Input | Minimum | Maximum |
| --- | ---: | ---: |
| PIN | 4 digits | 16 digits |
| Pattern | 2 point visits | 16 point visits |
| Super PIN | 4 digits | 16 digits |

Pattern limits count the **expanded sequence**, including mandatory straight-line midpoints. The square `13971` therefore counts nine visits. The former four length settings are removed. Limits are enforced server-side and for input buffers. Choose a sufficiently long, independent Super PIN.

## Register a script action

First create and test a normal Symcon script. For example:

```php
IPS_SetHidden($_IPS['OBJECT_ID'], $_IPS['HIDDEN']);
```

Then import it with a temporary registration script:

```php
var_dump(PRACT_RegisterAction(
    12345,      // Protected Action instance
    '123456',   // PIN
    67890,      // tested source script
    [
        'OBJECT_ID' => 54321,
        'HIDDEN'    => false
    ]
));
```

Protected Action reads the source script immediately and encrypts its complete
content together with the parameters. At execution time the parameters are
available through `$_IPS`. The source script ID is not stored and later changes
to that script have no effect on the imported action.

After testing the protected action successfully, delete the source script
manually or remove its sensitive content. Protected Action never deletes it
automatically. Also remove the PIN and parameters from the temporary
registration script.

## Register a visualization target

Pass any existing non-script object without parameters:

```php
var_dump(PRACT_RegisterAction(
    12345,      // Protected Action instance
    '654321',   // PIN
    54321,      // category or other visualization target
    []          // no parameters for targets
));
```

After the PIN has been entered through the built-in tile, the target opens only
on the client which submitted that PIN. Protected Action correlates the request
with a temporary random request ID. Other open clients ignore the navigation
message.

Visualization targets cannot be opened through `PRACT_Enter()`, because a PHP
API call has no associated visualization client. A correct PIN then returns
`false` and publishes the message code `VISUALIZATION_REQUIRED`. Script actions
continue to work through both the built-in tile and the public keypad API.

The target ID is encrypted at rest. During successful navigation it is sent
briefly through Symcon's visualization channel and is therefore not secret from
other connected clients. Hiding an object is a convenience feature, not access
control.

## Registration behaviour

`PRACT_RegisterAction()` detects the action type automatically:

- A script is imported as encrypted script text together with its parameters.
- Any other existing object is stored as an encrypted visualization target;
  parameters are rejected for this action type.
- A missing object or an empty source script is rejected.

Registering the same PIN again replaces its previous action. Remove an action
with:

```php
PRACT_Unregister(12345, '123456');
```


## Public API reference

All calls use the Protected Action instance ID as their first argument. The `PRACT_` functions are the IP-Symcon wrappers around the module methods. PINs are strings (including leading zeros), not integers.

| Function | Arguments after instance ID | Result | Purpose |
| --- | --- | --- | --- |
| `PRACT_RegisterAction` | `string $PIN, int $ObjectID, array $Parameters = []` | `bool` | Encrypt a copy of a script and its parameters, or register a non-script object as a visualization target. Reusing a PIN replaces the action. |
| `PRACT_Unregister` | `string $PIN` | `bool` | Remove the action assigned to a PIN; `false` if not found or invalid. |
| `PRACT_RegisterSuperPIN` | `string $PIN` | `bool` | Register or replace the recovery PIN. |
| `PRACT_InputDigit` | `int $Digit` | `bool` | Append one digit (`0`–`9`); `false` when input is locked, invalid, or full. |
| `PRACT_Clear` | none | `void` | Clear the current PIN entry and temporary message. |
| `PRACT_Enter` | none | `bool` | Submit the entered PIN. `true` on successful script execution or recovery; navigation targets require the built-in visualization tile. |
| `PRACT_BeginRecovery` | none | `bool` | Start Super PIN entry; only available after permanent lockout with a Super PIN registered. |
| `PRACT_CancelRecovery` | none | `void` | Leave recovery mode without unlocking. |
| `PRACT_Reset` | none | `void` | **Delete all actions and the Super PIN** and clear locks/counters. No undo. |
| `PRACT_GetState` | none | `string` | Return a JSON status snapshot, including registration count and failure count. |

`PRACT_InternalTimer()` is a technical timer callback, **not** part of the user-facing API. `RequestAction()` and `GetVisualizationTile()` are framework integration methods, not recommended for direct scripting.

### Build your own PIN keypad

You can replace the built-in keypad with your own controls, for example buttons in a custom visualization or actions triggered by a separate input device. Route each digit to `PRACT_InputDigit()`, the delete/clear button to `PRACT_Clear()`, and the confirmation button to `PRACT_Enter()`:

```php
// Script invoked by your own controls; adapt the source of $Key.
$InstanceID = 12345;
$Key = (string) $_IPS['VALUE']; // Example: '0'..'9', 'C', 'E'

if (preg_match('/^[0-9]$/', $Key) === 1) {
    PRACT_InputDigit($InstanceID, (int) $Key);
} elseif ($Key === 'C') {
    PRACT_Clear($InstanceID);
} elseif ($Key === 'E') {
    PRACT_Enter($InstanceID);
}
```

The example assumes that your control passes a single character in `$_IPS['VALUE']`; adjust this to your actual visualization/event setup. Do **not** build the PIN as a plaintext string in a visualization variable or log it. The module collects digits internally, clears the input on submission, and applies its own input timeout and lockout rules. The custom keypad must not attempt to implement PIN validation itself.

**Important limitation:** Custom PHP controls can execute encrypted **script actions**, but they cannot open a **visualization target**. Client-specific navigation uses the built-in tile's request correlation. A valid visualization-target PIN entered through `PRACT_Enter()` returns `false` with `VISUALIZATION_REQUIRED`.

### Reading state and showing feedback

`PRACT_GetState($InstanceID)` returns JSON with these fields:

| Field | Meaning |
| --- | --- |
| `Message` | Stable message code listed above. |
| `InputActive` | At least one digit has been entered; the digits and their count are not exposed. |
| `Locked` | PIN entry currently prohibited. |
| `LockUntil` | Unix timestamp for temporary lock expiry, or `0`. |
| `PermanentlyLocked` | Permanent lock flag. |
| `RecoveryMode` | Super PIN entry mode. |
| `ServerTime` | Unix timestamp of the snapshot. |
| `RegisteredActions` | Number of stored actions. |
| `FailedAttempts` | Failed regular PIN attempts. |
| `RecoveryDefined` | Whether a Super PIN is registered. |
| `InputMode` | `0` = PIN, `1` = pattern. |
| `InputModeLocked` | Mode change requires reset. |
| `CodeBound` | Currently configured code binding. |
| `CodeBindingLocked` | Binding change requires reset. |

The `Status` string variable (Ident `Status`) publishes the first seven fields above, but not the final seven administrative fields. Use `PRACT_GetState()` when those additional fields are needed. For example:

```php
$state = json_decode(PRACT_GetState(12345), true, 512, JSON_THROW_ON_ERROR);
if ($state['Locked']) {
    // Disable your own digit buttons or display a lock message.
}
```

### Configuration options

| Option | Default | Meaning |
| --- | ---: | --- |
| Input mode | PIN (0) | PIN or pattern (1); frozen after first registration. |
| Require re-registration after module code changes | on | Code binding; disable for registration persistence across compatible updates. Locked after first registration. |
| Start delay after failed attempts | 3 | Failure threshold for temporary lockouts. |
| Base delay | 20 s | Base lockout duration. |
| Permanently lock after failed attempts | 5 | Failure threshold for permanent lockout. |
| Input timeout | 20 s | Clear unfinished PIN entry after inactivity. |
| Recovery attempts | 3 | Allowed unsuccessful Super PIN attempts. |
| Display duration for temporary messages | 3 s | How long transient feedback is shown. |
| Scramble digits | off | Randomize digit placement in the built-in keypad. |
| Scramble interval while idle | 0 s | Optional idle-time reshuffling (`0` disables it). |

## Security boundary

Only the imported script text, its parameters, and visualization target IDs
inside Protected Action are encrypted. Everything an action accesses outside
the module is visible to and modifiable by Symcon, including variables, other
scripts, files, instance APIs, logs and remote endpoints. A self-contained
imported script therefore offers the clearest protection.

An administrator with persistent control of IP-Symcon can modify the PIN input
path and capture a PIN when it is entered later. Short PINs can also be tested
offline by anyone who obtains the encrypted module data. Argon2id slows this
down but cannot compensate for a small keyspace.

## Super PIN and recovery

```php
PRACT_RegisterSuperPIN(12345, '1234567890');
PRACT_BeginRecovery(12345);
```

After `BeginRecovery()`, enter the Super PIN through the keypad and press Enter.
A successful recovery resets the failure counters. `PRACT_CancelRecovery()`
ends recovery without unlocking the instance.

## Tile layout

The keypad or pattern pad is sized to the available visualization content area, not the tile header. No tile/content option is needed.

## Keypad and status

A custom PIN pad can use:

```php
PRACT_InputDigit(12345, 7);
PRACT_Clear(12345);
PRACT_Enter(12345);
```

The visible string variable with Ident `Status` contains machine-readable JSON.
Its `Message` field uses stable codes:

- `READY`
- `INPUT_ACTIVE`
- `PIN_INVALID`
- `ACTION_EXECUTED`
- `ACTION_FAILED`
- `VISUALIZATION_REQUIRED`
- `TEMPORARILY_LOCKED`
- `PERMANENTLY_LOCKED`
- `RECOVERY_ACTIVE`
- `SUPER_PIN_INVALID`
- `UNLOCKED`

It never contains a PIN, PIN length, script text, parameters or target ID.
Protected Action does not implement toggle state; the imported action is
responsible for all state-dependent behaviour.
