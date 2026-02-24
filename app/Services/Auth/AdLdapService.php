<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class AdLdapService
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public function authenticate(array $config, string $login, string $password): ?array
    {
        if (!$this->isEnabled($config) || trim($password) === '') {
            return null;
        }

        $connection = $this->connect($config);
        $this->bindServiceAccount($connection, $config);
        $entry = $this->findUserEntry($connection, $config, $login);
        if ($entry === null) {
            ldap_unbind($connection);
            return null;
        }

        $dn = (string) ($entry['dn'] ?? '');
        if ($dn === '') {
            ldap_unbind($connection);
            return null;
        }

        $bound = @ldap_bind($connection, $dn, $password);
        if ($bound !== true) {
            ldap_unbind($connection);
            return null;
        }

        $normalized = $this->normalizeEntry($entry, $config);
        ldap_unbind($connection);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{total:int,created:int,updated:int,disabled:int}
     */
    public function sync(array $config): array
    {
        if (!$this->isEnabled($config)) {
            throw new RuntimeException('AD/LDAP sync is disabled.');
        }

        $connection = $this->connect($config);
        $this->bindServiceAccount($connection, $config);

        $baseDn = trim((string) ($config['base_dn'] ?? ''));
        if ($baseDn === '') {
            throw new RuntimeException('Missing LDAP base_dn');
        }

        $filter = trim((string) ($config['sync_filter'] ?? ''));
        if ($filter === '') {
            $filter = '(&(objectClass=user)(!(objectClass=computer)))';
        }

        $attributes = [
            'dn',
            'mail',
            'userprincipalname',
            'samaccountname',
            'givenname',
            'sn',
            'displayname',
            'memberof',
            'objectguid',
        ];

        $search = @ldap_search($connection, $baseDn, $filter, $attributes);
        if ($search === false) {
            ldap_unbind($connection);
            throw new RuntimeException('LDAP search failed during sync');
        }

        $entries = ldap_get_entries($connection, $search);
        ldap_unbind($connection);

        $seenExternalIds = [];
        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'disabled' => 0];
        $groupRoleMap = $this->groupRoleMapFromSettings();

        for ($i = 0; $i < (int) ($entries['count'] ?? 0); $i++) {
            if (!is_array($entries[$i])) {
                continue;
            }

            $normalized = $this->normalizeEntry($entries[$i], $config);
            if ($normalized['email'] === '' || $normalized['external_id'] === '') {
                continue;
            }

            $seenExternalIds[] = $normalized['external_id'];
            $stats['total']++;

            $user = User::query()
                ->where('auth_provider', 'ad_ldap')
                ->where('external_directory_id', $normalized['external_id'])
                ->first();

            if ($user === null) {
                $user = User::query()->where('email', Str::lower($normalized['email']))->first();
            }

            $isCreate = $user === null;
            if ($user === null) {
                $user = new User();
                $user->password = Hash::make(Str::random(48));
                $user->mfa_type = 'mail';
                $user->must_change_password = false;
            }

            $user->first_name = $normalized['first_name'] !== '' ? $normalized['first_name'] : 'AD';
            $user->last_name = $normalized['last_name'] !== '' ? $normalized['last_name'] : 'User';
            $user->email = Str::lower($normalized['email']);
            $user->auth_provider = 'ad_ldap';
            $user->ad_username = $normalized['username'] !== '' ? $normalized['username'] : null;
            $user->external_directory_id = $normalized['external_id'];
            $user->external_directory_dn = $normalized['dn'] !== '' ? $normalized['dn'] : null;
            $user->external_directory_active = true;
            $user->external_directory_last_sync_at = Carbon::now();
            $user->is_active = true;
            $user->disabled_at = null;
            $user->save();

            if ($isCreate) {
                $stats['created']++;
            } else {
                $stats['updated']++;
            }

            $mappedRoleUuids = $this->resolveMappedRoleUuids($normalized['groups'], $groupRoleMap);
            if ($mappedRoleUuids !== []) {
                $roleIds = Role::query()
                    ->whereIn('uuid', $mappedRoleUuids)
                    ->pluck('id')
                    ->all();
                if ($roleIds !== []) {
                    $user->roles()->syncWithoutDetaching($roleIds);
                }
            }
        }

        if ($seenExternalIds !== []) {
            $stats['disabled'] = User::query()
                ->where('auth_provider', 'ad_ldap')
                ->whereNotIn('external_directory_id', $seenExternalIds)
                ->where('external_directory_active', true)
                ->update(['external_directory_active' => false]);
        }

        TenantSetting::query()->updateOrCreate(
            ['key' => 'ad_ldap_last_sync'],
            ['value_json' => ['at' => now()->toISOString(), 'stats' => $stats]]
        );

        return $stats;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function testConnection(array $config): void
    {
        if (!$this->isEnabled($config)) {
            throw new RuntimeException('AD/LDAP auth is disabled.');
        }

        $connection = $this->connect($config);
        $this->bindServiceAccount($connection, $config);
        ldap_unbind($connection);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connect(array $config)
    {
        if (!function_exists('ldap_connect')) {
            throw new RuntimeException('PHP LDAP extension is not installed.');
        }

        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('Missing LDAP host');
        }

        $port = (int) ($config['port'] ?? 389);
        $useSsl = (bool) ($config['use_ssl'] ?? false);
        $useTls = (bool) ($config['use_tls'] ?? false);
        $timeout = max(1, (int) ($config['timeout'] ?? 5));

        $uri = str_contains($host, '://')
            ? $host
            : (($useSsl ? 'ldaps://' : 'ldap://') . $host);

        $connection = @ldap_connect($uri, $port);
        if ($connection === false) {
            throw new RuntimeException('Unable to connect to LDAP server');
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $timeout);

        if ($useTls) {
            if (!@ldap_start_tls($connection)) {
                throw new RuntimeException('Failed to start LDAP TLS connection');
            }
        }

        return $connection;
    }

    /**
     * @param resource $connection
     * @param array<string, mixed> $config
     */
    private function bindServiceAccount($connection, array $config): void
    {
        $bindDn = trim((string) ($config['bind_dn'] ?? ''));
        $bindPassword = (string) ($config['bind_password'] ?? '');
        if ($bindDn === '' || trim($bindPassword) === '') {
            throw new RuntimeException('Missing LDAP bind credentials');
        }

        $bound = @ldap_bind($connection, $bindDn, $bindPassword);
        if ($bound !== true) {
            throw new RuntimeException('LDAP bind failed for service account');
        }
    }

    /**
     * @param resource $connection
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function findUserEntry($connection, array $config, string $login): ?array
    {
        $baseDn = trim((string) ($config['base_dn'] ?? ''));
        if ($baseDn === '') {
            throw new RuntimeException('Missing LDAP base_dn');
        }

        $loginValue = trim($login);
        $shortLogin = str_contains($loginValue, '@') ? explode('@', $loginValue, 2)[0] : $loginValue;

        $filterTemplate = trim((string) ($config['user_filter'] ?? ''));
        if ($filterTemplate === '') {
            $safeLogin = ldap_escape($loginValue, '', LDAP_ESCAPE_FILTER);
            $safeShort = ldap_escape($shortLogin, '', LDAP_ESCAPE_FILTER);
            $filterTemplate = "(&(objectClass=user)(!(objectClass=computer))(|(mail={$safeLogin})(userPrincipalName={$safeLogin})(sAMAccountName={$safeShort})))";
        } else {
            $filterTemplate = str_replace('{login}', ldap_escape($loginValue, '', LDAP_ESCAPE_FILTER), $filterTemplate);
            $filterTemplate = str_replace('{short_login}', ldap_escape($shortLogin, '', LDAP_ESCAPE_FILTER), $filterTemplate);
        }

        $attributes = [
            'dn',
            'mail',
            'userprincipalname',
            'samaccountname',
            'givenname',
            'sn',
            'displayname',
            'memberof',
            'objectguid',
        ];
        $search = @ldap_search($connection, $baseDn, $filterTemplate, $attributes, 0, 1);
        if ($search === false) {
            return null;
        }

        $entries = ldap_get_entries($connection, $search);
        if ((int) ($entries['count'] ?? 0) < 1 || !is_array($entries[0] ?? null)) {
            return null;
        }

        return $entries[0];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $config
     * @return array{external_id:string,dn:string,email:string,first_name:string,last_name:string,username:string,groups:array<int,string>}
     */
    private function normalizeEntry(array $entry, array $config): array
    {
        $emailAttr = trim((string) ($config['email_attribute'] ?? 'mail'));
        $firstNameAttr = trim((string) ($config['first_name_attribute'] ?? 'givenname'));
        $lastNameAttr = trim((string) ($config['last_name_attribute'] ?? 'sn'));
        $usernameAttr = trim((string) ($config['username_attribute'] ?? 'samaccountname'));
        $groupAttr = trim((string) ($config['group_attribute'] ?? 'memberof'));

        $email = $this->ldapValue($entry, $emailAttr);
        if ($email === '') {
            $email = $this->ldapValue($entry, 'userprincipalname');
        }

        $firstName = $this->ldapValue($entry, $firstNameAttr);
        $lastName = $this->ldapValue($entry, $lastNameAttr);
        $username = $this->ldapValue($entry, $usernameAttr);
        $dn = (string) ($entry['dn'] ?? '');

        $externalId = $this->ldapObjectGuid($entry);
        if ($externalId === '') {
            $externalId = $dn !== '' ? hash('sha256', Str::lower($dn)) : hash('sha256', Str::lower($email));
        }

        $groups = $this->ldapValues($entry, $groupAttr);
        $groups = array_values(array_unique(array_map(static fn (string $group): string => Str::lower(trim($group)), $groups)));

        return [
            'external_id' => $externalId,
            'dn' => $dn,
            'email' => Str::lower(trim($email)),
            'first_name' => trim($firstName),
            'last_name' => trim($lastName),
            'username' => trim($username),
            'groups' => $groups,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function ldapValue(array $entry, string $attribute): string
    {
        $attribute = Str::lower(trim($attribute));
        if ($attribute === '' || !isset($entry[$attribute]) || !is_array($entry[$attribute])) {
            return '';
        }

        $value = $entry[$attribute][0] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<int, string>
     */
    private function ldapValues(array $entry, string $attribute): array
    {
        $attribute = Str::lower(trim($attribute));
        if ($attribute === '' || !isset($entry[$attribute]) || !is_array($entry[$attribute])) {
            return [];
        }

        $values = [];
        $count = (int) ($entry[$attribute]['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $value = $entry[$attribute][$i] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function ldapObjectGuid(array $entry): string
    {
        if (!isset($entry['objectguid']) || !is_array($entry['objectguid'])) {
            return '';
        }

        $raw = $entry['objectguid'][0] ?? null;
        if (!is_string($raw) || strlen($raw) !== 16) {
            return '';
        }

        $parts = unpack('V1a/v1b/v1c/C8d', $raw);
        if (!is_array($parts)) {
            return '';
        }

        return sprintf(
            '%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
            $parts['a'],
            $parts['b'],
            $parts['c'],
            $parts['d1'],
            $parts['d2'],
            $parts['d3'],
            $parts['d4'],
            $parts['d5'],
            $parts['d6'],
            $parts['d7'],
            $parts['d8']
        );
    }

    /**
     * @return array<string, string>
     */
    private function groupRoleMapFromSettings(): array
    {
        $map = TenantSetting::query()->where('key', 'ad_ldap_group_role_map')->value('value_json');
        if (!is_array($map)) {
            return [];
        }

        $resolved = [];
        foreach ($map as $row) {
            if (!is_array($row)) {
                continue;
            }
            $group = Str::lower(trim((string) ($row['group'] ?? '')));
            $roleUuid = trim((string) ($row['role_uuid'] ?? ''));
            if ($group !== '' && $roleUuid !== '') {
                $resolved[$group] = $roleUuid;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $groups
     * @param array<string, string> $groupRoleMap
     * @return array<int, string>
     */
    private function resolveMappedRoleUuids(array $groups, array $groupRoleMap): array
    {
        $roles = [];
        foreach ($groups as $group) {
            $normalized = Str::lower(trim($group));
            if ($normalized === '') {
                continue;
            }
            if (isset($groupRoleMap[$normalized])) {
                $roles[] = $groupRoleMap[$normalized];
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isEnabled(array $config): bool
    {
        return (bool) ($config['enabled'] ?? false);
    }
}
