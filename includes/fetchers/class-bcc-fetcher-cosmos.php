<?php
/**
 * Cosmos Chain Fetcher
 *
 * Fetches validator and DAO data from Cosmos SDK chains via LCD REST API.
 * Supports any Cosmos SDK chain (cosmoshub, osmosis, akash, juno, etc.).
 *
 * API Endpoints used:
 *  - /cosmos/staking/v1beta1/validators/{address}     → validator info
 *  - /cosmos/staking/v1beta1/validators/{address}/delegations → delegator count
 *  - /cosmos/slashing/v1beta1/signing_infos/{cons_addr} → uptime
 *  - /cosmos/gov/v1beta1/proposals                    → governance proposals
 *  - /cosmos/gov/v1beta1/proposals/{id}/votes/{addr}  → vote participation
 *
 * @package BCC_Onchain_Signals
 * @subpackage Fetchers
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Fetcher_Cosmos implements BCC_Fetcher_Interface {

    private object $chain;
    private string $rest_url;
    private int $timeout = 15;

    public function __construct(object $chain) {
        $this->chain    = $chain;
        $this->rest_url = rtrim($chain->rest_url ?? $chain->rpc_url, '/');
    }

    public function get_chain(): object {
        return $this->chain;
    }

    public function supports_feature(string $feature): bool {
        return in_array($feature, ['validator', 'dao'], true);
    }

    // ── Validator Fetching ───────────────────────────────────────────────────

    public function fetch_validator(string $address): array {
        // Cosmos validator addresses use the valoper prefix
        // e.g., cosmosvaloper1... or osmovaloper1...
        $valoper = $this->ensure_valoper_prefix($address);

        // 1. Get validator info
        $validator = $this->lcd_get("/cosmos/staking/v1beta1/validators/{$valoper}");
        if (!$validator || !isset($validator['validator'])) {
            return [];
        }

        $val = $validator['validator'];

        // 2. Get delegator count (paginated, we just need the total)
        $delegations = $this->lcd_get("/cosmos/staking/v1beta1/validators/{$valoper}/delegations", [
            'pagination.limit'   => 1,
            'pagination.count_total' => 'true',
        ]);
        $delegator_count = (int) ($delegations['pagination']['total'] ?? 0);

        // 3. Get signing info for uptime calculation
        $uptime = $this->fetch_uptime($val);

        // 4. Get governance participation
        $gov_participation = $this->fetch_governance_participation($valoper);

        // 5. Get voting power rank
        $voting_power_rank = $this->fetch_voting_power_rank($valoper);

        // Parse commission rate (stored as decimal string, e.g., "0.100000000000000000")
        $commission_rate = isset($val['commission']['commission_rates']['rate'])
            ? round((float) $val['commission']['commission_rates']['rate'] * 100, 2)
            : null;

        // Parse tokens (bonded amount in base denom)
        $total_stake = isset($val['tokens'])
            ? $this->tokens_to_display($val['tokens'])
            : null;

        // Parse self-delegation
        $self_stake = $this->fetch_self_delegation($valoper);

        // Determine status
        $status = $this->parse_status($val['status'] ?? '');

        // Jailed count from slashing
        $jailed_count = $this->fetch_jailed_count($val);

        return [
            'operator_address'       => $valoper,
            'chain_id'               => (int) $this->chain->id,
            'moniker'                => $val['description']['moniker'] ?? null,
            'status'                 => $status,
            'commission_rate'        => $commission_rate,
            'total_stake'            => $total_stake,
            'self_stake'             => $self_stake,
            'delegator_count'        => $delegator_count,
            'uptime_30d'             => $uptime,
            'governance_participation' => $gov_participation,
            'jailed_count'           => $jailed_count,
            'voting_power_rank'      => $voting_power_rank,
        ];
    }

    // ── DAO Fetching ─────────────────────────────────────────────────────────

    public function fetch_dao_stats(string $contract): array {
        // Cosmos governance is chain-native (not contract-based)
        // We treat the chain itself as the "DAO"
        $proposals = $this->lcd_get('/cosmos/gov/v1beta1/proposals', [
            'pagination.limit'       => 1,
            'pagination.count_total' => 'true',
        ]);

        $total_proposals = (int) ($proposals['pagination']['total'] ?? 0);

        // Count passed proposals
        $passed = $this->lcd_get('/cosmos/gov/v1beta1/proposals', [
            'proposal_status'        => '3', // PROPOSAL_STATUS_PASSED
            'pagination.limit'       => 1,
            'pagination.count_total' => 'true',
        ]);
        $passed_count = (int) ($passed['pagination']['total'] ?? 0);

        // Participation rate from recent proposals
        $participation = $this->estimate_participation_rate();

        return [
            'governance_contract' => 'native',
            'chain_id'            => (int) $this->chain->id,
            'platform'            => 'cosmos-gov',
            'total_proposals'     => $total_proposals,
            'passed_proposals'    => $passed_count,
            'participation_rate'  => $participation,
            'quorum_threshold'    => null, // Would need params query
            'token_holders'       => null, // Not easily available via LCD
            'active_voters'       => null,
        ];
    }

    // ── Not Supported ────────────────────────────────────────────────────────

    public function fetch_collections(string $address): array {
        return []; // Cosmos doesn't have standard NFTs (Stargaze has CW721 but needs separate handling)
    }

    public function fetch_contracts(string $address): array {
        return []; // CosmWasm contracts need a different approach
    }

    // ── Internal Helpers ─────────────────────────────────────────────────────

    /**
     * Make a GET request to the Cosmos LCD REST API.
     */
    private function lcd_get(string $path, array $params = []): ?array {
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

    /**
     * Convert base denom tokens to display units.
     * e.g., uatom → ATOM (divide by 1e6)
     */
    private function tokens_to_display(string $amount): float {
        return round((float) $amount / 1e6, 6);
    }

    /**
     * Ensure the address has a valoper prefix.
     * If a standard address is provided (cosmos1...), convert to cosmosvaloper1...
     */
    private function ensure_valoper_prefix(string $address): string {
        // Already a valoper address
        if (strpos($address, 'valoper') !== false) {
            return $address;
        }

        // Try to convert by replacing the HRP
        // This is a simplified approach — proper conversion requires bech32 re-encoding
        $prefix = '';
        $pos = strpos($address, '1');
        if ($pos !== false) {
            $prefix = substr($address, 0, $pos);
        }

        // Common prefix mappings
        $valoper_map = [
            'cosmos' => 'cosmosvaloper',
            'osmo'   => 'osmovaloper',
            'akash'  => 'akashvaloper',
            'juno'   => 'junovaloper',
            'stars'  => 'starsvaloper',
        ];

        if (isset($valoper_map[$prefix])) {
            // Note: this string replacement only works if the bech32 data portion is the same
            // For proper conversion, we'd need to re-encode with the new prefix
            return str_replace($prefix . '1', $valoper_map[$prefix] . '1', $address);
        }

        return $address;
    }

    /**
     * Parse Cosmos SDK validator status enum.
     */
    private function parse_status(string $status): string {
        $map = [
            'BOND_STATUS_BONDED'    => 'active',
            'BOND_STATUS_UNBONDED'  => 'inactive',
            'BOND_STATUS_UNBONDING' => 'inactive',
        ];

        return $map[$status] ?? 'unknown';
    }

    /**
     * Fetch uptime percentage from signing info.
     */
    private function fetch_uptime(array $val): ?float {
        // Get consensus pubkey for signing info lookup
        $cons_key = $val['consensus_pubkey']['key'] ?? null;
        if (!$cons_key) {
            return null;
        }

        // Signing info endpoint uses the consensus address
        // We need to derive it from the pubkey — for now use the simpler missed blocks approach
        $signing_infos = $this->lcd_get('/cosmos/slashing/v1beta1/signing_infos', [
            'pagination.limit' => 200,
        ]);

        if (!$signing_infos || empty($signing_infos['info'])) {
            return null;
        }

        // Find this validator's signing info by matching consensus address
        // This is a simplified approach — in production you'd derive the cons address from pubkey
        $missed = null;
        foreach ($signing_infos['info'] as $info) {
            if (isset($info['address']) && $info['address'] === ($val['operator_address'] ?? '')) {
                $missed = (int) ($info['missed_blocks_counter'] ?? 0);
                break;
            }
        }

        if ($missed === null) {
            return null;
        }

        // Assuming a ~10000 block signing window (chain param dependent)
        $window = 10000;
        $uptime = round((1 - ($missed / $window)) * 100, 2);

        return max(0, min(100, $uptime));
    }

    /**
     * Fetch self-delegation amount.
     */
    private function fetch_self_delegation(string $valoper): ?float {
        // Derive the self-delegation address from valoper
        // In Cosmos, the delegator address shares the same underlying key
        // Replace 'valoper' with '' to get the self-delegation address
        $self_addr = str_replace('valoper', '', $valoper);

        $delegation = $this->lcd_get("/cosmos/staking/v1beta1/validators/{$valoper}/delegations/{$self_addr}");

        if ($delegation && isset($delegation['delegation_response']['balance']['amount'])) {
            return $this->tokens_to_display($delegation['delegation_response']['balance']['amount']);
        }

        return null;
    }

    /**
     * Fetch governance participation rate for a validator.
     * Checks votes on recent proposals.
     */
    private function fetch_governance_participation(string $valoper): ?float {
        // Get recent passed/rejected proposals
        $proposals = $this->lcd_get('/cosmos/gov/v1beta1/proposals', [
            'pagination.limit'  => 20,
            'pagination.reverse' => 'true',
        ]);

        if (!$proposals || empty($proposals['proposals'])) {
            return null;
        }

        $total   = 0;
        $voted   = 0;
        $voter   = str_replace('valoper', '', $valoper);

        foreach ($proposals['proposals'] as $prop) {
            $status = $prop['status'] ?? '';
            // Only count completed proposals
            if (!in_array($status, ['PROPOSAL_STATUS_PASSED', 'PROPOSAL_STATUS_REJECTED'], true)) {
                continue;
            }

            $total++;

            $vote = $this->lcd_get("/cosmos/gov/v1beta1/proposals/{$prop['proposal_id']}/votes/{$voter}");
            if ($vote && isset($vote['vote'])) {
                $voted++;
            }
        }

        if ($total === 0) {
            return null;
        }

        return round(($voted / $total) * 100, 2);
    }

    /**
     * Estimate overall governance participation rate from recent proposals.
     */
    private function estimate_participation_rate(): ?float {
        $proposals = $this->lcd_get('/cosmos/gov/v1beta1/proposals', [
            'proposal_status'   => '3', // PASSED
            'pagination.limit'  => 5,
            'pagination.reverse' => 'true',
        ]);

        if (!$proposals || empty($proposals['proposals'])) {
            return null;
        }

        // Check tally results of most recent passed proposal
        $latest = $proposals['proposals'][0] ?? null;
        if (!$latest || !isset($latest['final_tally_result'])) {
            return null;
        }

        $tally = $latest['final_tally_result'];
        $total_voted = (float) ($tally['yes'] ?? 0)
            + (float) ($tally['no'] ?? 0)
            + (float) ($tally['no_with_veto'] ?? 0)
            + (float) ($tally['abstain'] ?? 0);

        // Get total bonded tokens for percentage
        $pool = $this->lcd_get('/cosmos/staking/v1beta1/pool');
        $bonded = (float) ($pool['pool']['bonded_tokens'] ?? 0);

        if ($bonded === 0.0) {
            return null;
        }

        return round(($total_voted / $bonded) * 100, 2);
    }

    /**
     * Get voting power rank among active validators.
     */
    private function fetch_voting_power_rank(string $valoper): ?int {
        // Get all bonded validators sorted by tokens (descending is default)
        $validators = $this->lcd_get('/cosmos/staking/v1beta1/validators', [
            'status'           => 'BOND_STATUS_BONDED',
            'pagination.limit' => 300,
        ]);

        if (!$validators || empty($validators['validators'])) {
            return null;
        }

        // Sort by tokens descending
        $vals = $validators['validators'];
        usort($vals, function ($a, $b) {
            return bccomp($b['tokens'] ?? '0', $a['tokens'] ?? '0');
        });

        // Find rank
        foreach ($vals as $i => $v) {
            if (($v['operator_address'] ?? '') === $valoper) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Count how many times a validator has been jailed.
     */
    private function fetch_jailed_count(array $val): int {
        // The LCD doesn't directly expose jail count.
        // We can check if currently jailed.
        $jailed = $val['jailed'] ?? false;

        // For a proper count, you'd need to index slashing events.
        // For now, return 1 if currently jailed, 0 otherwise.
        return $jailed ? 1 : 0;
    }
}
