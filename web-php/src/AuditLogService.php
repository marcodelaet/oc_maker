<?php

declare(strict_types=1);

namespace OcMaker;

final class AuditLogService
{
    /** @param array<string, mixed>|null $details */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $message = null,
        ?array $details = null,
    ): void {
        $actor = self::resolveActor();
        $request = self::resolveRequestMeta();

        $stmt = Database::connection()->prepare(
            'INSERT INTO user_activity_log (
                user_id, actor_type, actor_label, action, entity_type, entity_id, message, details,
                ip_address, user_agent, client_os, client_browser, client_platform, reverse_hostname
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $actor['user_id'],
            $actor['actor_type'],
            $actor['actor_label'],
            $action,
            $entityType,
            $entityId,
            $message,
            $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $request['ip_address'],
            $request['user_agent'],
            $request['client_os'],
            $request['client_browser'],
            $request['client_platform'],
            $request['reverse_hostname'],
        ]);
    }

    /** @return array{user_id:?int,actor_type:string,actor_label:string} */
    public static function resolveActor(): array
    {
        $user = SessionAuth::user();
        if ($user === null) {
            return [
                'user_id' => null,
                'actor_type' => 'guest',
                'actor_label' => 'Usuário convidado',
            ];
        }

        $repo = new UserRepository();
        $public = $repo->publicUser($user);
        $label = (string) ($public['display_name'] ?? $public['name'] ?? $user['email'] ?? 'Usuário');

        return [
            'user_id' => (int) $user['id'],
            'actor_type' => 'user',
            'actor_label' => $label !== '' ? $label : 'Usuário #' . (int) $user['id'],
        ];
    }

    /** @return array<string, ?string> */
    private static function resolveRequestMeta(): array
    {
        $clientInfo = self::clientInfoFromHeader();
        $ip = ClientIpResolver::resolve($clientInfo['local_ip']);
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $parsed = self::parseUserAgent($userAgent);
        $platform = $clientInfo['platform'] ?? $parsed['os'];
        $reverseHost = null;

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) {
            $resolved = @gethostbyaddr($ip);
            if (is_string($resolved) && $resolved !== '' && $resolved !== $ip) {
                $reverseHost = substr($resolved, 0, 255);
            }
        }

        return [
            'ip_address' => $ip,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'client_os' => $parsed['os'],
            'client_browser' => $parsed['browser'],
            'client_platform' => $platform !== null && $platform !== '' ? substr((string) $platform, 0, 120) : null,
            'reverse_hostname' => $reverseHost,
        ];
    }

    /** @return array{platform:?string,language:?string,local_ip:?string} */
    private static function clientInfoFromHeader(): array
    {
        $empty = ['platform' => null, 'language' => null, 'local_ip' => null];
        $raw = trim((string) ($_SERVER['HTTP_X_OC_CLIENT_INFO'] ?? ''));
        if ($raw === '') {
            return $empty;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return $empty;
        }

        try {
            /** @var array<string, mixed>|null $json */
            $json = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $empty;
        }

        if (!is_array($json)) {
            return $empty;
        }

        $localIp = isset($json['local_ip']) ? trim((string) $json['local_ip']) : '';
        if ($localIp !== '' && !filter_var($localIp, FILTER_VALIDATE_IP)) {
            $localIp = '';
        }

        return [
            'platform' => isset($json['platform']) ? (string) $json['platform'] : null,
            'language' => isset($json['language']) ? (string) $json['language'] : null,
            'local_ip' => $localIp !== '' ? $localIp : null,
        ];
    }

    /** @return array{os:string,browser:string} */
    private static function parseUserAgent(string $userAgent): array
    {
        $os = 'Desconhecido';
        if (preg_match('/Windows NT/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iOS/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $os = 'Linux';
        }

        $browser = 'Desconhecido';
        if (preg_match('/Edg\//i', $userAgent)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/OPR\//i', $userAgent) || preg_match('/Opera/i', $userAgent)) {
            $browser = 'Opera';
        } elseif (preg_match('/Firefox\//i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome\//i', $userAgent)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/Safari\//i', $userAgent)) {
            $browser = 'Safari';
        }

        return ['os' => $os, 'browser' => $browser];
    }
}
