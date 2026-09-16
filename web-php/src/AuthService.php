<?php

declare(strict_types=1);

namespace OcMaker;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @return array{user: array<string, mixed>, requires_totp?: bool} */
    public function attemptLogin(string $login, string $password, ?string $totpCode = null): array
    {
        if (trim($login) === '' || $password === '') {
            throw new \RuntimeException('Informe usuário, e-mail ou telefone e a senha.');
        }

        $parsed = UserProfileService::parseLoginIdentifier($login);
        $user = $this->users->findByLoginIdentifier($parsed);
        if ($user === null || !(bool) ($user['active'] ?? false)) {
            throw new \RuntimeException('Credenciais inválidas.');
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new \RuntimeException('Conta temporariamente bloqueada. Tente novamente em alguns minutos.');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->users->recordFailedLogin((int) $user['id']);
            throw new \RuntimeException('Credenciais inválidas.');
        }

        if ((bool) ($user['totp_enabled'] ?? false)) {
            if ($totpCode === null || $totpCode === '') {
                return ['user' => $this->users->publicUser($user), 'requires_totp' => true];
            }
            if (!TotpService::verify((string) $user['totp_secret'], $totpCode)) {
                throw new \RuntimeException('Código do autenticador inválido.');
            }
        }

        $this->users->clearFailedLogins((int) $user['id']);
        SessionAuth::login($user);

        return ['user' => $this->users->publicUser($user)];
    }

    public function logout(): void
    {
        SessionAuth::logout();
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $user = SessionAuth::user();
        if ($user === null) {
            return null;
        }

        return $this->users->publicUser($user);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new \RuntimeException('Senha atual incorreta.');
        }
        PasswordPolicy::assertValid($newPassword);

        $this->users->updatePassword($userId, $newPassword);
    }

    public function changePasswordForced(int $userId, string $newPassword, string $confirmPassword): void
    {
        if ($newPassword !== $confirmPassword) {
            throw new \RuntimeException('A confirmação da senha não confere.');
        }
        PasswordPolicy::assertValid($newPassword);
        $this->users->updatePassword($userId, $newPassword, false);
        $user = $this->users->findById($userId);
        if ($user !== null) {
            SessionAuth::refreshUser($user);
        }
    }

    /** @return array{secret: string, uri: string} */
    public function beginTotpSetup(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }
        $secret = TotpService::generateSecret();

        return [
            'secret' => $secret,
            'uri' => TotpService::provisioningUri($secret, (string) $user['email']),
        ];
    }

    public function confirmTotpSetup(int $userId, string $secret, string $code): void
    {
        if (!TotpService::verify($secret, $code)) {
            throw new \RuntimeException('Código do autenticador inválido.');
        }
        $this->users->setTotpSecret($userId, $secret, true);
    }

    public function disableTotp(int $userId, string $password, ?string $code): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            throw new \RuntimeException('Senha incorreta.');
        }
        if ((bool) ($user['totp_enabled'] ?? false)) {
            if ($code === null || !TotpService::verify((string) $user['totp_secret'], $code)) {
                throw new \RuntimeException('Código do autenticador inválido.');
            }
        }
        $this->users->setTotpSecret($userId, null, false);
    }
}
