<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts raw on-chain signal data into a trust score contribution.
 *
 * Score breakdown (max 40 per wallet):
 *
 *  Wallet age (max 20 pts — graduated scores below, cap enforced by min())
 *  ─────────────────────────────────
 *   < 180 days        → 0.2
 *   180 – 364 days    → 0.5
 *   1 – 2 years       →  2
 *   2 – 3 years       →  3
 *   3 – 5 years       →  6
 *   5+ years          →  8  (highest graduated value; cap is 20)
 *
 *  Transaction depth (max 10 pts)
 *  ─────────────────────────────────
 *   < 20              → 0.2
 *   20 – 99           →  1
 *   100 – 499         →  3
 *   500 – 1 999       →  5
 *   2 000+            →  7
 *
 *  Contract deployments (max 10 pts)
 *  ─────────────────────────────────
 *   0                 → 0.2
 *   1                 → 0.5
 *   2                 →  1
 *   3                 →  3
 *   5                 →  4
 *   10                →  5
 *   20+               →  8  (hard cap: 10)
 *
 *  Anti-gaming multiplier (applied to contract score when age known):
 *   < 30 days         → × 0.15
 *   30 – 90 days      → × 0.30
 *   90 – 365 days     → × 0.45
 *   1+ year           → × 0.60
 */
class BCC_Onchain_Scorer
{
    public static function score(array $signals): float
    {
        $age_days          = (int)   ($signals['wallet_age_days']    ?? 0);
        $tx_count          = (int)   ($signals['tx_count']           ?? 0);
        $contract_count    = (int)   ($signals['contract_count']     ?? 0);
        $contract_age_days = isset($signals['contract_age_days'])
                             ? (int) $signals['contract_age_days']
                             : null;

        $age_score      = self::age_score($age_days);
        $depth_score    = self::depth_score($tx_count);
        $contract_score = self::contract_score($contract_count, $contract_age_days);

        return min(40.0, $age_score + $depth_score + $contract_score);
    }

    public static function breakdown(array $signals): array
    {
        $age_days          = (int)   ($signals['wallet_age_days']    ?? 0);
        $tx_count          = (int)   ($signals['tx_count']           ?? 0);
        $contract_count    = (int)   ($signals['contract_count']     ?? 0);
        $contract_age_days = isset($signals['contract_age_days'])
                             ? (int) $signals['contract_age_days']
                             : null;

        $age_score      = self::age_score($age_days);
        $depth_score    = self::depth_score($tx_count);
        $contract_score = self::contract_score($contract_count, $contract_age_days);

        return [
            'age_days'          => $age_days,
            'tx_count'          => $tx_count,
            'contract_count'    => $contract_count,
            'contract_age_days' => $contract_age_days,
            'age_score'         => $age_score,
            'depth_score'       => $depth_score,
            'contract_score'    => $contract_score,
            'total'             => min(40.0, $age_score + $depth_score + $contract_score),
            'max_possible'      => BCC_ONCHAIN_MAX_AGE_SCORE + BCC_ONCHAIN_MAX_DEPTH_SCORE + BCC_ONCHAIN_MAX_CONTRACT_SCORE,
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function age_score(int $days): float
    {
        return match (true) {
            $days >= (365 * 5) => 8.0,  // 5+ years — highest graduated value
            $days >= (365 * 3) => 6.0,
            $days >= (365 * 2) => 3.0,
            $days >= 365       => 2.0,
            $days >= 180       => 0.5,
            default            => 0.2,
        };
    }

    private static function depth_score(int $count): float
    {
        return match (true) {
            $count >= 2000 => 7.0,  // 2000+ txs — highest graduated value
            $count >= 500  => 5.0,
            $count >= 100  => 3.0,
            $count >= 20   => 1.0,
            default        => 0.2,
        };
    }

    private static function contract_score(int $count, ?int $contract_age_days): float
    {
        // Base score using graduated thresholds (not per-deployment accumulation)
        $base = match (true) {
            $count >= 20 => 8.0,
            $count >= 10 => 5.0,
            $count >= 5  => 4.0,
            $count >= 3  => 3.0,
            $count >= 2  => 1.0,
            $count >= 1  => 0.5,
            default      => 0.2,
        };

        $score = min($base, (float) BCC_ONCHAIN_MAX_CONTRACT_SCORE);

        // Anti-gaming multiplier: dampen newly deployed contracts
        if ($count > 0 && $contract_age_days !== null) {
            $multiplier = match (true) {
                $contract_age_days >= 365 => 0.60,
                $contract_age_days >= 90  => 0.45,
                $contract_age_days >= 30  => 0.30,
                default                   => 0.15,
            };
            $score = round($score * $multiplier, 2);
        }

        return $score;
    }
}
