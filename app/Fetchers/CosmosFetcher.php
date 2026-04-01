<?php

namespace BCC\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\CollectionFetcherInterface;
use BCC\Onchain\Contracts\FetcherInterface;

/**
 * Cosmos Chain Fetcher
 *
 * Fetches validator and DAO data from Cosmos SDK chains via LCD REST API.
 * Supports any Cosmos SDK chain (cosmoshub, osmosis, akash, juno, etc.).
 *
 * NFT collections: Cosmos SDK chains may have CW-721 NFTs (e.g. Stargaze)
 * but no standardized LCD endpoint exists for discovery. Returns empty.
 */
class CosmosFetcher implements FetcherInterface, CollectionFetcherInterface
{
    private object $chain;
    private string $rest_url;
    private int $timeout = 15;

    public function __construct(object $chain)
    {
        $this->chain    = $chain;
        $this->rest_url = rtrim($chain->rest_url ?? $chain->rpc_url, '/');
    }

    public function get_chain(): object
    {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool
    {
        return in_array($feature, ['validator', 'dao'], true);
    }

    // ── Validator Fetching ───────────────────────────────────────────────────

    public function fetch_validator(string $address): array
    {
        $valoper = $this->ensureValoperPrefix($address);

        $validator = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}");
        if (!$validator || !isset($validator['validator'])) {
            return [];
        }

        $val = $validator['validator'];

        $delegations = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}/delegations", [
            'pagination.limit'       => 1,
            'pagination.count_total' => 'true',
        ]);
        $delegator_count = (int) ($delegations['pagination']['total'] ?? 0);

        $uptime             = $this->fetchUptime($val);
        $gov_participation  = $this->fetchGovernanceParticipation($valoper);
        $voting_power_rank  = $this->fetchVotingPowerRank($valoper);

        $commission_rate = isset($val['commission']['commission_rates']['rate'])
            ? round((float) $val['commission']['commission_rates']['rate'] * 100, 2)
            : null;

        $total_stake = isset($val['tokens'])
            ? $this->tokensToDisplay($val['tokens'])
            : null;

        $self_stake   = $this->fetchSelfDelegation($valoper);
        $status       = $this->parseStatus($val['status'] ?? '');
        $jailed_count = $this->fetchJailedCount($val);

        return [
            'operator_address'         => $valoper,
            'chain_id'                 => (int) $this->chain->id,
            'moniker'                  => $val['description']['moniker'] ?? null,
            'status'                   => $status,
            'commission_rate'          => $commission_rate,
            'total_stake'              => $total_stake,
            'self_stake'               => $self_stake,
            'delegator_count'          => $delegator_count,
            'uptime_30d'               => $uptime,
            'governance_participation' => $gov_participation,
            'jailed_count'             => $jailed_count,
            'voting_power_rank'        => $voting_power_rank,
        ];
    }

    // ── Not Supported ────────────────────────────────────────────────────────

    /**
     * Cosmos chains lack a standardized NFT collection indexer on LCD.
     * CW-721 NFTs exist on chains like Stargaze but require chain-specific
     * indexers (e.g. Constellations API). Returns empty for now.
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        return [];
    }

    // ── Internal Helpers ─────────────────────────────────────────────────────

    private function lcdGet(string $path, array $params = []): ?array
    {
        $url = $this->rest_url . $path;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log("BCC Cosmos Fetcher: LCD error for {$path} — " . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log("BCC Cosmos Fetcher: LCD {$code} for {$path}");
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    private function tokensToDisplay(string $amount): float
    {
        return round((float) $amount / 1e6, 6);
    }

    private function ensureValoperPrefix(string $address): string
    {
        if (strpos($address, 'valoper') !== false) {
            return $address;
        }

        $prefix = '';
        $pos = strpos($address, '1');
        if ($pos !== false) {
            $prefix = substr($address, 0, $pos);
        }

        $valoper_map = [
            'cosmos' => 'cosmosvaloper',
            'osmo'   => 'osmovaloper',
            'akash'  => 'akashvaloper',
            'juno'   => 'junovaloper',
            'stars'  => 'starsvaloper',
        ];

        if (isset($valoper_map[$prefix])) {
            return str_replace($prefix . '1', $valoper_map[$prefix] . '1', $address);
        }

        return $address;
    }

    private function parseStatus(string $status): string
    {
        $map = [
            'BOND_STATUS_BONDED'    => 'active',
            'BOND_STATUS_UNBONDED'  => 'inactive',
            'BOND_STATUS_UNBONDING' => 'inactive',
        ];

        return $map[$status] ?? 'unknown';
    }

    private function fetchUptime(array $val): ?float
    {
        $cons_key = $val['consensus_pubkey']['key'] ?? null;
        if (!$cons_key) {
            return null;
        }

        // Derive the valcons address from the consensus pubkey so we can
        // match against the signing_infos "address" field.
        // Cosmos SDK: valcons_address = bech32(prefix + "valcons", SHA256(pubkey)[:20])
        $cons_address = $this->deriveConsensusAddress($cons_key, $val['operator_address'] ?? '');
        if (!$cons_address) {
            return null;
        }

        // Use the single-validator signing info endpoint (no pagination needed).
        $signing_info = $this->lcdGet("/cosmos/slashing/v1beta1/signing_infos/{$cons_address}");

        if (!$signing_info || !isset($signing_info['val_signing_info'])) {
            return null;
        }

        $missed = (int) ($signing_info['val_signing_info']['missed_blocks_counter'] ?? 0);

        $window = 10000;
        $uptime = round((1 - ($missed / $window)) * 100, 2);

        return max(0, min(100, $uptime));
    }

    /**
     * Derive the bech32 consensus address (valcons) from a base64-encoded
     * ed25519 consensus pubkey.
     *
     * Cosmos SDK derivation:
     *   1. base64_decode(pubkey) → 32 raw bytes
     *   2. SHA-256 hash → 32 bytes
     *   3. Take first 20 bytes (the "address bytes")
     *   4. Bech32-encode with "{chain_prefix}valcons" HRP
     */
    private function deriveConsensusAddress(string $base64PubKey, string $operatorAddress): ?string
    {
        $raw = base64_decode($base64PubKey, true);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }

        // SHA-256 hash, take first 20 bytes (standard Cosmos address derivation)
        $hash = hash('sha256', $raw, true);
        $addr_bytes = substr($hash, 0, 20);

        // Derive the valcons HRP from the operator address.
        // e.g. cosmosvaloper1... → cosmos, osmovaloper1... → osmo
        $hrp = $this->getValconsHrp($operatorAddress);
        if (!$hrp) {
            return null;
        }

        return $this->bech32Encode($hrp, $addr_bytes);
    }

    /**
     * Extract the valcons HRP from an operator address.
     * cosmosvaloper1... → cosmosvalcons
     * osmovaloper1...   → osmovalcons
     */
    private function getValconsHrp(string $operatorAddress): ?string
    {
        $pos = strpos($operatorAddress, 'valoper1');
        if ($pos === false) {
            return null;
        }
        return substr($operatorAddress, 0, $pos) . 'valcons';
    }

    // ── Bech32 encoding (self-contained, matches WalletController) ───────

    private function bech32Encode(string $hrp, string $data): string
    {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

        $values = $this->convertBits(array_values(unpack('C*', $data)), 8, 5, true);
        if ($values === null) {
            return '';
        }

        $polymod = $this->bech32Polymod(array_merge(
            $this->bech32HrpExpand($hrp),
            $values,
            [0, 0, 0, 0, 0, 0]
        )) ^ 1;

        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }

        $result = $hrp . '1';
        foreach (array_merge($values, $checksum) as $v) {
            $result .= $charset[$v];
        }

        return $result;
    }

    private function bech32Polymod(array $values): int
    {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                $chk ^= (($b >> $i) & 1) ? $gen[$i] : 0;
            }
        }
        return $chk;
    }

    private function bech32HrpExpand(string $hrp): array
    {
        $expand = [];
        for ($i = 0; $i < strlen($hrp); $i++) {
            $expand[] = ord($hrp[$i]) >> 5;
        }
        $expand[] = 0;
        for ($i = 0; $i < strlen($hrp); $i++) {
            $expand[] = ord($hrp[$i]) & 31;
        }
        return $expand;
    }

    private function convertBits(array $data, int $fromBits, int $toBits, bool $pad = true): ?array
    {
        $acc    = 0;
        $bits   = 0;
        $result = [];
        $maxv   = (1 << $toBits) - 1;

        foreach ($data as $value) {
            $acc = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $result[] = ($acc >> $bits) & $maxv;
            }
        }

        if ($pad && $bits > 0) {
            $result[] = ($acc << ($toBits - $bits)) & $maxv;
        }

        return $result;
    }

    private function fetchSelfDelegation(string $valoper): ?float
    {
        $self_addr = str_replace('valoper', '', $valoper);

        $delegation = $this->lcdGet("/cosmos/staking/v1beta1/validators/{$valoper}/delegations/{$self_addr}");

        if ($delegation && isset($delegation['delegation_response']['balance']['amount'])) {
            return $this->tokensToDisplay($delegation['delegation_response']['balance']['amount']);
        }

        return null;
    }

    private function fetchGovernanceParticipation(string $valoper): ?float
    {
        $proposals = $this->lcdGet('/cosmos/gov/v1beta1/proposals', [
            'pagination.limit'   => 20,
            'pagination.reverse' => 'true',
        ]);

        if (!$proposals || empty($proposals['proposals'])) {
            return null;
        }

        $total = 0;
        $voted = 0;
        $voter = str_replace('valoper', '', $valoper);

        foreach ($proposals['proposals'] as $prop) {
            $status = $prop['status'] ?? '';
            if (!in_array($status, ['PROPOSAL_STATUS_PASSED', 'PROPOSAL_STATUS_REJECTED'], true)) {
                continue;
            }

            $total++;

            $vote = $this->lcdGet("/cosmos/gov/v1beta1/proposals/{$prop['proposal_id']}/votes/{$voter}");
            if ($vote && isset($vote['vote'])) {
                $voted++;
            }
        }

        if ($total === 0) {
            return null;
        }

        return round(($voted / $total) * 100, 2);
    }

    private function fetchVotingPowerRank(string $valoper): ?int
    {
        $validators = $this->lcdGet('/cosmos/staking/v1beta1/validators', [
            'status'           => 'BOND_STATUS_BONDED',
            'pagination.limit' => 300,
        ]);

        if (!$validators || empty($validators['validators'])) {
            return null;
        }

        $vals = $validators['validators'];
        usort($vals, function ($a, $b) {
            return bccomp($b['tokens'] ?? '0', $a['tokens'] ?? '0');
        });

        foreach ($vals as $i => $v) {
            if (($v['operator_address'] ?? '') === $valoper) {
                return $i + 1;
            }
        }

        return null;
    }

    private function fetchJailedCount(array $val): int
    {
        $jailed = $val['jailed'] ?? false;
        return $jailed ? 1 : 0;
    }
}
