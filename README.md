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
