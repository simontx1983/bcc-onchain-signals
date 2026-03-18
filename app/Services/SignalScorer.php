<?php

namespace BCC\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts raw on-chain signal data into a trust score contribution.
 *
 * Score breakdown (max 40 per wallet):
 *
 *  Wallet age (max 20 pts)
 *  Transaction depth (max 10 pts)
 *  Contract deployments (max 10 pts)
 */
class SignalScorer
{
    public static function score(array $signals): float
    {
        $age_days          = (int)   ($signals['wallet_age_days']    ?? 0);
        $tx_count          = (int)   ($signals['tx_count']           ?? 0);
        $contract_count    = (int)   ($signals['contract_count']     ?? 0);
        $contract_age_days = isset($signals['contract_age_days'])
                             ? (int) $signals['contract_age_days']
                             : null;

        $age_score      = self::ageScore($age_days);
        $depth_score    = self::depthScore($tx_count);
        $contract_score = self::contractScore($contract_count, $contract_age_days);

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

        $age_score      = self::ageScore($age_days);
        $depth_score    = self::depthScore($tx_count);
        $contract_score = self::contractScore($contract_count, $contract_age_days);

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

    private static function ageScore(int $days): float
    {
        return match (true) {
            $days >= (365 * 5) => 8.0,
            $days >= (365 * 3) => 6.0,
            $days >= (365 * 2) => 3.0,
            $days >= 365       => 2.0,
            $days >= 180       => 0.5,
            default            => 0.2,
        };
    }

    private static function depthScore(int $count): float
    {
        return match (true) {
            $count >= 2000 => 7.0,
            $count >= 500  => 5.0,
            $count >= 100  => 3.0,
            $count >= 20   => 1.0,
            default        => 0.2,
        };
    }

    private static function contractScore(int $count, ?int $contract_age_days): float
    {
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
