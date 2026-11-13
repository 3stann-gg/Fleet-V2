<?php

namespace Tests\MySqlConcurrency\Support;

use RuntimeException;

/** Parse in memory only: SQL, bindings, record bytes and arbitrary server text never leave here. */
final class InnoDbDeadlockDiagnostic
{
    public static function parse(string $status): array
    {
        if (strlen($status) > 1048576 || ! str_contains($status, 'LATEST DETECTED DEADLOCK')) {
            throw new RuntimeException('No bounded InnoDB deadlock report is available.');
        }
        $section = explode('LATEST DETECTED DEADLOCK', $status, 2)[1];
        $transactions = [];
        $ordinal = null;
        $kind = null;
        $lastLock = null;
        $victim = null;
        foreach (preg_split('/\r?\n/', $section) as $line) {
            if (preg_match('/\A\*\*\* WE ROLL BACK TRANSACTION \(([12])\)/', $line, $match)) {
                $victim = (int) $match[1];
                break;
            }
            if (preg_match('/\A\*\*\* \(([12])\) TRANSACTION:/', $line, $match)) {
                $ordinal = (int) $match[1];
                $transactions[$ordinal] = ['ordinal' => $ordinal, 'transaction_id' => null, 'thread_id' => null, 'operation' => null, 'locks' => []];
                $kind = $lastLock = null;
            } elseif ($ordinal !== null && preg_match('/\ATRANSACTION ([0-9]+),/', $line, $match)) {
                $transactions[$ordinal]['transaction_id'] = $match[1];
            } elseif ($ordinal !== null && preg_match('/\AMySQL thread id ([0-9]+),/', $line, $match)) {
                $transactions[$ordinal]['thread_id'] = (int) $match[1];
            } elseif ($ordinal !== null && preg_match('/\A(insert into|update|select|delete from)\s/i', $line, $match)) {
                $transactions[$ordinal]['operation'] = strtolower($match[1]);
            } elseif (preg_match('/\A\*\*\* \(([12])\) (HOLDS THE LOCK|WAITING FOR THIS LOCK)/', $line, $match)) {
                $ordinal = (int) $match[1];
                $kind = str_starts_with($match[2], 'HOLDS') ? 'held' : 'waiting';
            } elseif ($ordinal !== null && $kind !== null && preg_match('/\ARECORD LOCKS .* index ([A-Za-z0-9_]+) of table `([^`]+)`\.`([^`]+)` .* lock_mode ([XS])\b(.*)\z/', $line, $match)) {
                if ($match[2] !== HarnessSafetyPolicy::DATABASE || ! in_array($match[3], ['route_plans', 'reservations', 'route_stops', 'route_estimate_provenances', 'audit_logs'], true)
                    || ! in_array($match[1], ['PRIMARY', 'rep_current_uq', 'rep_calculation_uq', 'rep_route_history_idx', 'rep_reservation_idx', 'rep_actor_idx', 'route_stops_route_plan_id_stop_order_unique'], true)) {
                    throw new RuntimeException('Deadlock metadata contains an unapproved resource.');
                }
                $transactions[$ordinal]['locks'][] = ['kind' => $kind, 'table' => $match[3], 'index' => $match[1], 'mode' => $match[4],
                    'insert_intention' => str_contains($match[5], 'insert intention'), 'gap_before_record' => str_contains($match[5], 'gap before rec'),
                    'supremum' => false];
                $lastLock = array_key_last($transactions[$ordinal]['locks']);
            } elseif ($ordinal !== null && $lastLock !== null && str_contains($line, 'asc supremum')) {
                $transactions[$ordinal]['locks'][$lastLock]['supremum'] = true;
            }
        }
        if (count($transactions) !== 2 || $victim === null) {
            throw new RuntimeException('Incomplete InnoDB deadlock cycle.');
        }
        foreach ($transactions as $transaction) {
            if ($transaction['transaction_id'] === null || $transaction['thread_id'] === null || count($transaction['locks']) < 2
                || ! in_array('held', array_column($transaction['locks'], 'kind'), true) || ! in_array('waiting', array_column($transaction['locks'], 'kind'), true)) {
                throw new RuntimeException('Incomplete deadlock transaction evidence.');
            }
        }

        return ['transactions' => array_values($transactions), 'victim_ordinal' => $victim, 'raw_sql_and_records_retained' => false];
    }
}
