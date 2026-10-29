<?php

declare(strict_types=1);

class ProtectedAction extends IPSModuleStrict
{
    // Records written up to version 1.1. They remain readable and are migrated on first use.
    private const LEGACY_RECORD_FORMAT = 6;
    private const RECORD_FORMAT = 7;
    // The decrypted payload format is independent of the record format.
    private const PAYLOAD_FORMAT = 6;
    // Keep these values and their semantics stable across compatible module updates.
    private const KDF_VERSION = 1;
    private const KDF_OPSLIMIT = 2;
    private const KDF_MEMLIMIT = 67108864; // 64 MiB; part of KDF version 1
    private const KDF_CONTEXT = 'PRACTkey'; // Exactly 8 bytes for sodium_crypto_kdf_derive_from_key()
    private const SUBKEY_LOOKUP = 1;
    private const SUBKEY_ENCRYPTION = 2;
    private const MAX_OUTPUT_LENGTH = 200;
    private const MIN_PIN_LENGTH = 4;
    private const MIN_PATTERN_LENGTH = 2;
    private const MAX_CODE_LENGTH = 16;
    private const MODULE_ID = '{84C928B5-BB9D-4E25-8FC4-574B1F2B945E}';
    // Semaphore timeouts in milliseconds. Entries do not queue: a concurrent entry is dropped.
    private const WAIT_ENTRY = 250;
    private const WAIT_INPUT = 5000;
    private const WAIT_ADMIN = 60000;
    private const INPUT_BUFFER = 'Input';
    private const INPUT_TIME_BUFFER = 'InputTime';
    private const MESSAGE_BUFFER = 'Message';
    private const MESSAGE_CODES = [
        'READY', 'INPUT_ACTIVE', 'PIN_INVALID', 'ACTION_EXECUTED', 'ACTION_FAILED',
        'TEMPORARILY_LOCKED', 'PERMANENTLY_LOCKED', 'RECOVERY_ACTIVE',
        'SUPER_PIN_INVALID', 'UNLOCKED', 'VISUALIZATION_REQUIRED', 'REREGISTRATION_REQUIRED'
    ];

    // Request of the tile submission being processed. Its result message is sent to that
    // client only; other tiles never show the outcome of someone else's entry.
    private ?string $resultRequestID = null;
    private ?string $result = null;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType($this->VisualizationType());

        $this->RegisterPropertyInteger('InputMode', 0); // 0 = PIN, 1 = pattern
        $this->RegisterPropertyBoolean('BindToModuleCode', true);
        $this->RegisterPropertyInteger('DelayAfterFailures', 3);
        $this->RegisterPropertyInteger('BaseDelay', 20);
        $this->RegisterPropertyInteger('PermanentLockAfter', 5);
        $this->RegisterPropertyInteger('InputTimeout', 20);
        $this->RegisterPropertyInteger('RecoveryAttempts', 3);
        $this->RegisterPropertyInteger('MessageDuration', 3);
        $this->RegisterPropertyBoolean('ScrambleKeypad', false);
        $this->RegisterPropertyInteger('ScrambleInterval', 0);
        $this->RegisterPropertyInteger('KeypadStyle', 2); // 0 = round, 1 = pills, 2 = tiles
        $this->RegisterPropertyInteger('KeyLabels', 0); // 0 = ENTER/CLEAR, 1 = OK/translated CLEAR
        $this->RegisterPropertyInteger('PatternTrail', 0); // 0 = prominent, 1 = fading, 2 = invisible

        $this->RegisterAttributeInteger('RegisteredInputMode', -1);
        $this->RegisterAttributeInteger('RegisteredCodeBinding', -1);
        $this->RegisterAttributeString('Actions', '[]');
        $this->RegisterAttributeString('Recovery', '');
        $this->RegisterAttributeString('KeySalt', '');
        $this->RegisterAttributeString('CodeFingerprint', '');
        $this->RegisterAttributeInteger('FailedAttempts', 0);
        $this->RegisterAttributeInteger('LockUntil', 0);
        $this->RegisterAttributeBoolean('PermanentlyLocked', false);
        $this->RegisterAttributeBoolean('RecoveryMode', false);
        $this->RegisterAttributeInteger('RecoveryFailedAttempts', 0);

        $this->RegisterVariableString('Status', 'Status');
        $this->RegisterTimer('MessageTimer', 0, 'PRACT_InternalTimer($_IPS["TARGET"], "Message");');
        $this->RegisterTimer('InputTimer', 0, 'PRACT_InternalTimer($_IPS["TARGET"], "Input");');
        $this->RegisterTimer('LockTimer', 0, 'PRACT_InternalTimer($_IPS["TARGET"], "Lock");');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetVisualizationType($this->VisualizationType());
        $this->SetTimerInterval('MessageTimer', 0);
        $this->SetTimerInterval('InputTimer', 0);
        $this->SetBuffer(self::MESSAGE_BUFFER, '');
        $this->ClearInput();

        if (!$this->CryptoAvailable()) {
            $this->SetStatus(201);
            $this->SetSummary($this->Translate('Sodium extension is not available'));
            return;
        }
        // Existing registrations predate InputMode and therefore always belong to PIN mode.
        if ($this->ReadAttributeInteger('RegisteredInputMode') === -1 && $this->HasRegistrations()) {
            $this->WriteAttributeInteger('RegisteredInputMode', 0);
        }
        // All registrations created before this option was introduced are code-bound.
        if ($this->ReadAttributeInteger('RegisteredCodeBinding') === -1 && $this->HasRegistrations()) {
            $this->WriteAttributeInteger('RegisteredCodeBinding', 1);
        }
        $error = $this->ConfigurationError();
        if ($error !== '') {
            $this->SetStatus(202);
            $this->SetSummary($error);
            $this->PublishStatus();
            $this->ReloadForm();
            return;
        }

        $lockUntil = $this->ReadAttributeInteger('LockUntil');
        if ($lockUntil > time()) {
            $this->SetTimerInterval('LockTimer', ($lockUntil - time()) * 1000);
        } else {
            $this->WriteAttributeInteger('LockUntil', 0);
            $this->SetTimerInterval('LockTimer', 0);
        }
        $this->SetStatus($this->ReregistrationRequired() ? 203 : 102);
        $this->UpdateSummary();
        $this->PublishStatus();
        $this->ReloadForm();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($form['elements'] as &$element) {
            switch ($element['name'] ?? '') {
                case 'InputMode':
                case 'BindToModuleCode':
                    $element['enabled'] = !$this->ModeIsLocked();
                    break;
                case 'ConfigurationError':
                    $element['caption'] = $this->StateError();
                    $element['visible'] = $element['caption'] !== '';
                    break;
            }
        }
        unset($element);
        $captions = $this->ReadPropertyInteger('InputMode') === 1
            ? ['Code' => 'Pattern (points 1 to 9)', 'CodeConfirmation' => 'Repeat pattern']
            : [];
        $superPINDefined = $this->ReadAttributeString('Recovery') !== '';
        foreach ($form['actions'] as &$panel) {
            foreach ($panel['items'] ?? [] as $index => $item) {
                $name = $item['name'] ?? '';
                if (isset($captions[$name])) {
                    $panel['items'][$index]['caption'] = $captions[$name];
                }
                if (in_array($name, ['SuperPIN', 'SuperPINConfirmation', 'RegisterSuperPIN'], true)) {
                    $panel['items'][$index]['visible'] = !$superPINDefined;
                }
                if ($name === 'SuperPINRegistered') {
                    $panel['items'][$index]['visible'] = $superPINDefined;
                }
            }
        }
        unset($panel);
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Submit':
            case 'Pattern': // Sent by tiles loaded before version 1.2
                $this->Synchronized(self::WAIT_ENTRY, fn (): bool => $this->ProcessSubmission($Value), false);
                break;
            case 'Digit':
                $this->InputDigit((int) $Value);
                break;
            case 'Clear':
                $this->Clear();
                break;
            case 'Enter':
                $this->Enter();
                break;
            case 'Refresh':
                $this->Synchronized(self::WAIT_INPUT, function (): bool {
                    $this->ExpireInput();
                    $this->PublishStatus();
                    return true;
                }, false);
                break;
            default:
                throw new InvalidArgumentException('Unknown action: ' . $Ident);
        }
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '';
        }
        return $html . '<script>handleMessage(' . json_encode(
            $this->GetTileData(),
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ');</script>';
    }

    public function RegisterAction(string $PIN, int $ObjectID, array $Parameters = []): bool
    {
        return $this->Synchronized(
            self::WAIT_ADMIN,
            fn (): bool => $this->StoreAction($PIN, $ObjectID, $Parameters, false),
            false
        );
    }

    public function RegisterActionEx(string $PIN, int $ObjectID, array $Parameters, bool $ShowOutput): bool
    {
        return $this->Synchronized(
            self::WAIT_ADMIN,
            fn (): bool => $this->StoreAction($PIN, $ObjectID, $Parameters, $ShowOutput),
            false
        );
    }

    // Called by the configuration form. Codes are never stored as properties.
    public function UIRegisterAction(string $Code, string $Confirmation, int $ObjectID, string $Parameters, bool $ShowOutput): void
    {
        $this->ClearFormCodes(['Code', 'CodeConfirmation']);
        if ($Code !== $Confirmation) {
            echo $this->Translate('The codes do not match.');
            return;
        }
        $parameters = [];
        if (trim($Parameters) !== '') {
            try {
                $parameters = json_decode($Parameters, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $parameters = null;
            }
            if (!is_array($parameters)) {
                echo $this->Translate('Script parameters must be a JSON object.');
                return;
            }
        }
        echo $this->RegisterActionEx($Code, $ObjectID, $parameters, $ShowOutput)
            ? $this->Translate('Action registered.')
            : $this->Translate('Registration failed. Check the code, the target, the parameters and the instance status.');
    }

    public function UIUnregister(string $Code): void
    {
        $this->ClearFormCodes(['Code', 'CodeConfirmation']);
        echo $this->Unregister($Code)
            ? $this->Translate('Action removed.')
            : $this->Translate('No action is registered for this code.');
    }

    public function UIRegisterSuperPIN(string $PIN, string $Confirmation): void
    {
        $this->ClearFormCodes(['SuperPIN', 'SuperPINConfirmation']);
        if ($this->ReadAttributeString('Recovery') !== '') {
            echo $this->Translate('A Super PIN is already registered. Reset the instance to define a new one.');
            return;
        }
        if ($PIN !== $Confirmation) {
            echo $this->Translate('The codes do not match.');
            return;
        }
        echo $this->RegisterSuperPIN($PIN)
            ? $this->Translate('Super PIN registered.')
            : $this->Translate('Registration failed. The Super PIN must have 4 to 16 digits.');
    }

    public function UIUnlock(string $SuperPIN): void
    {
        $this->ClearFormCodes(['UnlockPIN']);
        echo $this->Unlock($SuperPIN)
            ? $this->Translate('Unlocked.')
            : $this->Translate('Unlocking failed.');
    }

    public function Unregister(string $PIN): bool
    {
        return $this->Synchronized(self::WAIT_ADMIN, fn (): bool => $this->RemoveAction($PIN), false);
    }

    public function RegisterSuperPIN(string $PIN): bool
    {
        return $this->Synchronized(self::WAIT_ADMIN, function () use ($PIN): bool {
            // The Super PIN can be defined only once. Replacing it would lift a permanent
            // lock without knowing the old one; a forgotten Super PIN requires a reset.
            if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' ||
                $this->ReregistrationRequired() || $this->ReadAttributeString('Recovery') !== '' ||
                !$this->ValidPIN($PIN)) {
                return false;
            }
            $payload = json_encode(['format' => self::PAYLOAD_FORMAT, 'purpose' => 'recovery'], JSON_THROW_ON_ERROR);
            $this->StoreRecovery($PIN, $payload);
            sodium_memzero($PIN);
            $this->StoreCodeFingerprint();
            $this->LockRegistrationOptions();
            $this->PublishStatus();
            return true;
        }, false);
    }

    public function InputDigit(int $Digit): bool
    {
        return $this->Synchronized(self::WAIT_INPUT, function () use ($Digit): bool {
            $this->ExpireInput();
            if ($Digit < 0 || $Digit > 9 || !$this->InputAllowed()) {
                return false;
            }
            $input = $this->GetBuffer(self::INPUT_BUFFER);
            $recovery = $this->ReadAttributeBoolean('RecoveryMode');
            if (!$recovery && $this->ReadPropertyInteger('InputMode') === 1 &&
                ($Digit === 0 || str_ends_with($input, (string) $Digit))) {
                return false;
            }
            if (strlen($input) >= self::MAX_CODE_LENGTH) {
                return false;
            }
            $this->ClearTransientMessage();
            $this->SetBuffer(self::INPUT_BUFFER, $input . (string) $Digit);
            $this->SetBuffer(self::INPUT_TIME_BUFFER, (string) time());
            $this->SetTimerInterval('InputTimer', $this->ReadPropertyInteger('InputTimeout') * 1000);
            $this->PublishStatus();
            return true;
        }, false);
    }

    public function Clear(): void
    {
        $this->Synchronized(self::WAIT_INPUT, function (): bool {
            $this->ClearInput();
            $this->ClearTransientMessage();
            $this->PublishStatus();
            return true;
        }, false);
    }

    public function Enter(): bool
    {
        return $this->Synchronized(self::WAIT_ENTRY, function (): bool {
            $this->ExpireInput();
            $code = $this->GetBuffer(self::INPUT_BUFFER);
            $this->ClearInput();
            // Code entered through the API buffer cannot be assigned to a visualization client.
            return $this->ProcessEntry($code, null);
        }, false);
    }

    public function Unlock(string $SuperPIN): bool
    {
        return $this->Synchronized(self::WAIT_ADMIN, function () use ($SuperPIN): bool {
            if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' ||
                $this->ReregistrationRequired() ||
                !$this->ReadAttributeBoolean('PermanentlyLocked') ||
                $this->ReadAttributeString('Recovery') === '') {
                return false;
            }
            // Do not call BeginRecovery(): it explicitly starts a new attempt budget.
            // API calls share the current budget with the interactive recovery flow.
            if ($this->ReadAttributeInteger('RecoveryFailedAttempts') >=
                $this->ReadPropertyInteger('RecoveryAttempts')) {
                sodium_memzero($SuperPIN);
                return false;
            }
            $this->ClearInput();
            if (!$this->ValidPIN($SuperPIN)) {
                sodium_memzero($SuperPIN);
                return $this->RejectRecovery();
            }
            return $this->TryRecovery($SuperPIN);
        }, false);
    }

    public function BeginRecovery(): bool
    {
        return $this->Synchronized(self::WAIT_INPUT, function (): bool {
            if (!$this->ReadAttributeBoolean('PermanentlyLocked') ||
                $this->ReadAttributeString('Recovery') === '' || $this->ReregistrationRequired()) {
                return false;
            }
            $this->WriteAttributeBoolean('RecoveryMode', true);
            $this->WriteAttributeInteger('RecoveryFailedAttempts', 0);
            $this->ClearInput();
            $this->ClearTransientMessage();
            $this->UpdateSummary();
            $this->PublishStatus();
            return true;
        }, false);
    }

    public function CancelRecovery(): void
    {
        $this->Synchronized(self::WAIT_INPUT, function (): bool {
            $this->WriteAttributeBoolean('RecoveryMode', false);
            $this->ClearInput();
            $this->ClearTransientMessage();
            $this->UpdateSummary();
            $this->PublishStatus();
            return true;
        }, false);
    }

    public function Reset(): void
    {
        $this->Synchronized(self::WAIT_ADMIN, function (): bool {
            $this->WriteAttributeString('Actions', '[]');
            $this->WriteAttributeString('Recovery', '');
            $this->WriteAttributeString('KeySalt', '');
            $this->WriteAttributeString('CodeFingerprint', '');
            $this->WriteAttributeInteger('RegisteredInputMode', -1);
            $this->WriteAttributeInteger('RegisteredCodeBinding', -1);
            $this->WriteAttributeInteger('FailedAttempts', 0);
            $this->WriteAttributeInteger('LockUntil', 0);
            $this->WriteAttributeBoolean('PermanentlyLocked', false);
            $this->WriteAttributeBoolean('RecoveryMode', false);
            $this->WriteAttributeInteger('RecoveryFailedAttempts', 0);
            $this->SetTimerInterval('LockTimer', 0);
            $this->ClearInput();
            $this->ClearTransientMessage();
            $this->UpdateSummary();
            $this->PublishStatus();
            if ($this->CryptoAvailable() && $this->ConfigurationError() === '') {
                $this->SetStatus(102);
            }
            $this->ReloadForm();
            return true;
        }, false);
    }

    public function GetState(): string
    {
        return json_encode(array_merge($this->GetStatusData(true), [
            'RegisteredActions'      => count($this->ReadActions()),
            'FailedAttempts'         => $this->ReadAttributeInteger('FailedAttempts'),
            'RecoveryDefined'        => $this->ReadAttributeString('Recovery') !== '',
            'InputMode'              => $this->ReadPropertyInteger('InputMode'),
            'InputModeLocked'        => $this->ModeIsLocked(),
            'CodeBound'              => $this->ReadPropertyBoolean('BindToModuleCode'),
            'CodeBindingLocked'      => $this->ModeIsLocked(),
            'ReregistrationRequired' => $this->ReregistrationRequired()
        ]), JSON_THROW_ON_ERROR);
    }

    public function InternalTimer(string $Timer): void
    {
        $timers = ['Message' => 'MessageTimer', 'Input' => 'InputTimer', 'Lock' => 'LockTimer'];
        if (!isset($timers[$Timer])) {
            return;
        }
        $handled = $this->Synchronized(self::WAIT_INPUT, function () use ($Timer): bool {
            switch ($Timer) {
                case 'Message':
                    $this->ClearTransientMessage();
                    break;
                case 'Input':
                    $this->ClearInput();
                    break;
                case 'Lock':
                    // A failure handled just before this timer fired may have extended the lock.
                    $remaining = $this->ReadAttributeInteger('LockUntil') - time();
                    if ($remaining > 0) {
                        $this->SetTimerInterval('LockTimer', $remaining * 1000);
                        return true;
                    }
                    $this->WriteAttributeInteger('LockUntil', 0);
                    $this->SetTimerInterval('LockTimer', 0);
                    $this->UpdateSummary();
                    break;
            }
            $this->PublishStatus();
            return true;
        }, false);
        if (!$handled) {
            // Busy with an entry or registration; try again shortly.
            $this->SetTimerInterval($timers[$Timer], 1000);
        }
    }

    private function VisualizationType(): int
    {
        // Fullscreen-capable HTML tiles (type 2) exist from 9.1; older kernels use the normal HTML SDK (type 1).
        return version_compare(IPS_GetKernelVersion(), '9.1', '>=') ? 2 : 1;
    }

    private function Synchronized(int $Timeout, callable $Callback, mixed $Busy): mixed
    {
        $name = 'ProtectedAction_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($name, $Timeout)) {
            return $Busy;
        }
        try {
            return $Callback();
        } finally {
            IPS_SemaphoreLeave($name);
        }
    }

    private function ClearFormCodes(array $Fields): void
    {
        foreach ($Fields as $field) {
            $this->UpdateFormField($field, 'value', '');
        }
    }

    private function StoreAction(string $PIN, int $ObjectID, array $Parameters, bool $ShowOutput): bool
    {
        $normalized = $this->NormalizeAccessCode($PIN, false);
        // IDs below 10000 are the root or the form's "none" selection, never a script or target.
        if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' || $this->ReregistrationRequired() ||
            $normalized === null || $ObjectID < 10000 || !IPS_ObjectExists($ObjectID)) {
            return false;
        }
        sodium_memzero($PIN);
        $PIN = $normalized;
        sodium_memzero($normalized);
        try {
            if (IPS_ScriptExists($ObjectID)) {
                $scriptText = IPS_GetScriptContent($ObjectID);
                if (trim($scriptText) === '') {
                    sodium_memzero($scriptText);
                    sodium_memzero($PIN);
                    return false;
                }
                $action = [
                    'format'     => self::PAYLOAD_FORMAT,
                    'type'       => 'ScriptText',
                    'script'     => $scriptText,
                    'parameters' => $Parameters
                ];
                if ($ShowOutput) {
                    $action['output'] = true;
                }
            } else {
                if ($Parameters !== [] || $ShowOutput) {
                    sodium_memzero($PIN);
                    return false;
                }
                $action = [
                    'format'   => self::PAYLOAD_FORMAT,
                    'type'     => 'VisualizationTarget',
                    'objectID' => $ObjectID
                ];
            }
            $payload = json_encode($action, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (isset($action['script'])) {
                sodium_memzero($action['script']);
            }
            if (isset($scriptText)) {
                sodium_memzero($scriptText);
            }
        } catch (JsonException $e) {
            if (isset($scriptText)) {
                sodium_memzero($scriptText);
            }
            sodium_memzero($PIN);
            return false;
        } catch (Throwable $e) {
            sodium_memzero($PIN);
            return false;
        }

        $this->EnsureKeySalt();
        $keys = $this->ActionKeys($PIN);
        $actions = $this->ReadActions();
        // Replace a record that an earlier version stored for the same code.
        $legacy = $this->FindLegacyAction($PIN, $actions);
        sodium_memzero($PIN);
        if ($keys === null) {
            sodium_memzero($payload);
            return false;
        }
        if ($legacy !== null) {
            sodium_memzero($legacy['plainText']);
            unset($actions[$legacy['recordID']]);
        }
        $actions[$keys['recordID']] = $this->EncryptRecord(
            $keys['key'], $payload, 'action:' . $keys['recordID']
        );
        sodium_memzero($keys['key']);
        sodium_memzero($payload);
        ksort($actions, SORT_STRING);
        $this->WriteActions($actions);
        $this->StoreCodeFingerprint();
        $this->LockRegistrationOptions();
        $this->UpdateSummary();
        $this->PublishStatus();
        return true;
    }

    private function RemoveAction(string $PIN): bool
    {
        $normalized = $this->NormalizeAccessCode($PIN, false);
        if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' || $this->ReregistrationRequired() ||
            $normalized === null) {
            return false;
        }
        sodium_memzero($PIN);
        $actions = $this->ReadActions();
        $match = $this->FindAction($normalized, $actions);
        sodium_memzero($normalized);
        if ($match === null) {
            return false;
        }
        sodium_memzero($match['plainText']);
        unset($actions[$match['recordID']]);
        $this->WriteActions($actions);
        $this->UpdateSummary();
        $this->PublishStatus();
        return true;
    }

    private function ProcessSubmission(mixed $Value): bool
    {
        if (!$this->InputAllowed()) {
            return false;
        }
        // Bounded, complete transport packet. The code is never published to clients or logs.
        if (!is_string($Value) || strlen($Value) > 1024) {
            return $this->RejectInput();
        }
        try {
            $packet = json_decode($Value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->RejectInput();
        }
        $code = is_array($packet) ? ($packet['Code'] ?? $packet['Pattern'] ?? null) : null;
        if (!is_string($code)) {
            return $this->RejectInput();
        }
        $requestID = is_string($packet['RequestID'] ?? null) &&
            preg_match('/^[A-Za-z0-9_-]{16,64}$/', $packet['RequestID']) === 1
            ? $packet['RequestID'] : null;
        $this->resultRequestID = $requestID;
        try {
            return $this->ProcessEntry($code, $requestID);
        } finally {
            if ($this->result !== null && $this->resultRequestID !== null) {
                $this->PublishResult($this->result, $this->resultRequestID);
            }
            $this->resultRequestID = null;
            $this->result = null;
        }
    }

    private function ProcessEntry(string $Code, ?string $RequestID): bool
    {
        if (!$this->InputAllowed()) {
            $this->PublishStatus();
            return false;
        }
        // An empty submission, e.g. a stray ENTER, is not an attempt.
        if ($Code === '') {
            $this->PublishStatus();
            return false;
        }
        $recovery = $this->ReadAttributeBoolean('RecoveryMode');
        $normalized = $this->NormalizeAccessCode($Code, $recovery);
        sodium_memzero($Code);
        if ($normalized === null) {
            return $this->RejectInput();
        }
        if ($recovery) {
            return $this->TryRecovery($normalized);
        }

        // Count the attempt before the expensive check: an aborted check must not be free.
        $attempts = $this->ReadAttributeInteger('FailedAttempts') + 1;
        $this->WriteAttributeInteger('FailedAttempts', $attempts);
        $match = $this->FindAction($normalized, $this->ReadActions());
        if ($match === null) {
            sodium_memzero($normalized);
            return $this->ApplyFailure($attempts);
        }
        $this->WriteAttributeInteger('FailedAttempts', 0);
        $this->WriteAttributeInteger('LockUntil', 0);
        $this->SetTimerInterval('LockTimer', 0);
        if ($match['legacy']) {
            $this->MigrateAction($normalized, $match['recordID'], $match['plainText']);
        }
        sodium_memzero($normalized);
        $result = $this->ExecutePayload($match['plainText'], $RequestID);
        sodium_memzero($match['plainText']);
        $this->SetTransientMessage($result['message']);
        $this->UpdateSummary();
        $this->PublishStatus();
        if ($result['target'] !== null && $RequestID !== null) {
            $this->PublishNavigation($result['target'], $RequestID);
        }
        if ($result['output'] !== null && $RequestID !== null) {
            $this->PublishOutput($result['output'], $RequestID);
        }
        return $result['success'];
    }

    private function TryRecovery(string $PIN): bool
    {
        // Count the attempt before the expensive check: an aborted check must not be free.
        $attempts = $this->ReadAttributeInteger('RecoveryFailedAttempts') + 1;
        $this->WriteAttributeInteger('RecoveryFailedAttempts', $attempts);
        $plainText = $this->DecryptRecovery($PIN);
        sodium_memzero($PIN);
        if ($plainText !== null) {
            try {
                $payload = json_decode($plainText, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $payload = [];
            }
            sodium_memzero($plainText);
            if (($payload['format'] ?? null) === self::PAYLOAD_FORMAT && ($payload['purpose'] ?? null) === 'recovery') {
                $this->WriteAttributeInteger('FailedAttempts', 0);
                $this->WriteAttributeInteger('LockUntil', 0);
                $this->WriteAttributeBoolean('PermanentlyLocked', false);
                $this->WriteAttributeBoolean('RecoveryMode', false);
                $this->WriteAttributeInteger('RecoveryFailedAttempts', 0);
                $this->SetTransientMessage('UNLOCKED');
                $this->UpdateSummary();
                $this->PublishStatus();
                return true;
            }
        }
        return $this->ApplyRecoveryFailure($attempts);
    }

    private function RejectInput(): bool
    {
        return $this->ReadAttributeBoolean('RecoveryMode') ? $this->RejectRecovery() : $this->HandleFailure();
    }

    private function RejectRecovery(): bool
    {
        $attempts = $this->ReadAttributeInteger('RecoveryFailedAttempts') + 1;
        $this->WriteAttributeInteger('RecoveryFailedAttempts', $attempts);
        return $this->ApplyRecoveryFailure($attempts);
    }

    private function ApplyRecoveryFailure(int $Attempts): bool
    {
        if ($Attempts >= $this->ReadPropertyInteger('RecoveryAttempts')) {
            $this->WriteAttributeBoolean('RecoveryMode', false);
            $this->ClearTransientMessage();
        } else {
            $this->SetTransientMessage('SUPER_PIN_INVALID');
        }
        $this->UpdateSummary();
        $this->PublishStatus();
        return false;
    }

    private function HandleFailure(): bool
    {
        $attempts = $this->ReadAttributeInteger('FailedAttempts') + 1;
        $this->WriteAttributeInteger('FailedAttempts', $attempts);
        return $this->ApplyFailure($attempts);
    }

    private function ApplyFailure(int $Attempts): bool
    {
        if ($Attempts >= $this->ReadPropertyInteger('PermanentLockAfter')) {
            $this->WriteAttributeBoolean('PermanentlyLocked', true);
            $this->WriteAttributeInteger('LockUntil', 0);
            $this->SetTimerInterval('LockTimer', 0);
            $this->ClearTransientMessage();
        } elseif ($Attempts >= $this->ReadPropertyInteger('DelayAfterFailures')) {
            $factor = $Attempts - $this->ReadPropertyInteger('DelayAfterFailures') + 1;
            $delay = $this->ReadPropertyInteger('BaseDelay') * $factor;
            $this->WriteAttributeInteger('LockUntil', time() + $delay);
            $this->SetTimerInterval('LockTimer', $delay * 1000);
            $this->ClearTransientMessage();
        } else {
            $this->SetTransientMessage('PIN_INVALID');
        }
        $this->UpdateSummary();
        $this->PublishStatus();
        return false;
    }

    private function ExecutePayload(string $PlainText, ?string $RequestID): array
    {
        $failed = ['success' => false, 'message' => 'ACTION_FAILED', 'target' => null, 'output' => null];
        try {
            $payload = json_decode($PlainText, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $failed;
        }
        if (($payload['format'] ?? null) !== self::PAYLOAD_FORMAT) {
            return $failed;
        }
        if (($payload['type'] ?? null) === 'VisualizationTarget') {
            $objectID = $payload['objectID'] ?? null;
            if (!is_int($objectID) || !IPS_ObjectExists($objectID)) {
                return $failed;
            }
            if ($RequestID === null) {
                return ['success' => false, 'message' => 'VISUALIZATION_REQUIRED', 'target' => null, 'output' => null];
            }
            return ['success' => true, 'message' => 'ACTION_EXECUTED', 'target' => $objectID, 'output' => null];
        }
        if (($payload['type'] ?? null) !== 'ScriptText' ||
            !is_string($payload['script'] ?? null) ||
            trim($payload['script']) === '' ||
            !is_array($payload['parameters'] ?? null)) {
            return $failed;
        }
        // The context is set last so that registered parameters cannot replace it.
        $parameters = array_merge($payload['parameters'], [
            'SENDER'   => 'ProtectedAction',
            'INSTANCE' => $this->InstanceID,
            'SELF'     => $this->InstanceID
        ]);
        try {
            if (($payload['output'] ?? false) === true) {
                // Runs synchronously; the instance accepts no other entry until the script ends.
                $output = $this->LimitOutput(IPS_RunScriptTextWaitEx($payload['script'], $parameters));
                return ['success' => true, 'message' => 'ACTION_EXECUTED', 'target' => null, 'output' => $output];
            }
            $executed = IPS_RunScriptTextEx($payload['script'], $parameters);
        } catch (Throwable $e) {
            $this->SendDebug('ExecuteAction', $e->getMessage(), 0);
            return $failed;
        }
        return [
            'success' => $executed,
            'message' => $executed ? 'ACTION_EXECUTED' : 'ACTION_FAILED',
            'target'  => null,
            'output'  => null
        ];
    }

    private function LimitOutput(string $Output): ?string
    {
        $output = trim(mb_scrub($Output, 'UTF-8'));
        if ($output === '') {
            return null;
        }
        return mb_strlen($output, 'UTF-8') > self::MAX_OUTPUT_LENGTH
            ? mb_substr($output, 0, self::MAX_OUTPUT_LENGTH - 1, 'UTF-8') . '…'
            : $output;
    }

    // Returns ['recordID' => string, 'plainText' => string, 'legacy' => bool] or null.
    private function FindAction(string $Code, array $Actions): ?array
    {
        if ($this->ReadAttributeString('KeySalt') !== '') {
            $keys = $this->ActionKeys($Code);
            if ($keys !== null) {
                $record = $Actions[$keys['recordID']] ?? null;
                $plainText = is_array($record)
                    ? $this->DecryptRecord($keys['key'], $record, 'action:' . $keys['recordID'])
                    : null;
                sodium_memzero($keys['key']);
                if ($plainText !== null) {
                    return ['recordID' => $keys['recordID'], 'plainText' => $plainText, 'legacy' => false];
                }
            }
        }
        return $this->FindLegacyAction($Code, $Actions);
    }

    private function FindLegacyAction(string $Code, array $Actions): ?array
    {
        foreach ($Actions as $recordID => $record) {
            if (!is_string($recordID) || !is_array($record) ||
                ($record['format'] ?? null) !== self::LEGACY_RECORD_FORMAT) {
                continue;
            }
            $plainText = $this->LegacyDecrypt($Code, $record, 'action:' . $recordID);
            if ($plainText !== null) {
                return ['recordID' => $recordID, 'plainText' => $plainText, 'legacy' => true];
            }
        }
        return null;
    }

    private function MigrateAction(string $Code, string $LegacyID, string $PlainText): void
    {
        $this->EnsureKeySalt();
        $keys = $this->ActionKeys($Code);
        if ($keys === null) {
            return;
        }
        $actions = $this->ReadActions();
        unset($actions[$LegacyID]);
        $actions[$keys['recordID']] = $this->EncryptRecord(
            $keys['key'], $PlainText, 'action:' . $keys['recordID']
        );
        sodium_memzero($keys['key']);
        ksort($actions, SORT_STRING);
        $this->WriteActions($actions);
    }

    private function StoreRecovery(string $PIN, string $Payload): void
    {
        $this->EnsureKeySalt();
        $key = $this->RecoveryKey($PIN);
        if ($key === null) {
            return;
        }
        $this->WriteAttributeString('Recovery', json_encode(
            $this->EncryptRecord($key, $Payload, 'recovery'),
            JSON_THROW_ON_ERROR
        ));
        sodium_memzero($key);
    }

    private function DecryptRecovery(string $PIN): ?string
    {
        $record = json_decode($this->ReadAttributeString('Recovery'), true);
        if (!is_array($record)) {
            return null;
        }
        if (($record['format'] ?? null) === self::RECORD_FORMAT) {
            $key = $this->RecoveryKey($PIN);
            if ($key === null) {
                return null;
            }
            $plainText = $this->DecryptRecord($key, $record, 'recovery');
            sodium_memzero($key);
            return $plainText;
        }
        $plainText = $this->LegacyDecrypt($PIN, $record, 'recovery');
        if ($plainText !== null) {
            $this->StoreRecovery($PIN, $plainText);
        }
        return $plainText;
    }

    // One Argon2 derivation per entry. The lookup subkey addresses the record directly,
    // so the cost of an entry does not grow with the number of registered actions.
    private function ActionKeys(string $Code): ?array
    {
        $master = $this->MasterKey($Code, $this->ReadPropertyInteger('InputMode') === 1 ? 'pattern' : 'pin');
        if ($master === null) {
            return null;
        }
        $lookup = sodium_crypto_kdf_derive_from_key(32, self::SUBKEY_LOOKUP, self::KDF_CONTEXT, $master);
        $key = sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::SUBKEY_ENCRYPTION, self::KDF_CONTEXT, $master
        );
        sodium_memzero($master);
        $recordID = bin2hex(sodium_crypto_generichash('record', $lookup, 16));
        sodium_memzero($lookup);
        return ['recordID' => $recordID, 'key' => $key];
    }

    private function RecoveryKey(string $PIN): ?string
    {
        $master = $this->MasterKey($PIN, 'super-pin');
        if ($master === null) {
            return null;
        }
        $key = sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::SUBKEY_ENCRYPTION, self::KDF_CONTEXT, $master
        );
        sodium_memzero($master);
        return $key;
    }

    private function MasterKey(string $Code, string $Kind): ?string
    {
        $salt = base64_decode($this->ReadAttributeString('KeySalt'), true);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            return null;
        }
        // Update-resistant records deliberately exclude module contents, version, build,
        // filename, instance ID and filesystem path. Format/KDF compatibility must remain stable.
        $binding = $this->ReadPropertyBoolean('BindToModuleCode')
            ? 'code-bound' . chr(0) . $this->CodeHash()
            : 'update-independent';
        $material = 'ProtectedAction-Key-' . self::RECORD_FORMAT . chr(0) . self::KDF_VERSION . chr(0) .
            $Kind . chr(0) . $Code . chr(0) . $binding;
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_KDF_KEYBYTES,
            $material,
            $salt,
            self::KDF_OPSLIMIT,
            self::KDF_MEMLIMIT,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        sodium_memzero($material);
        return $key;
    }

    private function EnsureKeySalt(): void
    {
        if ($this->ReadAttributeString('KeySalt') === '') {
            $this->WriteAttributeString('KeySalt', base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)));
        }
    }

    private function EncryptRecord(string $Key, string $PlainText, string $Purpose): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipherText = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $PlainText, $this->AssociatedData($Purpose), $nonce, $Key
        );
        return [
            'format' => self::RECORD_FORMAT,
            'codeBound' => $this->ReadPropertyBoolean('BindToModuleCode'),
            'nonce' => base64_encode($nonce),
            'cipherText' => base64_encode($cipherText)
        ];
    }

    private function DecryptRecord(string $Key, array $Record, string $Purpose): ?string
    {
        if (($Record['format'] ?? null) !== self::RECORD_FORMAT ||
            ($Record['codeBound'] ?? null) !== $this->ReadPropertyBoolean('BindToModuleCode')) {
            return null;
        }
        $nonce = base64_decode((string) ($Record['nonce'] ?? ''), true);
        $cipherText = base64_decode((string) ($Record['cipherText'] ?? ''), true);
        if ($nonce === false || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ||
            $cipherText === false) {
            return null;
        }
        $plainText = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipherText, $this->AssociatedData($Purpose), $nonce, $Key
        );
        return $plainText === false ? null : $plainText;
    }

    private function AssociatedData(string $Purpose): string
    {
        return self::MODULE_ID . chr(0) . self::RECORD_FORMAT . chr(0) .
            self::KDF_VERSION . chr(0) . $Purpose . chr(0) .
            (int) $this->ReadPropertyBoolean('BindToModuleCode');
    }

    // Reads records written up to version 1.1 (one Argon2 derivation per record).
    // Must stay byte-compatible with that version; do not change.
    private function LegacyDecrypt(string $PIN, array $Record, string $Purpose): ?string
    {
        if (($Record['format'] ?? null) !== self::LEGACY_RECORD_FORMAT ||
            ($Record['kdf'] ?? null) !== self::KDF_VERSION ||
            ($Record['codeBound'] ?? null) !== $this->ReadPropertyBoolean('BindToModuleCode')) {
            return null;
        }
        $salt = base64_decode((string) ($Record['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($Record['nonce'] ?? ''), true);
        $cipherText = base64_decode((string) ($Record['cipherText'] ?? ''), true);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES ||
            $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ||
            $cipherText === false) {
            return null;
        }
        $kind = $Purpose === 'recovery' ? 'super-pin' :
            ($this->ReadPropertyInteger('InputMode') === 1 ? 'pattern' : 'pin');
        $binding = $this->ReadPropertyBoolean('BindToModuleCode')
            ? 'code-bound' . chr(0) . $this->CodeHash()
            : 'update-independent';
        $material = 'ProtectedAction-KDF-' . self::KDF_VERSION . chr(0) .
            $kind . chr(0) . $PIN . chr(0) . $binding;
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $material,
            $salt,
            self::KDF_OPSLIMIT,
            self::KDF_MEMLIMIT,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        sodium_memzero($material);
        $associatedData = self::MODULE_ID . chr(0) . self::LEGACY_RECORD_FORMAT . chr(0) .
            self::KDF_VERSION . chr(0) . $Purpose . chr(0) .
            (int) $this->ReadPropertyBoolean('BindToModuleCode');
        $plainText = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipherText, $associatedData, $nonce, $key
        );
        sodium_memzero($key);
        return $plainText === false ? null : $plainText;
    }

    private function CodeHash(): string
    {
        $moduleHash = hash_file('sha256', __FILE__);
        $tileHash = hash_file('sha256', __DIR__ . '/module.html');
        if ($moduleHash === false || $tileHash === false) {
            throw new RuntimeException('Unable to calculate module hash');
        }
        return hash('sha256', $moduleHash . chr(0) . $tileHash);
    }

    // Not secret: it only detects that code-bound records can no longer be decrypted.
    private function CodeFingerprint(): string
    {
        return hash('sha256', 'ProtectedAction-CodeFingerprint' . chr(0) . $this->CodeHash());
    }

    private function StoreCodeFingerprint(): void
    {
        if ($this->ReadPropertyBoolean('BindToModuleCode')) {
            $this->WriteAttributeString('CodeFingerprint', $this->CodeFingerprint());
        }
    }

    private function ReregistrationRequired(): bool
    {
        if (!$this->ReadPropertyBoolean('BindToModuleCode') || !$this->HasRegistrations()) {
            return false;
        }
        // Registrations from versions without a fingerprint were bound to different module code.
        $fingerprint = $this->ReadAttributeString('CodeFingerprint');
        return $fingerprint === '' || !hash_equals($fingerprint, $this->CodeFingerprint());
    }

    private function ValidPIN(string $PIN): bool
    {
        return preg_match('/^[0-9]+$/D', $PIN) === 1 &&
            strlen($PIN) >= self::MIN_PIN_LENGTH && strlen($PIN) <= self::MAX_CODE_LENGTH;
    }

    private function NormalizeAccessCode(string $Code, bool $Recovery): ?string
    {
        if ($Recovery || $this->ReadPropertyInteger('InputMode') === 0) {
            return $this->ValidPIN($Code) ? $Code : null;
        }
        $maximum = self::MAX_CODE_LENGTH;
        if (strlen($Code) > $maximum || preg_match('/^[1-9]+$/D', $Code) !== 1 ||
            preg_match('/(.)\\1/', $Code) === 1) {
            return null;
        }
        $normalized = $Code[0];
        for ($i = 1; $i < strlen($Code); $i++) {
            $from = (int) $Code[$i - 1] - 1;
            $to = (int) $Code[$i] - 1;
            $x = $from % 3;
            $y = intdiv($from, 3);
            $dx = ($to % 3) - $x;
            $dy = intdiv($to, 3) - $y;
            // Only horizontal, vertical and exact diagonal two-cell moves have a midpoint.
            if ((abs($dx) === 2 && ($dy === 0 || abs($dy) === 2)) ||
                (abs($dy) === 2 && $dx === 0)) {
                $normalized .= (string) (1 + (int) ($x + $dx / 2) + 3 * (int) ($y + $dy / 2));
            }
            $normalized .= $Code[$i];
            if (strlen($normalized) > $maximum) {
                return null;
            }
        }
        return strlen($normalized) >= self::MIN_PATTERN_LENGTH ? $normalized : null;
    }

    private function HasRegistrations(): bool
    {
        return $this->ReadAttributeString('Recovery') !== '' || $this->ReadActions() !== [];
    }

    private function ModeIsLocked(): bool
    {
        return $this->ReadAttributeInteger('RegisteredInputMode') !== -1 ||
            $this->ReadAttributeInteger('RegisteredCodeBinding') !== -1 || $this->HasRegistrations();
    }

    private function LockRegistrationOptions(): void
    {
        $this->WriteAttributeInteger('RegisteredInputMode', $this->ReadPropertyInteger('InputMode'));
        $this->WriteAttributeInteger('RegisteredCodeBinding', (int) $this->ReadPropertyBoolean('BindToModuleCode'));
        $this->ReloadForm();
    }

    private function ConfigurationError(): string
    {
        $mode = $this->ReadPropertyInteger('InputMode');
        if (!in_array($mode, [0, 1], true)) {
            return $this->Translate('Invalid input mode');
        }
        $registeredMode = $this->ReadAttributeInteger('RegisteredInputMode');
        if ($registeredMode === -1 && $this->HasRegistrations()) {
            $registeredMode = 0;
        }
        if ($registeredMode !== -1 && $registeredMode !== $mode) {
            return $this->Translate('Input mode is locked. Restore the registered mode or reset all registrations.');
        }
        $registeredBinding = $this->ReadAttributeInteger('RegisteredCodeBinding');
        if ($registeredBinding === -1 && $this->HasRegistrations()) {
            $registeredBinding = 1;
        }
        if ($registeredBinding !== -1 &&
            $registeredBinding !== (int) $this->ReadPropertyBoolean('BindToModuleCode')) {
            return $this->Translate('Code binding is locked. Restore the registered setting or reset all registrations.');
        }
        return '';
    }

    private function StateError(): string
    {
        $error = $this->ConfigurationError();
        if ($error === '' && $this->ReregistrationRequired()) {
            $error = $this->Translate('Module code changed. Reset the instance and register all actions and the Super PIN again.');
        }
        return $error;
    }

    private function InputAllowed(): bool
    {
        if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' || $this->ReregistrationRequired()) {
            return false;
        }
        if ($this->ReadAttributeBoolean('PermanentlyLocked')) {
            return $this->ReadAttributeBoolean('RecoveryMode');
        }
        return time() >= $this->ReadAttributeInteger('LockUntil');
    }

    private function ExpireInput(): bool
    {
        $inputTime = (int) $this->GetBuffer(self::INPUT_TIME_BUFFER);
        if ($inputTime <= 0 || time() - $inputTime <= $this->ReadPropertyInteger('InputTimeout')) {
            return false;
        }
        $this->ClearInput();
        return true;
    }

    private function ClearInput(): void
    {
        $input = $this->GetBuffer(self::INPUT_BUFFER);
        if ($input !== '') {
            sodium_memzero($input);
        }
        $this->SetBuffer(self::INPUT_BUFFER, '');
        $this->SetBuffer(self::INPUT_TIME_BUFFER, '0');
        $this->SetTimerInterval('InputTimer', 0);
    }

    private function SetTransientMessage(string $Message): void
    {
        if (!in_array($Message, self::MESSAGE_CODES, true)) {
            throw new InvalidArgumentException('Unknown message code');
        }
        $this->SetBuffer(self::MESSAGE_BUFFER, $Message);
        $this->SetTimerInterval('MessageTimer', $this->ReadPropertyInteger('MessageDuration') * 1000);
        if ($this->resultRequestID !== null) {
            $this->result = $Message;
        }
    }

    // Transient messages appear in the Status variable and GetState() for custom controls.
    // Tiles receive them only through PublishResult().
    private function ClearTransientMessage(): void
    {
        $this->SetBuffer(self::MESSAGE_BUFFER, '');
        $this->SetTimerInterval('MessageTimer', 0);
        $this->result = null;
    }

    private function ReadActions(): array
    {
        try {
            $actions = json_decode($this->ReadAttributeString('Actions'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }
        return is_array($actions) ? $actions : [];
    }

    private function WriteActions(array $Actions): void
    {
        $this->WriteAttributeString('Actions', json_encode(
            $Actions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
    }

    private function CryptoAvailable(): bool
    {
        return function_exists('sodium_crypto_pwhash') &&
            function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') &&
            function_exists('sodium_crypto_kdf_derive_from_key');
    }

    private function UpdateSummary(): void
    {
        if ($this->ReregistrationRequired()) {
            $this->SetSummary($this->Translate('Re-registration required'));
        } elseif ($this->ReadAttributeBoolean('RecoveryMode')) {
            $this->SetSummary($this->Translate('Recovery mode'));
        } elseif ($this->ReadAttributeBoolean('PermanentlyLocked')) {
            $this->SetSummary($this->Translate('Permanently locked'));
        } elseif (time() < $this->ReadAttributeInteger('LockUntil')) {
            $this->SetSummary($this->Translate('Temporarily locked'));
        } else {
            $this->SetSummary(sprintf($this->Translate('Protected actions: %d'), count($this->ReadActions())));
        }
    }

    private function PublishStatus(): void
    {
        $this->SetValue('Status', json_encode($this->GetStatusData(true), JSON_THROW_ON_ERROR));
        $this->UpdateVisualizationValue(json_encode($this->GetTileData(), JSON_THROW_ON_ERROR));
    }

    // Sent to all clients like navigation; only the client with this request ID shows it.
    private function PublishResult(string $Message, string $RequestID): void
    {
        $this->UpdateVisualizationValue(json_encode([
            'Result' => [
                'RequestID' => $RequestID,
                'Message'   => $Message
            ]
        ], JSON_THROW_ON_ERROR));
    }

    private function PublishNavigation(int $ObjectID, string $RequestID): void
    {
        $this->UpdateVisualizationValue(json_encode([
            'Navigation' => [
                'RequestID' => $RequestID,
                'ObjectID'  => $ObjectID
            ]
        ], JSON_THROW_ON_ERROR));
    }

    // Sent to all clients like navigation; only the client with this request ID shows it.
    private function PublishOutput(string $Output, string $RequestID): void
    {
        $this->UpdateVisualizationValue(json_encode([
            'Output' => [
                'RequestID' => $RequestID,
                'Text'      => $Output
            ]
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function GetStatusData(bool $IncludeTransient): array
    {
        $lockUntil = $this->ReadAttributeInteger('LockUntil');
        $recoveryMode = $this->ReadAttributeBoolean('RecoveryMode');
        $permanentlyLocked = $this->ReadAttributeBoolean('PermanentlyLocked');
        $inputActive = $this->GetBuffer(self::INPUT_BUFFER) !== '';
        $transientMessage = $IncludeTransient ? $this->GetBuffer(self::MESSAGE_BUFFER) : '';
        if ($this->ReregistrationRequired()) {
            $message = 'REREGISTRATION_REQUIRED';
        } elseif ($permanentlyLocked && !$recoveryMode) {
            $message = 'PERMANENTLY_LOCKED';
        } elseif (time() < $lockUntil) {
            $message = 'TEMPORARILY_LOCKED';
        } elseif ($inputActive) {
            $message = 'INPUT_ACTIVE';
        } elseif ($transientMessage !== '') {
            $message = $transientMessage;
        } elseif ($recoveryMode) {
            $message = 'RECOVERY_ACTIVE';
        } else {
            $message = 'READY';
        }
        return [
            'Message' => $message,
            'InputActive' => $inputActive,
            'Locked' => !$this->InputAllowed(),
            'LockUntil' => $lockUntil,
            'PermanentlyLocked' => $permanentlyLocked,
            'RecoveryMode' => $recoveryMode,
            'ServerTime' => time()
        ];
    }

    private function GetPresentationData(): array
    {
        return [
            'InputMode' => $this->ReadPropertyInteger('InputMode'),
            'MinimumPatternLength' => self::MIN_PATTERN_LENGTH,
            'MaximumPatternLength' => self::MAX_CODE_LENGTH,
            'InputTimeout' => $this->ReadPropertyInteger('InputTimeout'),
            'MessageDuration' => $this->ReadPropertyInteger('MessageDuration'),
            'Scramble' => $this->ReadPropertyBoolean('ScrambleKeypad'),
            'ScrambleInterval' => $this->ReadPropertyInteger('ScrambleInterval'),
            // Only normal tiles (type 1) overlap the title; type 2 already excludes it.
            'ContentMargins' => $this->VisualizationType() === 1,
            'KeypadStyle' => $this->ReadPropertyInteger('KeypadStyle'),
            'KeyLabels' => $this->ReadPropertyInteger('KeyLabels'),
            'PatternTrail' => $this->ReadPropertyInteger('PatternTrail')
        ];
    }

    private function GetTileData(): array
    {
        return array_merge($this->GetStatusData(false), $this->GetPresentationData());
    }
}
