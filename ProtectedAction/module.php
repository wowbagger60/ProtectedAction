<?php

declare(strict_types=1);

class ProtectedAction extends IPSModuleStrict
{
    private const FORMAT_VERSION = 6;
    // Keep these values and their semantics stable across compatible module updates.
    private const KDF_VERSION = 1;
    private const KDF_OPSLIMIT = 2;
    private const KDF_MEMLIMIT = 67108864; // 64 MiB; part of KDF version 1
    private const MIN_PIN_LENGTH = 4;
    private const MIN_PATTERN_LENGTH = 2;
    private const MAX_CODE_LENGTH = 16;
    private const MODULE_ID = '{84C928B5-BB9D-4E25-8FC4-574B1F2B945E}';
    private const INPUT_BUFFER = 'Input';
    private const INPUT_TIME_BUFFER = 'InputTime';
    private const MESSAGE_BUFFER = 'Message';
    private const MESSAGE_CODES = [
        'READY', 'INPUT_ACTIVE', 'PIN_INVALID', 'ACTION_EXECUTED', 'ACTION_FAILED',
        'TEMPORARILY_LOCKED', 'PERMANENTLY_LOCKED', 'RECOVERY_ACTIVE',
        'SUPER_PIN_INVALID', 'UNLOCKED', 'VISUALIZATION_REQUIRED'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

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

        $this->RegisterAttributeInteger('RegisteredInputMode', -1);
        $this->RegisterAttributeInteger('RegisteredCodeBinding', -1);
        $this->RegisterAttributeString('Actions', '[]');
        $this->RegisterAttributeString('Recovery', '');
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
        $this->SetStatus(102);
        $this->UpdateSummary();
        $this->PublishStatus();
        $this->ReloadForm();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $mode = $this->ReadPropertyInteger('InputMode');
        foreach ($form['elements'] as &$element) {
            switch ($element['name'] ?? '') {
                case 'InputMode':
                case 'BindToModuleCode':
                    $element['enabled'] = !$this->ModeIsLocked();
                    break;
                case 'ScrambleKeypad':
                case 'ScrambleInterval':
                    $element['visible'] = $mode === 0;
                    break;
                case 'ConfigurationError':
                    $element['caption'] = $this->ConfigurationError();
                    $element['visible'] = $element['caption'] !== '';
                    break;
            }
        }
        unset($element);
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'InputModePreview':
                if (!$this->ModeIsLocked()) {
                    $pattern = (int) $Value === 1;
                    $this->UpdateFormField('ScrambleKeypad', 'visible', !$pattern);
                    $this->UpdateFormField('ScrambleInterval', 'visible', !$pattern);
                }
                break;
            case 'Pattern':
                $this->ProcessPattern($Value);
                break;
            case 'Digit':
                $this->InputDigit((int) $Value);
                break;
            case 'Clear':
                $this->Clear();
                break;
            case 'Enter':
                $requestID = is_string($Value) && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $Value) === 1
                    ? $Value
                    : null;
                $this->ProcessEntry($requestID);
                break;
            case 'Refresh':
                $this->ExpireInput();
                $this->PublishStatus();
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
        $normalized = $this->NormalizeAccessCode($PIN, false);
        if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' ||
            $normalized === null || !IPS_ObjectExists($ObjectID)) {
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
                    'format'     => self::FORMAT_VERSION,
                    'type'       => 'ScriptText',
                    'script'     => $scriptText,
                    'parameters' => $Parameters
                ];
            } else {
                if ($Parameters !== []) {
                    sodium_memzero($PIN);
                    return false;
                }
                $action = [
                    'format'   => self::FORMAT_VERSION,
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

        $actions = $this->ReadActions();
        $recordID = $this->FindActionByPIN($PIN, $actions) ?? bin2hex(random_bytes(16));
        $actions[$recordID] = $this->Encrypt($PIN, $payload, 'action:' . $recordID);
        sodium_memzero($payload);
        ksort($actions, SORT_STRING);
        $this->WriteActions($actions);
        $this->LockRegistrationOptions();
        sodium_memzero($PIN);
        $this->UpdateSummary();
        $this->PublishStatus();
        return true;
    }

    public function Unregister(string $PIN): bool
    {
        $normalized = $this->NormalizeAccessCode($PIN, false);
        if ($this->ConfigurationError() !== '' || $normalized === null) {
            return false;
        }
        sodium_memzero($PIN);
        $PIN = $normalized;
        sodium_memzero($normalized);
        $actions = $this->ReadActions();
        $recordID = $this->FindActionByPIN($PIN, $actions);
        sodium_memzero($PIN);
        if ($recordID === null) {
            return false;
        }
        unset($actions[$recordID]);
        $this->WriteActions($actions);
        $this->UpdateSummary();
        $this->PublishStatus();
        return true;
    }

    public function RegisterSuperPIN(string $PIN): bool
    {
        if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '' || !$this->ValidPIN($PIN)) {
            return false;
        }
        $payload = json_encode(['format' => self::FORMAT_VERSION, 'purpose' => 'recovery'], JSON_THROW_ON_ERROR);
        $this->WriteAttributeString('Recovery', json_encode(
            $this->Encrypt($PIN, $payload, 'recovery'),
            JSON_THROW_ON_ERROR
        ));
        sodium_memzero($PIN);
        $this->LockRegistrationOptions();
        $this->PublishStatus();
        return true;
    }

    public function InputDigit(int $Digit): bool
    {
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
        $maximum = self::MAX_CODE_LENGTH;
        if (strlen($input) >= $maximum) {
            return false;
        }
        $this->ClearTransientMessage();
        $this->SetBuffer(self::INPUT_BUFFER, $input . (string) $Digit);
        $this->SetBuffer(self::INPUT_TIME_BUFFER, (string) time());
        $this->SetTimerInterval('InputTimer', $this->ReadPropertyInteger('InputTimeout') * 1000);
        $this->PublishStatus();
        return true;
    }

    public function Clear(): void
    {
        $this->ClearInput();
        $this->ClearTransientMessage();
        $this->PublishStatus();
    }

    public function Enter(): bool
    {
        return $this->ProcessEntry(null);
    }

    private function ProcessEntry(?string $RequestID): bool
    {
        if (!$this->InputAllowed()) {
            $this->ClearInput();
            $this->PublishStatus();
            return false;
        }
        $this->ExpireInput();
        $pin = $this->GetBuffer(self::INPUT_BUFFER);
        $this->ClearInput();
        $recovery = $this->ReadAttributeBoolean('RecoveryMode');
        $normalized = $this->NormalizeAccessCode($pin, $recovery);
        if ($normalized === null) {
            sodium_memzero($pin);
            return $recovery ? $this->TryRecovery('') : $this->HandleFailure();
        }
        sodium_memzero($pin);
        $pin = $normalized;
        sodium_memzero($normalized);
        if ($recovery) {
            return $this->TryRecovery($pin);
        }

        foreach ($this->ReadActions() as $recordID => $record) {
            $plainText = $this->Decrypt($pin, $record, 'action:' . $recordID);
            if ($plainText === null) {
                continue;
            }
            $this->WriteAttributeInteger('FailedAttempts', 0);
            $this->WriteAttributeInteger('LockUntil', 0);
            $this->SetTimerInterval('LockTimer', 0);
            sodium_memzero($pin);
            $result = $this->ExecutePayload($plainText, $RequestID);
            sodium_memzero($plainText);
            $this->SetTransientMessage($result['message']);
            $this->UpdateSummary();
            $this->PublishStatus();
            if ($result['target'] !== null && $RequestID !== null) {
                $this->PublishNavigation($result['target'], $RequestID);
            }
            return $result['success'];
        }
        sodium_memzero($pin);
        return $this->HandleFailure();
    }

    private function ProcessPattern(mixed $Value): bool
    {
        if ($this->ReadPropertyInteger('InputMode') !== 1 ||
            $this->ReadAttributeBoolean('RecoveryMode') || !$this->InputAllowed()) {
            return false;
        }
        $this->ClearInput();
        // Bounded, complete transport packet. No point sequence is published to clients or logs.
        if (!is_string($Value) || strlen($Value) > 1024) {
            return $this->HandleFailure();
        }
        try {
            $packet = json_decode($Value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->HandleFailure();
        }
        if (!is_array($packet) || !is_string($packet['Pattern'] ?? null)) {
            return $this->HandleFailure();
        }
        $pattern = $packet['Pattern'];
        if ($this->NormalizeAccessCode($pattern, false) === null) {
            sodium_memzero($pattern);
            return $this->HandleFailure();
        }
        $requestID = is_string($packet['RequestID'] ?? null) &&
            preg_match('/^[A-Za-z0-9_-]{16,64}$/', $packet['RequestID']) === 1
            ? $packet['RequestID'] : null;
        $this->SetBuffer(self::INPUT_BUFFER, $pattern);
        $this->SetBuffer(self::INPUT_TIME_BUFFER, (string) time());
        sodium_memzero($pattern);
        return $this->ProcessEntry($requestID);
    }

    public function BeginRecovery(): bool
    {
        if (!$this->ReadAttributeBoolean('PermanentlyLocked') || $this->ReadAttributeString('Recovery') === '') {
            return false;
        }
        $this->WriteAttributeBoolean('RecoveryMode', true);
        $this->WriteAttributeInteger('RecoveryFailedAttempts', 0);
        $this->ClearInput();
        $this->ClearTransientMessage();
        $this->UpdateSummary();
        $this->PublishStatus();
        return true;
    }

    public function CancelRecovery(): void
    {
        $this->WriteAttributeBoolean('RecoveryMode', false);
        $this->WriteAttributeInteger('RecoveryFailedAttempts', 0);
        $this->ClearInput();
        $this->ClearTransientMessage();
        $this->UpdateSummary();
        $this->PublishStatus();
    }

    public function Reset(): void
    {
        $this->WriteAttributeString('Actions', '[]');
        $this->WriteAttributeString('Recovery', '');
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
    }

    public function GetState(): string
    {
        return json_encode(array_merge($this->GetStatusData(), [
            'RegisteredActions' => count($this->ReadActions()),
            'FailedAttempts'    => $this->ReadAttributeInteger('FailedAttempts'),
            'RecoveryDefined'   => $this->ReadAttributeString('Recovery') !== '',
            'InputMode'         => $this->ReadPropertyInteger('InputMode'),
            'InputModeLocked'   => $this->ModeIsLocked(),
            'CodeBound'         => $this->ReadPropertyBoolean('BindToModuleCode'),
            'CodeBindingLocked' => $this->ModeIsLocked()
        ]), JSON_THROW_ON_ERROR);
    }

    public function InternalTimer(string $Timer): void
    {
        switch ($Timer) {
            case 'Message':
                $this->ClearTransientMessage();
                break;
            case 'Input':
                $this->ClearInput();
                break;
            case 'Lock':
                $this->WriteAttributeInteger('LockUntil', 0);
                $this->SetTimerInterval('LockTimer', 0);
                $this->UpdateSummary();
                break;
            default:
                return;
        }
        $this->PublishStatus();
    }

    private function TryRecovery(string $PIN): bool
    {
        $record = json_decode($this->ReadAttributeString('Recovery'), true);
        $plainText = is_array($record) ? $this->Decrypt($PIN, $record, 'recovery') : null;
        sodium_memzero($PIN);
        if ($plainText !== null) {
            try {
                $payload = json_decode($plainText, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $payload = [];
            }
            sodium_memzero($plainText);
            if (($payload['format'] ?? null) === self::FORMAT_VERSION && ($payload['purpose'] ?? null) === 'recovery') {
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

        $attempts = $this->ReadAttributeInteger('RecoveryFailedAttempts') + 1;
        $this->WriteAttributeInteger('RecoveryFailedAttempts', $attempts);
        if ($attempts >= $this->ReadPropertyInteger('RecoveryAttempts')) {
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
        if ($attempts >= $this->ReadPropertyInteger('PermanentLockAfter')) {
            $this->WriteAttributeBoolean('PermanentlyLocked', true);
            $this->WriteAttributeInteger('LockUntil', 0);
            $this->SetTimerInterval('LockTimer', 0);
            $this->ClearTransientMessage();
        } elseif ($attempts >= $this->ReadPropertyInteger('DelayAfterFailures')) {
            $factor = $attempts - $this->ReadPropertyInteger('DelayAfterFailures') + 1;
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
        try {
            $payload = json_decode($PlainText, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['success' => false, 'message' => 'ACTION_FAILED', 'target' => null];
        }
        if (($payload['format'] ?? null) !== self::FORMAT_VERSION) {
            return ['success' => false, 'message' => 'ACTION_FAILED', 'target' => null];
        }
        if (($payload['type'] ?? null) === 'VisualizationTarget') {
            $objectID = $payload['objectID'] ?? null;
            if (!is_int($objectID) || !IPS_ObjectExists($objectID)) {
                return ['success' => false, 'message' => 'ACTION_FAILED', 'target' => null];
            }
            if ($RequestID === null) {
                return ['success' => false, 'message' => 'VISUALIZATION_REQUIRED', 'target' => null];
            }
            return ['success' => true, 'message' => 'ACTION_EXECUTED', 'target' => $objectID];
        }
        if (($payload['type'] ?? null) !== 'ScriptText' ||
            !is_string($payload['script'] ?? null) ||
            trim($payload['script']) === '' ||
            !is_array($payload['parameters'] ?? null)) {
            return ['success' => false, 'message' => 'ACTION_FAILED', 'target' => null];
        }
        try {
            $executed = IPS_RunScriptTextEx($payload['script'], $payload['parameters']);
        } catch (Throwable $e) {
            $this->SendDebug('ExecuteAction', $e->getMessage(), 0);
            return ['success' => false, 'message' => 'ACTION_FAILED', 'target' => null];
        }
        return ['success' => $executed, 'message' => $executed ? 'ACTION_EXECUTED' : 'ACTION_FAILED', 'target' => null];
    }

    private function FindActionByPIN(string $PIN, array $Actions): ?string
    {
        foreach ($Actions as $recordID => $record) {
            if (!is_string($recordID) || !is_array($record)) {
                continue;
            }
            $plainText = $this->Decrypt($PIN, $record, 'action:' . $recordID);
            if ($plainText !== null) {
                sodium_memzero($plainText);
                return $recordID;
            }
        }
        return null;
    }

    private function Encrypt(string $PIN, string $PlainText, string $Purpose): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = $this->DeriveKey($PIN, $salt, $Purpose);
        $cipherText = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $PlainText, $this->AssociatedData($Purpose), $nonce, $key
        );
        sodium_memzero($key);
        return [
            'format' => self::FORMAT_VERSION,
            'kdf' => self::KDF_VERSION,
            'codeBound' => $this->ReadPropertyBoolean('BindToModuleCode'),
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'cipherText' => base64_encode($cipherText)
        ];
    }

    private function Decrypt(string $PIN, array $Record, string $Purpose): ?string
    {
        if (($Record['format'] ?? null) !== self::FORMAT_VERSION ||
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
        $key = $this->DeriveKey($PIN, $salt, $Purpose);
        $plainText = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipherText, $this->AssociatedData($Purpose), $nonce, $key
        );
        sodium_memzero($key);
        return $plainText === false ? null : $plainText;
    }

    private function DeriveKey(string $PIN, string $Salt, string $Purpose): string
    {
        $kind = $Purpose === 'recovery' ? 'super-pin' :
            ($this->ReadPropertyInteger('InputMode') === 1 ? 'pattern' : 'pin');
        // Update-resistant records deliberately exclude module contents, version, build,
        // filename, instance ID and filesystem path. Format/KDF compatibility must remain stable.
        $binding = $this->ReadPropertyBoolean('BindToModuleCode')
            ? 'code-bound' . chr(0) . $this->CodeHash()
            : 'update-independent';
        $material = 'ProtectedAction-KDF-' . self::KDF_VERSION . chr(0) .
            $kind . chr(0) . $PIN . chr(0) . $binding;
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $material,
            $Salt,
            self::KDF_OPSLIMIT,
            self::KDF_MEMLIMIT,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        sodium_memzero($material);
        return $key;
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

    private function AssociatedData(string $Purpose): string
    {
        return self::MODULE_ID . chr(0) . self::FORMAT_VERSION . chr(0) .
            self::KDF_VERSION . chr(0) . $Purpose . chr(0) .
            (int) $this->ReadPropertyBoolean('BindToModuleCode');
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

    private function InputAllowed(): bool
    {
        if (!$this->CryptoAvailable() || $this->ConfigurationError() !== '') {
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
    }

    private function ClearTransientMessage(): void
    {
        $this->SetBuffer(self::MESSAGE_BUFFER, '');
        $this->SetTimerInterval('MessageTimer', 0);
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
            function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    private function UpdateSummary(): void
    {
        if ($this->ReadAttributeBoolean('RecoveryMode')) {
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
        $status = $this->GetStatusData();
        $this->SetValue('Status', json_encode($status, JSON_THROW_ON_ERROR));
        $this->UpdateVisualizationValue(json_encode(
            array_merge($status, $this->GetPresentationData()), JSON_THROW_ON_ERROR
        ));
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

    private function GetStatusData(): array
    {
        $lockUntil = $this->ReadAttributeInteger('LockUntil');
        $recoveryMode = $this->ReadAttributeBoolean('RecoveryMode');
        $permanentlyLocked = $this->ReadAttributeBoolean('PermanentlyLocked');
        $inputActive = $this->GetBuffer(self::INPUT_BUFFER) !== '';
        $transientMessage = $this->GetBuffer(self::MESSAGE_BUFFER);
        if ($permanentlyLocked && !$recoveryMode) {
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
            'MaximumPatternLength' => self::MAX_CODE_LENGTH,
            'InputTimeout' => $this->ReadPropertyInteger('InputTimeout'),
            'Scramble' => $this->ReadPropertyBoolean('ScrambleKeypad'),
            'ScrambleInterval' => $this->ReadPropertyInteger('ScrambleInterval')
        ];
    }

    private function GetTileData(): array
    {
        return array_merge($this->GetStatusData(), $this->GetPresentationData());
    }
}
