<?php

declare(strict_types=1);

class ProtectedAction extends IPSModuleStrict
{
    private const FORMAT_VERSION = 4;
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

        $this->RegisterPropertyInteger('MinimumPINLength', 4);
        $this->RegisterPropertyInteger('MaximumPINLength', 16);
        $this->RegisterPropertyInteger('DelayAfterFailures', 3);
        $this->RegisterPropertyInteger('BaseDelay', 20);
        $this->RegisterPropertyInteger('PermanentLockAfter', 5);
        $this->RegisterPropertyInteger('InputTimeout', 20);
        $this->RegisterPropertyInteger('RecoveryAttempts', 3);
        $this->RegisterPropertyInteger('MessageDuration', 3);
        $this->RegisterPropertyBoolean('ScrambleKeypad', false);
        $this->RegisterPropertyInteger('ScrambleInterval', 0);

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
            $this->SetSummary('Sodium extension is not available');
            return;
        }
        if ($this->ReadPropertyInteger('MinimumPINLength') > $this->ReadPropertyInteger('MaximumPINLength')) {
            $this->SetStatus(202);
            $this->SetSummary('Invalid PIN length configuration');
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
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
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
        if (!$this->CryptoAvailable() || !$this->ValidPIN($PIN) || !IPS_ObjectExists($ObjectID)) {
            return false;
        }
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
        sodium_memzero($PIN);
        $this->UpdateSummary();
        $this->PublishStatus();
        return true;
    }

    public function Unregister(string $PIN): bool
    {
        if (!$this->ValidPIN($PIN)) {
            return false;
        }
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
        if (!$this->CryptoAvailable() || !$this->ValidPIN($PIN)) {
            return false;
        }
        $payload = json_encode(['format' => self::FORMAT_VERSION, 'purpose' => 'recovery'], JSON_THROW_ON_ERROR);
        $this->WriteAttributeString('Recovery', json_encode(
            $this->Encrypt($PIN, $payload, 'recovery'),
            JSON_THROW_ON_ERROR
        ));
        sodium_memzero($PIN);
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
        if (strlen($input) >= $this->ReadPropertyInteger('MaximumPINLength')) {
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
        if (!$this->ValidPIN($pin)) {
            return $this->HandleFailure();
        }
        if ($this->ReadAttributeBoolean('RecoveryMode')) {
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
    }

    public function GetState(): string
    {
        return json_encode(array_merge($this->GetStatusData(), [
            'RegisteredActions' => count($this->ReadActions()),
            'FailedAttempts'    => $this->ReadAttributeInteger('FailedAttempts'),
            'RecoveryDefined'   => $this->ReadAttributeString('Recovery') !== ''
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
        $key = $this->DeriveKey($PIN, $salt);
        $cipherText = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $PlainText, $this->AssociatedData($Purpose), $nonce, $key
        );
        sodium_memzero($key);
        return [
            'format' => self::FORMAT_VERSION,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'cipherText' => base64_encode($cipherText)
        ];
    }

    private function Decrypt(string $PIN, array $Record, string $Purpose): ?string
    {
        if (($Record['format'] ?? null) !== self::FORMAT_VERSION) {
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
        $key = $this->DeriveKey($PIN, $salt);
        $plainText = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipherText, $this->AssociatedData($Purpose), $nonce, $key
        );
        sodium_memzero($key);
        return $plainText === false ? null : $plainText;
    }

    private function DeriveKey(string $PIN, string $Salt): string
    {
        $material = $PIN . chr(0) . $this->CodeHash();
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $material,
            $Salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
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
        return self::MODULE_ID . chr(0) . self::FORMAT_VERSION . chr(0) . $Purpose;
    }

    private function ValidPIN(string $PIN): bool
    {
        $length = strlen($PIN);
        return ctype_digit($PIN) &&
            $length >= $this->ReadPropertyInteger('MinimumPINLength') &&
            $length <= $this->ReadPropertyInteger('MaximumPINLength');
    }

    private function InputAllowed(): bool
    {
        if (!$this->CryptoAvailable()) {
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
            $this->SetSummary('Recovery mode');
        } elseif ($this->ReadAttributeBoolean('PermanentlyLocked')) {
            $this->SetSummary('Permanently locked');
        } elseif (time() < $this->ReadAttributeInteger('LockUntil')) {
            $this->SetSummary('Temporarily locked');
        } else {
            $this->SetSummary(count($this->ReadActions()) . ' protected action(s)');
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
            'Scramble' => $this->ReadPropertyBoolean('ScrambleKeypad'),
            'ScrambleInterval' => $this->ReadPropertyInteger('ScrambleInterval')
        ];
    }

    private function GetTileData(): array
    {
        return array_merge($this->GetStatusData(), $this->GetPresentationData());
    }
}
