<?php

namespace BCC\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Onchain\Contracts\FetcherInterface;

/**
 * Cosmos Chain Fetcher
 *
 * Fetches validator and DAO data from Cosmos SDK chains via LCD REST API.
 * Supports any Cosmos SDK chain (cosmoshub, osmosis, akash, juno, etc.).
 */
class CosmosFetcher implements FetcherInterface
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

    // ── DAO Fetching ─────────────────────────────────────────────────────────

    public function fetch_dao_stats(string $contract): array
    {
        $proposals = $this->lcdGet('/cosmos/gov/v1beta1/proposals', [
            'pagination.limit'       => 1,
            'pagination.count_total' => 'true',
        ]);

        $total_proposals = (int) ($proposals['pagination']['total'] ?? 0);

        $passed = $this->lcdGet('/cosmos/gov/v1beta1/proposals', [
            'proposal_status'        => '3',
            'pagination.limit'       => 1,
            'pagination.count_total' => 'true',
        ]);
        $passed_count = (int) ($passed['pagination']['total'] ?? 0);

        $participation = $this->estimateParticipationRate();

        return [
            'governance_contract' => 'native',
            'chain_id'            => (int) $this->chain->id,
            'platform'            => 'cosmos-gov',
            'total_proposals'     => $total_proposals,
            'passed_proposals'    => $passed_count,
            'participation_rate'  => $participation,
            'quorum_threshold'    => null,
            'token_holders'       => null,
            'active_voters'       => null,
        ];
    }

    // ── Not Supported ────────────────────────────────────────────────────────

    public function fetch_collections(string $address): array
    {
        return [];
    }

    public function fetch_contracts(string $address): array
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

        $signing_infos = $this->lcdGet('/cosmos/slashing/v1beta1/signing_infos', [
            'pagination.limit' => 200,
        ]);

        if (!$signing_infos || empty($signing_infos['info'])) {
            return null;
        }

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

        $window = 10000;
        $uptime = round((1 - ($missed / $window)) * 100, 2);

        return max(0, min(100, $uptime));
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

    private function estimateParticipationRate(): ?float
    {
        $proposals = $this->lcdGet('/cosmos/gov/v1beta1/proposals', [
            'proposal_status'    => '3',
            'pagination.limit'   => 5,
            'pagination.reverse' => 'true',
        ]);

        if (!$proposals || empty($proposals['proposals'])) {
            return null;
        }

        $latest = $proposals['proposals'][0] ?? null;
        if (!$latest || !isset($latest['final_tally_result'])) {
            return null;
        }

        $tally = $latest['final_tally_result'];
        $total_voted = (float) ($tally['yes'] ?? 0)
            + (float) ($tally['no'] ?? 0)
            + (float) ($tally['no_with_veto'] ?? 0)
            + (float) ($tally['abstain'] ?? 0);

        $pool   = $this->lcdGet('/cosmos/staking/v1beta1/pool');
        $bonded = (float) ($pool['pool']['bonded_tokens'] ?? 0);

        if ($bonded === 0.0) {
            return null;
        }

        return round(($total_voted / $bonded) * 100, 2);
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
