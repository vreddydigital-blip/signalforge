<?php
// Remote orchestration engine: calls the LangGraph service on Render.
//
// The PHP side stays the source of truth for the run record. A remote run is
// mirrored into agent_runs / agent_tasks, so the dashboard reads the same shape
// whichever engine produced it, and a run survives a service restart as a
// recorded failure rather than a silent disappearance.
//
// Engine selection (config.php -> orchestrator_mode):
//   'remote'  always call the service; a clear error if it is unreachable
//   'local'   always use the in-PHP engine (orchestrator.php)
//   'auto'    try the service, fall back to the local engine when it is down

function orch_remote_config()
{
    return [
        'url'   => trim((string) cfg('orchestrator_url')),
        'token' => trim((string) cfg('orchestrator_token')),
        'mode'  => strtolower(trim((string) cfg('orchestrator_mode'))),
        'timeout' => (int) (cfg('orchestrator_timeout') ?: 20),
    ];
}

function orch_remote_mode()
{
    $mode = orch_remote_config()['mode'];
    return in_array($mode, ['remote', 'local', 'auto'], true) ? $mode : 'auto';
}

function orch_remote_configured()
{
    $conf = orch_remote_config();
    return $conf['url'] !== '' && preg_match('#^https?://#i', $conf['url']) === 1;
}

/**
 * One HTTP call to the service. Returns [ok, status, body, error].
 * Uses cURL when available and falls back to stream wrappers, because some
 * shared hosts disable one or the other.
 */
function orch_remote_request($method, $path, $payload = null)
{
    $conf = orch_remote_config();
    $url = rtrim($conf['url'], '/') . $path;
    $headers = ['Accept: application/json'];
    if ($conf['token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $conf['token'];
    }
    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $conf['timeout'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return [false, 0, null, $error !== '' ? $error : 'Connection failed'];
        }
        return [true, $status, json_decode($raw, true), null];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body === null ? '' : $body,
            'timeout'       => $conf['timeout'],
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return [false, 0, null, 'Connection failed (allow_url_fopen may be disabled)'];
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [true, $status, json_decode($raw, true), null];
}

/**
 * Health probe. Returns ['reachable' => bool, 'degraded' => bool, 'provider' => ?string, 'error' => ?string].
 */
function orch_remote_health()
{
    if (!orch_remote_configured()) {
        return ['reachable' => false, 'degraded' => true, 'provider' => null, 'error' => 'No orchestrator_url configured'];
    }
    list($ok, $status, $body, $error) = orch_remote_request('GET', '/health');
    if (!$ok) {
        return ['reachable' => false, 'degraded' => true, 'provider' => null, 'error' => $error];
    }
    if ($status !== 200 || !is_array($body)) {
        return ['reachable' => false, 'degraded' => true, 'provider' => null, 'error' => 'Health check returned HTTP ' . $status];
    }
    return [
        'reachable' => true,
        'degraded'  => !empty($body['degraded']),
        'provider'  => isset($body['provider']) ? (string) $body['provider'] : null,
        'model'     => isset($body['model']) ? (string) $body['model'] : null,
        'error'     => null,
    ];
}

/**
 * Start a run on the service and mirror it into the database.
 * Returns the same shape as orch_run_state().
 */
function orch_remote_start($user, $objective)
{
    $dataset = orch_dataset($user['id']);
    list($ok, $status, $body, $error) = orch_remote_request('POST', '/v1/runs', [
        'objective' => $objective,
        'dataset'   => $dataset,
    ]);

    if (!$ok || $status < 200 || $status >= 300 || !is_array($body) || empty($body['id'])) {
        $detail = $error;
        if ($detail === null && is_array($body) && isset($body['detail'])) {
            $detail = is_string($body['detail']) ? $body['detail'] : 'Rejected by the orchestration service';
        }
        if ($detail === null) {
            $detail = 'HTTP ' . $status;
        }
        return [null, $detail];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO agent_runs (userId, objective, status, engine, remoteId) VALUES (?, ?, 'running', 'langgraph', ?)")
            ->execute([(int) $user['id'], $objective, (string) $body['id']]);
        $runId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO agent_tasks (runId, agentKey, title, status, position) VALUES (?, ?, ?, 'queued', ?)");
        $titles = [
            'signal-analyst'   => 'Score every experiment for a decisive signal',
            'segment-scout'    => 'Find which audience segment responds best',
            'tracking-auditor' => 'Audit event flow and traffic split integrity',
            'sample-planner'   => 'Estimate remaining sample and days to decision',
            'brief-writer'     => 'Draft the next test brief from the weakest result',
        ];
        $position = 0;
        foreach (orch_agents() as $agent) {
            $ins->execute([$runId, $agent['key'], $titles[$agent['key']], $position]);
            $position++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [orch_run_state($user['id'], $runId), null];
}

/**
 * Pull the service's view of a run and write it into the mirror tables.
 * Returns the same shape as orch_run_state().
 */
function orch_remote_sync($user, $runId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM agent_runs WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $runId, (int) $user['id']]);
    $run = $st->fetch();
    if (!$run) {
        fail('Run not found.', 'NOT_FOUND', 404);
    }
    if (empty($run['remoteId'])) {
        return orch_run_state($user['id'], $runId);
    }
    if ($run['status'] !== 'running') {
        return orch_run_state($user['id'], $runId);
    }

    list($ok, $status, $body, $error) = orch_remote_request('GET', '/v1/runs/' . rawurlencode((string) $run['remoteId']));

    if (!$ok || $status !== 200 || !is_array($body)) {
        // The service went away mid-run. Record it honestly instead of leaving the
        // run spinning forever, and say what to check.
        $detail = $error !== null ? $error : ('HTTP ' . $status);
        if ($status === 404) {
            $detail = 'The orchestration service no longer has this run (it may have restarted).';
        }
        $pdo->prepare("UPDATE agent_tasks SET status = 'failed', error = ?, finishedAt = CURRENT_TIMESTAMP WHERE runId = ? AND status IN ('queued','running')")
            ->execute([mb_substr('Orchestration service unreachable: ' . $detail, 0, 500), (int) $run['id']]);
        $pdo->prepare("UPDATE agent_runs SET status = 'failed', updatedAt = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([(int) $run['id']]);
        return orch_run_state($user['id'], $runId);
    }

    $tasks = isset($body['tasks']) && is_array($body['tasks']) ? $body['tasks'] : [];
    $byKey = [];
    foreach ($tasks as $task) {
        if (isset($task['agentKey'])) {
            $byKey[(string) $task['agentKey']] = $task;
        }
    }

    $markDone = $pdo->prepare("UPDATE agent_tasks SET status = 'done', output = ?, startedAt = COALESCE(startedAt, CURRENT_TIMESTAMP), finishedAt = CURRENT_TIMESTAMP WHERE id = ?");
    $markFailed = $pdo->prepare("UPDATE agent_tasks SET status = 'failed', error = ?, finishedAt = CURRENT_TIMESTAMP WHERE id = ?");
    $markRunning = $pdo->prepare("UPDATE agent_tasks SET status = 'running', startedAt = COALESCE(startedAt, CURRENT_TIMESTAMP) WHERE id = ?");

    $tst = $pdo->prepare('SELECT * FROM agent_tasks WHERE runId = ? ORDER BY position ASC, id ASC');
    $tst->execute([(int) $run['id']]);
    foreach ($tst->fetchAll() as $row) {
        $key = (string) $row['agentKey'];
        if (!isset($byKey[$key])) {
            continue;
        }
        $remote = $byKey[$key];
        $remoteStatus = isset($remote['status']) ? (string) $remote['status'] : 'queued';
        if ($remoteStatus === 'done' && !empty($remote['output'])) {
            $markDone->execute([json_encode($remote['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['id']]);
        } elseif ($remoteStatus === 'failed') {
            $message = isset($remote['error']) && $remote['error'] !== null ? (string) $remote['error'] : 'This agent failed on the orchestration service.';
            $markFailed->execute([mb_substr($message, 0, 500), (int) $row['id']]);
        } elseif ($remoteStatus === 'running' && $row['status'] === 'queued') {
            $markRunning->execute([(int) $row['id']]);
        }
    }

    $remoteStatus = isset($body['status']) ? (string) $body['status'] : 'running';
    if ($remoteStatus !== 'running') {
        $final = $remoteStatus === 'completed' ? 'completed' : ($remoteStatus === 'cancelled' ? 'cancelled' : 'failed');
        $pdo->prepare('UPDATE agent_runs SET status = ?, updatedAt = CURRENT_TIMESTAMP WHERE id = ?')->execute([$final, (int) $run['id']]);
    } else {
        $pdo->prepare('UPDATE agent_runs SET updatedAt = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $run['id']]);
    }

    return orch_run_state($user['id'], $runId);
}

/**
 * Ask the service to cancel, then mirror the result.
 */
function orch_remote_cancel($user, $runId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM agent_runs WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $runId, (int) $user['id']]);
    $run = $st->fetch();
    if (!$run) {
        fail('Run not found.', 'NOT_FOUND', 404);
    }
    if (!empty($run['remoteId']) && $run['status'] === 'running') {
        orch_remote_request('POST', '/v1/runs/' . rawurlencode((string) $run['remoteId']) . '/cancel');
    }
    $pdo->prepare("UPDATE agent_tasks SET status = 'failed', error = 'Cancelled by user', finishedAt = CURRENT_TIMESTAMP WHERE runId = ? AND status IN ('queued','running')")
        ->execute([(int) $run['id']]);
    $pdo->prepare("UPDATE agent_runs SET status = 'cancelled', updatedAt = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int) $run['id']]);
    return orch_run_state($user['id'], $runId);
}
