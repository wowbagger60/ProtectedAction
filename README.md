# Protected Action

Protected Action executes an encrypted action only after a registered PIN has
been entered. An action can contain either an imported Symcon script or a
visualization target.

This module is not a replacement for IP-Symcon access controls or a complete alarm system.

## Important before updating

`module.php` and `module.html` are part of the key derivation. Every change to
either file makes existing actions and the Super PIN undecryptable. After **every module update**, reset the old data and register all actions and the Super PIN again. Migration is intentionally unsupported:

```php
PRACT_Reset(12345);
```

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
    54321       // category or other visualization target
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

The `Status` string variable (Ident `Status`) publishes the first seven fields above, but not the final three administrative fields. Use `PRACT_GetState()` when those additional fields are needed. For example:

```php
$state = json_decode(PRACT_GetState(12345), true, 512, JSON_THROW_ON_ERROR);
if ($state['Locked']) {
    // Disable your own digit buttons or display a lock message.
}
```

### Configuration options

| Option | Default | Meaning |
| --- | ---: | --- |
| Minimum PIN length | 4 | Minimum accepted PIN length. |
| Maximum PIN length | 16 | Maximum accepted PIN length. |
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

The keypad is sized to the available visualization content area, not the tile header. No tile/content option is needed.

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
