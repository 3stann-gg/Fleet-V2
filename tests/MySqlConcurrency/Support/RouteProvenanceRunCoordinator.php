<?php

namespace Tests\MySqlConcurrency\Support;

use Closure;
use RuntimeException;
use Throwable;

final class RouteProvenanceRunCoordinator
{
    /** Tests may observe safe events in memory; live events go to the launcher's captured stdout. */
    public function __construct(private ?Closure $evidenceSink = null) {}

    /** Only typed, bounded diagnostics from the known worker contract may leave the process. */
    public function recordWorker(string $worker, array $result, ?int $launcherPid, array $context): void
    {
        $event = ['event' => 'route_provenance_worker', 'worker' => in_array($worker, ['a', 'b', 'holder', 'contender', 'probe'], true) ? $worker : null,
            'barrier' => is_string($context['barrier'] ?? null) && preg_match('/\Arp-[a-z0-9-]{1,65}\z/', $context['barrier']) === 1 ? $context['barrier'] : null,
            'launcher_pid' => $launcherPid, 'exit_code' => is_int($result['exit_code'] ?? null) ? $result['exit_code'] : null,
            'evidence_complete' => false];
        try {
            if ($event['worker'] === null || $event['barrier'] === null || $launcherPid === null || $launcherPid < 1 || $event['exit_code'] === null
                || ! is_string($result['output'] ?? null) || ! is_string($result['error_output'] ?? null)
                || strlen($result['output']) > 32768 || strlen($result['error_output']) > 32768) {
                throw new RuntimeException('Invalid worker evidence envelope.');
            }
            $success = $event['exit_code'] === 0;
            $text = trim($success ? $result['output'] : $result['error_output']);
            if ($success && trim($result['error_output']) !== '') {
                throw new RuntimeException('Unexpected worker stderr.');
            }
            if (! $success) {
                $parts = explode(' Diagnostics: ', $text, 2);
                if (count($parts) !== 2 || ! str_starts_with($parts[0], 'Route provenance worker refused or failed: Unexpected route worker result,')) {
                    throw new RuntimeException('Missing failure diagnostics.');
                }
                $text = $parts[1];
            }
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Invalid worker JSON.');
            }
            if ($success && $worker === 'probe') {
                if (($decoded['outcome'] ?? null) !== 'row_lock_confirmed' || ($decoded['mysql_error'] ?? null) !== 3572) {
                    throw new RuntimeException('Invalid probe evidence.');
                }
                $event += ['outcome' => 'row_lock_confirmed', 'mysql_error' => 3572];
                foreach (['pid', 'connection_id', 'control_connection_id', 'probe_connection_id'] as $field) {
                    if (! is_int($decoded[$field] ?? null) || $decoded[$field] < 1) {
                        throw new RuntimeException('Missing probe identity.');
                    }
                    $event[$field] = $decoded[$field];
                }
            } else {
                $diagnostic = $success ? ($decoded['diagnostic'] ?? null) : $decoded;
                $safe = $this->workerDiagnostic($diagnostic, $worker, $context);
                if ($success && (($decoded['outcome'] ?? null) !== $safe['outcome'] || in_array(true, $safe['diagnostic']['failure_conditions'], true))) {
                    throw new RuntimeException('Inconsistent successful worker evidence.');
                }
                if (! $success && ! in_array(true, $safe['diagnostic']['failure_conditions'], true)) {
                    throw new RuntimeException('Missing worker rejection reason.');
                }
                $event += $safe;
                if ($success) {
                    foreach (['pid', 'connection_id', 'control_connection_id'] as $field) {
                        if (! is_int($decoded[$field] ?? null) || $decoded[$field] < 1) {
                            throw new RuntimeException('Missing worker identity.');
                        }
                        $event[$field] = $decoded[$field];
                    }
                    if ($decoded['pid'] !== $safe['diagnostic']['worker_pid'] || $decoded['connection_id'] === $decoded['control_connection_id']) {
                        throw new RuntimeException('Inconsistent worker identity.');
                    }
                }
            }
            $event['evidence_complete'] = true;
        } catch (Throwable) {
            $this->emit($event);
            throw new RuntimeException('Required sanitized worker evidence is missing or malformed.');
        }
        $this->emit($event);
    }

    /** Rebuild allowlisted diagnostics through the same pure guard; never trust arbitrary child JSON. */
    private function workerDiagnostic(mixed $diagnostic, string $worker, array $context): array
    {
        if (! is_array($diagnostic) || ($diagnostic['worker'] ?? null) !== $worker
            || ! is_string($context['barrier'] ?? null) || preg_match('/\Arp-[a-z0-9-]{1,65}\z/', $context['barrier']) !== 1 || ($diagnostic['barrier'] ?? null) !== $context['barrier']
            || ! is_int($diagnostic['worker_pid'] ?? null) || $diagnostic['worker_pid'] < 1
            || ! is_int($diagnostic['http_status'] ?? null) || $diagnostic['http_status'] < 100 || $diagnostic['http_status'] > 599
            || ! is_int($diagnostic['laravel_transaction_level'] ?? null) || ! is_bool($diagnostic['pdo_in_transaction'] ?? null)
            || ! is_bool($diagnostic['route_lock_attempted'] ?? null) || ! is_bool($diagnostic['route_lock_acquired'] ?? null)
            || ! array_key_exists('expected_estimate_revision', $diagnostic) || $diagnostic['expected_estimate_revision'] !== ($context['expected_estimate_revision'] ?? null)
            || ! is_array($diagnostic['response'] ?? null) || ! is_array($diagnostic['sql_failures'] ?? null)
            || ! is_array($diagnostic['sql_exceptions'] ?? null) || count($diagnostic['sql_failures']) > 16 || count($diagnostic['sql_exceptions']) > 16
            || ($diagnostic['sql_failure_count'] ?? null) !== count($diagnostic['sql_failures'])
            || ($diagnostic['sql_exception_count'] ?? null) !== count($diagnostic['sql_exceptions'])) {
            throw new RuntimeException('Invalid worker diagnostic contract.');
        }
        $conditions = ['malformed_response', 'unrecognized_response_or_sql_failure', 'missing_route_lock_attempt', 'open_laravel_transaction', 'open_pdo_transaction'];
        foreach ($conditions as $condition) {
            if (! is_bool($diagnostic['failure_conditions'][$condition] ?? null)) {
                throw new RuntimeException('Missing worker failure condition.');
            }
        }
        $response = $diagnostic['response'];
        $message = match ($response['message_category'] ?? null) {
            'persistence_error' => 'Route changes could not be saved.',
            'validation_error' => 'Please check the route information.',
            'synthetic_audit_failure' => RouteProvenanceConcurrencyWorker::AUDIT_FAILURE,
            default => '',
        };
        $body = ['message' => $message, 'code' => $response['application_code'] ?? null];
        if ($diagnostic['http_status'] === 200) {
            $body += ['success' => true, 'routePlan' => []];
        }
        foreach (array_slice(is_array($response['validation_fields'] ?? null) ? $response['validation_fields'] : [], 0, 64) as $field) {
            if (is_string($field)) {
                $body['errors'][$field] = [];
            }
        }
        $content = $diagnostic['failure_conditions']['malformed_response'] ? '{' : json_encode($body, JSON_THROW_ON_ERROR);
        $recoveries = $diagnostic['recovered_deadlocks'] ?? [];
        if (! is_array($recoveries)) {
            throw new RuntimeException('Invalid deadlock recovery history.');
        }
        RouteProvenanceConcurrencyWorker::assertDeadlockRecoveries($recoveries);
        $outcome = $diagnostic['failure_conditions']['malformed_response'] ? 'unexpected_response'
            : RouteProvenanceConcurrencyWorker::classify($diagnostic['http_status'], $body, $diagnostic['sql_failures'], $message === RouteProvenanceConcurrencyWorker::AUDIT_FAILURE);
        try {
            $safe = RouteProvenanceConcurrencyWorker::assertResult($diagnostic['http_status'], $content,
                ['participant' => $worker, 'barrier' => $context['barrier'], 'request' => ['expected_estimate_revision' => $context['expected_estimate_revision'] ?? null]],
                $diagnostic['sql_failures'], $diagnostic['sql_exceptions'], $message === RouteProvenanceConcurrencyWorker::AUDIT_FAILURE,
                $diagnostic['route_lock_attempted'], $diagnostic['route_lock_acquired'], $diagnostic['laravel_transaction_level'], $diagnostic['pdo_in_transaction'], $recoveries)['diagnostic'];
        } catch (RuntimeException $exception) {
            $safe = json_decode(explode(' Diagnostics: ', $exception->getMessage(), 2)[1], true, 64, JSON_THROW_ON_ERROR);
        }
        foreach ($conditions as $condition) {
            if ($safe['failure_conditions'][$condition] !== $diagnostic['failure_conditions'][$condition]) {
                throw new RuntimeException('Inconsistent worker failure conditions.');
            }
        }
        $safe['worker_pid'] = $diagnostic['worker_pid'];
        if (! is_int($response['omitted_validation_fields'] ?? null) || $response['omitted_validation_fields'] < 0) {
            throw new RuntimeException('Missing validation omission count.');
        }
        $safe['response']['omitted_validation_fields'] = $response['omitted_validation_fields'];

        return ['outcome' => $outcome, 'diagnostic' => $safe];
    }

    /** Guard every inspection; capture post-state even when execution or assertions fail. */
    public function observeDuplicate(Closure $guard, Closure $inspect, Closure $execute): void
    {
        if ($guard() !== true) {
            throw new RuntimeException('Duplicate evidence inspection was not authorized.');
        }
        $before = $inspect();
        $this->emit(['event' => 'route_provenance_baseline', 'states' => $this->stateFacts($before)]);
        $failure = null;
        try {
            if ($execute($before) !== true) {
                throw new RuntimeException('Duplicate invariant verification did not complete.');
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        try {
            if ($guard() !== true) {
                throw new RuntimeException('Post-state inspection was not authorized.');
            }
            $after = $inspect();
            if (array_keys($before) !== array_keys($after)) {
                throw new RuntimeException('Post-state route identities differ.');
            }
            $equal = [];
            foreach ($before as $id => $state) {
                $equal[$id] = $state === $after[$id];
            }
            $this->emit(['event' => 'route_provenance_post_state', 'available' => true,
                'verification_passed' => $failure === null, 'states' => $this->stateFacts($after), 'complete_state_equal' => $equal]);
        } catch (Throwable) {
            $this->emit(['event' => 'route_provenance_post_state', 'available' => false, 'verification_passed' => false]);
            $failure ??= new RuntimeException('Required guarded post-state evidence is unavailable.');
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Persist only component hashes/counts; raw protected records remain in test memory. */
    private function stateFacts(array $states): array
    {
        if (count($states) !== 2) {
            throw new RuntimeException('Two protected route states are required.');
        }
        $facts = [];
        foreach ($states as $id => $state) {
            if (! is_int($id) || $id < 1 || ! is_array($state) || count($state) !== 5) {
                throw new RuntimeException('Invalid protected route state.');
            }
            foreach (['route', 'stops', 'reservation', 'provenance', 'audits'] as $component) {
                if (! is_array($state[$component] ?? null)) {
                    throw new RuntimeException('Incomplete protected route state.');
                }
                $facts[$id][$component] = ['sha256' => hash('sha256', json_encode($state[$component], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
                    'count' => count($state[$component])];
            }
        }

        return $facts;
    }

    private function emit(array $event): void
    {
        array_walk_recursive($event, function (mixed &$value): void {
            if (is_string($value)) {
                $value = HarnessEnvironment::sanitizeDiagnostic($value);
            }
        });
        $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        if (strlen($json) > 32768) {
            throw new RuntimeException('Sanitized evidence exceeded its bound.');
        }
        if ($this->evidenceSink !== null) {
            ($this->evidenceSink)($event);
        } elseif (fwrite(STDOUT, $json) !== strlen($json)) {
            throw new RuntimeException('Unable to preserve sanitized evidence.');
        }
    }

    /**
     * Baseline verification never rebuilds the schema or erases failed-test evidence.
     *
     * @param  Closure(): void  $execute
     * @param  Closure(): void  $verifyBaseline
     * @param  Closure(): void  $release
     */
    public function run(Closure $execute, Closure $verifyBaseline, Closure $release): void
    {
        $failures = [];
        foreach (['Route provenance execution' => $execute, 'Baseline verification' => $verifyBaseline, 'Advisory lock release' => $release] as $phase => $operation) {
            try {
                $operation();
            } catch (Throwable $exception) {
                $failures[$phase] = $exception;
            }
        }
        if ($failures !== []) {
            $parts = [];
            foreach ($failures as $phase => $exception) {
                $parts[] = $phase.' failed: '.HarnessEnvironment::sanitizeDiagnostic($exception::class.': '.$exception->getMessage());
            }
            throw new RuntimeException(implode(' ', $parts), 0, reset($failures));
        }
    }
}
