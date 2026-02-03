<?php
$token_path = '/var/run/secrets/kubernetes.io/serviceaccount/token';
$ca_path = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
$api = 'https://kubernetes.default.svc';

$nodes = [];
$pods = [];
$error = null;

if (file_exists($token_path) && file_exists($ca_path)) {
  $token = trim(file_get_contents($token_path));
  $ch = function($path) use ($api, $token, $ca_path) {
    $c = curl_init($api . $path);
    curl_setopt_array($c, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
      CURLOPT_CAINFO => $ca_path,
      CURLOPT_TIMEOUT => 5,
    ]);
    $out = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $code === 200 ? json_decode($out, true) : null;
  };
  $nodesData = $ch('/api/v1/nodes');
  $podsData = $ch('/api/v1/pods?limit=500');
  if ($nodesData !== null) $nodes = $nodesData['items'] ?? [];
  if ($podsData !== null) $pods = $podsData['items'] ?? [];
  if ($nodesData === null && $podsData === null) $error = 'Could not reach Kubernetes API.';
} else {
  $error = 'Not running inside Kubernetes (no service account token).';
}

function nodeReady($node) {
  foreach ($node['status']['conditions'] ?? [] as $c) {
    if (($c['type'] ?? '') === 'Ready') return ($c['status'] ?? '') === 'True';
  }
  return false;
}

function nodeCapacity($node) {
  $c = $node['status']['capacity'] ?? [];
  return [
    'cpu' => $c['cpu'] ?? '—',
    'memory' => $c['memory'] ?? '—',
  ];
}

function podRestarts($pod) {
  $n = 0;
  foreach ($pod['status']['containerStatuses'] ?? [] as $cs) {
    $n += (int)($cs['restartCount'] ?? 0);
  }
  return $n;
}

function podAge($pod) {
  $t = $pod['metadata']['creationTimestamp'] ?? '';
  if (!$t) return '—';
  $sec = time() - strtotime($t);
  if ($sec < 60) return $sec . 's';
  if ($sec < 3600) return floor($sec / 60) . 'm';
  if ($sec < 86400) return floor($sec / 3600) . 'h';
  return floor($sec / 86400) . 'd';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cluster Dashboard</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0b0c0e;
      color: #d8d9da;
      min-height: 100vh;
      font-size: 13px;
    }
    .navbar {
      background: #181b1f;
      border-bottom: 1px solid #2d2d2d;
      padding: 0.75rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .navbar h1 {
      font-size: 1.1rem;
      font-weight: 600;
      color: #e0e0e0;
    }
    .navbar .badge {
      background: #252526;
      color: #9d9d9d;
      padding: 0.2rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
    }
    .container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
    .panel {
      background: #181b1f;
      border: 1px solid #2d2d2d;
      border-radius: 6px;
      margin-bottom: 1.5rem;
      overflow: hidden;
    }
    .panel-header {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid #2d2d2d;
      font-weight: 600;
      color: #e0e0e0;
      font-size: 0.9rem;
    }
    .panel-body { padding: 0; }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      text-align: left;
      padding: 0.6rem 1rem;
      border-bottom: 1px solid #252526;
    }
    th {
      color: #9d9d9d;
      font-weight: 500;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    tr:last-child td { border-bottom: 0; }
    tr:hover td { background: rgba(255,255,255,0.02); }
    .status-ok { color: #73bf69; }
    .status-warn { color: #e0b000; }
    .status-bad { color: #e02f44; }
    .mono { font-family: ui-monospace, monospace; font-size: 0.9em; }
    .error-box {
      background: #2d1f1f;
      border: 1px solid #e02f44;
      color: #e0a0a0;
      padding: 1rem 1.5rem;
      border-radius: 6px;
      margin: 1.5rem;
    }
    .stats-row {
      display: flex;
      gap: 1rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }
    .stat-card {
      background: #181b1f;
      border: 1px solid #2d2d2d;
      border-radius: 6px;
      padding: 1rem 1.25rem;
      min-width: 140px;
    }
    .stat-card .value { font-size: 1.5rem; font-weight: 600; color: #5794f2; }
    .stat-card .label { color: #9d9d9d; font-size: 0.75rem; margin-top: 0.25rem; }
  </style>
</head>
<body>
  <div class="navbar">
    <h1>Cluster Dashboard</h1>
    <span class="badge">Kubernetes</span>
  </div>
  <div class="container">
    <?php if ($error): ?>
      <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
      <div class="stats-row">
        <div class="stat-card">
          <div class="value"><?php echo count($nodes); ?></div>
          <div class="label">Nodes</div>
        </div>
        <div class="stat-card">
          <div class="value"><?php echo count($pods); ?></div>
          <div class="label">Pods</div>
        </div>
        <div class="stat-card">
          <div class="value"><?php echo count(array_filter($pods, fn($p) => ($p['status']['phase'] ?? '') === 'Running')); ?></div>
          <div class="label">Running</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">Nodes</div>
        <div class="panel-body">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Status</th>
                <th>CPU</th>
                <th>Memory</th>
                <th>OS / Kubelet</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($nodes as $n): ?>
                <?php $ready = nodeReady($n); $cap = nodeCapacity($n); ?>
                <tr>
                  <td class="mono"><?php echo htmlspecialchars($n['metadata']['name'] ?? '—'); ?></td>
                  <td>
                    <span class="<?php echo $ready ? 'status-ok' : 'status-bad'; ?>">
                      <?php echo $ready ? 'Ready' : 'Not Ready'; ?>
                    </span>
                  </td>
                  <td><?php echo htmlspecialchars($cap['cpu']); ?></td>
                  <td><?php echo htmlspecialchars($cap['memory']); ?></td>
                  <td><?php echo htmlspecialchars(($n['status']['nodeInfo']['osImage'] ?? '—') . ' / ' . ($n['status']['nodeInfo']['kubeletVersion'] ?? '—')); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($nodes)): ?>
                <tr><td colspan="5" style="color:#9d9d9d">No nodes</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">Pods</div>
        <div class="panel-body">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Namespace</th>
                <th>Status</th>
                <th>Restarts</th>
                <th>Node</th>
                <th>Age</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pods as $p): ?>
                <?php
                  $phase = $p['status']['phase'] ?? 'Unknown';
                  $restarts = podRestarts($p);
                  $statusClass = $phase === 'Running' ? 'status-ok' : ($phase === 'Pending' ? 'status-warn' : 'status-bad');
                ?>
                <tr>
                  <td class="mono"><?php echo htmlspecialchars($p['metadata']['name'] ?? '—'); ?></td>
                  <td class="mono"><?php echo htmlspecialchars($p['metadata']['namespace'] ?? '—'); ?></td>
                  <td><span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($phase); ?></span></td>
                  <td><?php echo $restarts > 0 ? '<span class="status-warn">' . $restarts . '</span>' : $restarts; ?></td>
                  <td class="mono"><?php echo htmlspecialchars($p['spec']['nodeName'] ?? '—'); ?></td>
                  <td><?php echo htmlspecialchars(podAge($p)); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($pods)): ?>
                <tr><td colspan="6" style="color:#9d9d9d">No pods</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
