<?php
declare(strict_types=1);

/**
 * AiAgentConnection -- the Microsoft / Google account an AI agent acts on.
 *
 * ONE connection per (agent, provider), shared by everyone who can edit that agent.
 *
 * It used to be one per (user, agent, provider), read for whoever was logged in. So when mr@ connected a
 * Microsoft account to "AI agent 1" and ks@ then opened the same agent, ks@ saw "not connected" -- the
 * grant existed, under mr@'s key -- and the voice bridge, which looks the grant up by the agent's saved
 * owner, could miss a connection made by someone else. Now:
 *
 *   - Everybody sees the same status: "Connected as <account>", plus who connected it.
 *   - Anybody can disconnect it and connect their own; that REPLACES it (there is still only one).
 *   - The voice bridge's token lookup finds it whoever made it.
 *
 * STORAGE   ai_agent_token:{domain}:{agent}:{provider}   JSON; access and refresh tokens encrypted by the
 *           caller-supplied closures (the controller owns the cipher). Carries connected_by_user_uuid and
 *           connected_by_email so the UI can say who.
 *
 * EXISTING CONNECTIONS are not lost: the old per-(user, agent) record of the agent's owner, then of the
 * person looking, is ADOPTED into the shared record the first time it is read, and the old key removed.
 */
class AiAgentConnection
{
    public static function key(string $domainUuid, string $agentId, string $provider): string
    {
        return "ai_agent_token:{$domainUuid}:{$agentId}:{$provider}";
    }

    /** The older per-user key (see TeamsaiController::aiTokenKey). */
    public static function legacyKey(string $domainUuid, string $userUuid, string $agentId, string $provider): string
    {
        return "ai_user_token:{$domainUuid}:{$userUuid}:{$agentId}:{$provider}";
    }

    /** Stores the grant, replacing any existing one. Tokens are encrypted with $encrypt. */
    public static function save($redis, callable $encrypt, string $domainUuid, string $agentId, string $provider, array $grant): void
    {
        $grant['access_token']  = $encrypt((string) ($grant['access_token'] ?? ''));
        $grant['refresh_token'] = $encrypt((string) ($grant['refresh_token'] ?? ''));
        $grant['updated_at']    = date('c');
        $redis->set(self::key($domainUuid, $agentId, $provider), json_encode($grant));
    }

    /**
     * The agent's connection, decrypted, or null. Looks at the shared record first; failing that, adopts a
     * legacy per-user record from $legacyUsers (the agent's owner first, then the viewer), so a connection made
     * before this change keeps working and is shown to everyone from now on.
     */
    public static function load($redis, callable $encrypt, callable $decrypt, string $domainUuid, string $agentId, string $provider, array $legacyUsers = []): ?array
    {
        $raw = $redis->get(self::key($domainUuid, $agentId, $provider));
        if (!empty($raw)) {
            $grant = json_decode((string) $raw, true);
            if (is_array($grant)) {
                $grant['access_token']  = $decrypt((string) ($grant['access_token'] ?? ''));
                $grant['refresh_token'] = $decrypt((string) ($grant['refresh_token'] ?? ''));
                return $grant;
            }
        }

        foreach (array_values(array_unique(array_filter($legacyUsers, 'is_string'))) as $userUuid) {
            if ($userUuid === '') { continue; }
            $legacyRaw = $redis->get(self::legacyKey($domainUuid, $userUuid, $agentId, $provider));
            if (empty($legacyRaw)) { continue; }
            $legacy = json_decode((string) $legacyRaw, true);
            if (!is_array($legacy)) { continue; }

            $legacy['access_token']  = $decrypt((string) ($legacy['access_token'] ?? ''));
            $legacy['refresh_token'] = $decrypt((string) ($legacy['refresh_token'] ?? ''));
            $legacy['connected_by_user_uuid'] = $userUuid;
            self::save($redis, $encrypt, $domainUuid, $agentId, $provider, $legacy);
            $redis->del(self::legacyKey($domainUuid, $userUuid, $agentId, $provider));
            return $legacy;
        }
        return null;
    }

    /**
     * Removes the connection. The shared record AND any older per-user records for this agent that could
     * otherwise be adopted back (someone's old connection must not reappear after a disconnect).
     */
    public static function remove($redis, string $domainUuid, string $agentId, string $provider, array $legacyUsers = []): void
    {
        $redis->del(self::key($domainUuid, $agentId, $provider));
        self::removeLegacy($redis, $domainUuid, $agentId, $provider, $legacyUsers);
    }

    /** Only the older per-user records -- used after a new connection replaces them, so they cannot be adopted back. */
    public static function removeLegacy($redis, string $domainUuid, string $agentId, string $provider, array $legacyUsers = []): void
    {
        foreach (array_values(array_unique(array_filter($legacyUsers, 'is_string'))) as $userUuid) {
            if ($userUuid !== '') {
                $redis->del(self::legacyKey($domainUuid, $userUuid, $agentId, $provider));
            }
        }
    }

    /**
     * What the browser may see of a connection: the account, who connected it, and whether that is the
     * person looking. Never any token material.
     */
    public static function publicView(?array $grant, string $viewerUserUuid): ?array
    {
        if ($grant === null) { return null; }
        $by = (string) ($grant['connected_by_user_uuid'] ?? '');
        return [
            'accountEmail'    => $grant['account_email'] ?? '',
            'accountName'     => $grant['account_name'] ?? '',
            'scopes'          => $grant['scopes'] ?? [],
            'connectedAt'     => $grant['connected_at'] ?? '',
            'updatedAt'       => $grant['updated_at'] ?? '',
            'hasRefreshToken' => !empty($grant['refresh_token']),
            'expiresAt'       => $grant['expires_at'] ?? 0,
            // Who made the connection: their RingQ login email when it was recorded (connections adopted
            // from before this change do not have one). connectedByYou lets the UI say "you" instead.
            'connectedByEmail' => (string) ($grant['connected_by_email'] ?? ''),
            'connectedByYou'   => $by !== '' && $viewerUserUuid !== '' && $by === $viewerUserUuid,
        ];
    }
}
