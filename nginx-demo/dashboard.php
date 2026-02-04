<?php
$token_path = '/var/run/secrets/kubernetes.io/serviceaccount/token';
$ca_path = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
$api = 'https://kubernetes.default.svc';

$nodes = [];
$pods = [];
$error = null;

$token = (file_exists($token_path) && is_readable($token_path)) ? trim((string)@file_get_contents($token_path)) : '';
if ($token !== '' && file_exists($ca_path)) {
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

function parseMemBytes($s) {
  $s = trim((string)($s ?? ''));
  if (preg_match('/^(\d+)Ki$/i', $s, $m)) return (int)$m[1] * 1024;
  if (preg_match('/^(\d+)Mi$/i', $s, $m)) return (int)$m[1] * 1024 * 1024;
  if (preg_match('/^(\d+)Gi$/i', $s, $m)) return (int)$m[1] * 1024 * 1024 * 1024;
  if (preg_match('/^(\d+)$/', $s, $m)) return (int)$m[1];
  return 0;
}

function clusterCapacity($nodes) {
  $cpu = 0;
  $memBytes = 0;
  foreach ($nodes as $n) {
    $c = $n['status']['capacity'] ?? [];
    $cpu += (int)($c['cpu'] ?? 0);
    $memBytes += parseMemBytes($c['memory'] ?? '');
  }
  return ['cpu' => $cpu, 'memory_gi' => round($memBytes / (1024**3), 1)];
}

function phaseCounts($pods) {
  $out = ['Running' => 0, 'Pending' => 0, 'Succeeded' => 0, 'Failed' => 0, 'Unknown' => 0];
  foreach ($pods as $p) {
    $phase = $p['status']['phase'] ?? 'Unknown';
    $out[$phase] = ($out[$phase] ?? 0) + 1;
  }
  return $out;
}

function namespacesFromPods($pods) {
  $counts = [];
  foreach ($pods as $p) {
    $ns = $p['metadata']['namespace'] ?? '—';
    $counts[$ns] = ($counts[$ns] ?? 0) + 1;
  }
  ksort($counts);
  return $counts;
}

$clusterCap = !$error ? clusterCapacity($nodes) : ['cpu' => 0, 'memory_gi' => 0];
$phaseCounts = !$error ? phaseCounts($pods) : [];
$nsCounts = !$error ? namespacesFromPods($pods) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#0b0c0e">
  <title>Cluster Dashboard</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0b0c0e;
      color: #d8d9da;
      min-height: 100vh;
      font-size: clamp(13px, 2.5vw, 14px);
      -webkit-font-smoothing: antialiased;
    }
    .navbar {
      background: #181b1f;
      border-bottom: 1px solid #2d2d2d;
      padding: 0.75rem 1rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .navbar h1 {
      font-size: clamp(1rem, 4vw, 1.1rem);
      font-weight: 600;
      color: #e0e0e0;
    }
    .navbar .badge {
      background: #252526;
      color: #9d9d9d;
      padding: 0.2rem 0.5rem;
      border-radius: 6px;
      font-size: 0.75rem;
    }
    .container { max-width: 1400px; margin: 0 auto; padding: 1rem; }
    @media (min-width: 600px) { .container { padding: 1.5rem; } }
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
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 0.75rem;
      margin-bottom: 1.5rem;
    }
    @media (min-width: 480px) { .stats-row { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 768px) { .stats-row { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; } }
    .stat-card {
      background: #181b1f;
      border: 1px solid #2d2d2d;
      border-radius: 8px;
      padding: 0.875rem 1rem;
      min-width: 0;
    }
    .stat-card { display: flex; align-items: flex-start; gap: 0.6rem; }
    .stat-card .icon-wrap { flex-shrink: 0; margin-top: 0.15rem; }
    .stat-card .icon-wrap svg { width: 20px; height: 20px; opacity: 0.9; }
    .stat-card .value { font-size: 1.5rem; font-weight: 600; color: #5794f2; }
    .stat-card .label { color: #9d9d9d; font-size: 0.75rem; margin-top: 0.25rem; }
    .live-indicator { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.7rem; color: #73bf69; margin-left: auto; }
    .live-dot { width: 6px; height: 6px; border-radius: 50%; background: #73bf69; animation: pulse 1.5s ease-in-out infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
    .chart-wrap { position: relative; height: 220px; min-height: 180px; border-radius: 8px; overflow: hidden; }
    @media (min-width: 600px) { .chart-wrap { height: 260px; } }
    .panel-body .chart-wrap { background: rgba(0,0,0,0.15); }
    .top-consumers { display: grid; grid-template-columns: 1fr; gap: 1.25rem; }
    @media (min-width: 700px) { .top-consumers { grid-template-columns: 1fr 1fr; } }
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table-wrap table { min-width: 280px; }
    .panel-header .icon-wrap { display: inline-flex; align-items: center; gap: 0.4rem; }
    .panel-header .icon-wrap svg { width: 18px; height: 18px; opacity: 0.9; }
    .storage-note { color: #9d9d9d; font-size: 0.8rem; margin-top: 0.5rem; }
    .cell-bar { position: relative; padding: 0; min-width: 80px; }
    .cell-bar .bar-wrap {
      position: relative;
      display: block;
      height: 1.6rem;
      line-height: 1.6rem;
      border-radius: 4px;
      overflow: hidden;
    }
    .cell-bar .bar-fill {
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      border-radius: 4px;
      opacity: 0.35;
      min-width: 4px;
    }
    .cell-bar .bar-value {
      position: relative;
      z-index: 1;
      padding: 0 0.4rem;
      font-variant-numeric: tabular-nums;
    }
    .tabs {
      display: flex;
      gap: 0.25rem;
      padding: 0 1rem 0;
      margin-bottom: 1rem;
      border-bottom: 1px solid #2d2d2d;
      flex-wrap: wrap;
    }
    .tab-btn {
      background: none;
      border: none;
      color: #9d9d9d;
      padding: 0.6rem 1rem;
      font-size: 0.875rem;
      cursor: pointer;
      border-radius: 6px;
      margin-bottom: -1px;
      border-bottom: 2px solid transparent;
    }
    .tab-btn:hover { color: #d8d9da; }
    .tab-btn.active {
      color: #5794f2;
      border-bottom-color: #5794f2;
      font-weight: 500;
    }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .summary-card {
      background: #181b1f;
      border: 1px solid #2d2d2d;
      border-radius: 8px;
      padding: 1rem;
    }
    .summary-card h3 {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #9d9d9d;
      margin-bottom: 0.5rem;
    }
    .summary-card .row { display: flex; justify-content: space-between; gap: 0.5rem; margin-top: 0.35rem; font-size: 0.9rem; }
    .summary-card .row .v { color: #e0e0e0; font-variant-numeric: tabular-nums; }
    .namespace-list { max-height: 200px; overflow-y: auto; }
    .namespace-list .row { padding: 0.35rem 0; border-bottom: 1px solid #252526; }
    .namespace-list .row:last-child { border-bottom: 0; }
    .metrics-message {
      background: rgba(224, 176, 0, 0.1);
      border: 1px solid rgba(224, 176, 0, 0.4);
      color: #e0c060;
      padding: 0.75rem 1rem;
      border-radius: 6px;
      margin-bottom: 1rem;
      font-size: 0.85rem;
      line-height: 1.45;
    }
    .metrics-message a { color: #90c0ff; }
    @media (max-width: 599px) {
      .panel-header { padding: 0.65rem 0.75rem; font-size: 0.85rem; }
      th, td { padding: 0.5rem 0.75rem; font-size: 0.875rem; }
      .stat-card .value { font-size: 1.25rem; }
      .tabs { padding: 0 0.75rem 0; }
      .tab-btn { padding: 0.5rem 0.75rem; font-size: 0.8rem; }
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0">
    <symbol id="icon-cpu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/></symbol>
    <symbol id="icon-mem" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/><path d="M6 8h4M6 12h4M14 8h4M14 12h4"/></symbol>
    <symbol id="icon-drive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="M2 12h4M18 12h4M12 2v4M12 18v4"/></symbol>
  </svg>
</head>
<body>
  <div class="navbar">
    <h1>Cluster Dashboard</h1>
    <span class="badge">Kubernetes</span>
    <span class="live-indicator" id="live-indicator"><span class="live-dot"></span> Live</span>
  </div>
  <div class="container">
    <?php if ($error): ?>
      <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
      <nav class="tabs" role="tablist">
        <button type="button" class="tab-btn active" data-tab="overview" role="tab">Overview</button>
        <button type="button" class="tab-btn" data-tab="nodes" role="tab">Nodes</button>
        <button type="button" class="tab-btn" data-tab="pods" role="tab">Pods</button>
        <button type="button" class="tab-btn" data-tab="metrics" role="tab">Metrics</button>
        <button type="button" class="tab-btn" data-tab="storage" role="tab">Storage</button>
      </nav>

      <div id="tab-overview" class="tab-pane active" role="tabpanel">
      <div class="stats-row" id="stats-row">
        <div class="stat-card">
          <span class="icon-wrap" style="color:#e0b000"><svg><use href="#icon-cpu"/></svg></span>
          <div><div class="value" data-live="nodes"><?php echo count($nodes); ?></div><div class="label">Nodes</div></div>
        </div>
        <div class="stat-card">
          <span class="icon-wrap" style="color:#5794f2"><svg><use href="#icon-mem"/></svg></span>
          <div><div class="value" data-live="pods"><?php echo count($pods); ?></div><div class="label">Pods</div></div>
        </div>
        <div class="stat-card">
          <span class="icon-wrap" style="color:#73bf69"><svg><use href="#icon-mem"/></svg></span>
          <div><div class="value" data-live="running"><?php echo count(array_filter($pods, fn($p) => ($p['status']['phase'] ?? '') === 'Running')); ?></div><div class="label">Running</div></div>
        </div>
        <div class="stat-card" id="stat-cpu" style="display:none">
          <span class="icon-wrap" style="color:#e02f44"><svg><use href="#icon-cpu"/></svg></span>
          <div><div class="value" data-live="cpu">—</div><div class="label">CPU (cores)</div></div>
        </div>
        <div class="stat-card" id="stat-memory" style="display:none">
          <span class="icon-wrap" style="color:#5794f2"><svg><use href="#icon-mem"/></svg></span>
          <div><div class="value" data-live="memory">—</div><div class="label">Memory (Mi)</div></div>
        </div>
        <div class="stat-card" id="stat-storage" style="display:none">
          <span class="icon-wrap" style="color:#9d9d9d"><svg><use href="#icon-drive"/></svg></span>
          <div><div class="value" data-live="storage">—</div><div class="label">Storage (Mi)</div></div>
        </div>
      </div>

      <div id="metrics-message" class="metrics-message" style="display:none" role="status"></div>
      <div class="summary-grid">
        <div class="summary-card">
          <h3>Cluster capacity (all nodes)</h3>
          <div class="row"><span>CPU</span><span class="v"><?php echo $clusterCap['cpu']; ?> cores</span></div>
          <div class="row"><span>Memory</span><span class="v"><?php echo $clusterCap['memory_gi']; ?> Gi</span></div>
        </div>
        <div class="summary-card">
          <h3>Pod status</h3>
          <?php foreach ($phaseCounts as $phase => $cnt): if ($cnt > 0): ?>
          <div class="row"><span class="<?php echo $phase === 'Running' ? 'status-ok' : ($phase === 'Pending' ? 'status-warn' : 'status-bad'); ?>"><?php echo htmlspecialchars($phase); ?></span><span class="v"><?php echo $cnt; ?></span></div>
          <?php endif; endforeach; ?>
        </div>
        <div class="summary-card">
          <h3>Namespaces (<?php echo count($nsCounts); ?>)</h3>
          <div class="namespace-list">
            <?php foreach ($nsCounts as $ns => $cnt): ?>
            <div class="row"><span class="mono"><?php echo htmlspecialchars($ns); ?></span><span class="v"><?php echo $cnt; ?> pods</span></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      </div><!-- /tab-overview -->

      <div id="tab-metrics" class="tab-pane" role="tabpanel">
      <div class="panel" id="panel-live-metrics" style="display:none">
        <div class="panel-header">Live metrics (normalized 0–100% in time window)</div>
        <div class="panel-body" style="padding:1rem 1rem 1.25rem">
          <div class="chart-wrap" id="chart-metrics-wrap">
            <canvas id="chart-metrics"></canvas>
          </div>
        </div>
      </div>

      <div class="panel" id="panel-top-consumers" style="display:none">
        <div class="panel-header">Top consumers</div>
        <div class="panel-body" style="padding:1rem">
          <div class="top-consumers">
            <div>
              <div class="panel-header" style="border-bottom:0;padding:0 0 0.5rem 0">
                <span class="icon-wrap" style="color:#e02f44"><svg><use href="#icon-cpu"/></svg></span> Top CPU (pods)
              </div>
              <div class="table-wrap"><table><thead><tr><th>Pod</th><th>Namespace</th><th>CPU</th></tr></thead><tbody id="top-cpu-tbody"></tbody></table></div>
            </div>
            <div>
              <div class="panel-header" style="border-bottom:0;padding:0 0 0.5rem 0">
                <span class="icon-wrap" style="color:#5794f2"><svg><use href="#icon-mem"/></svg></span> Top Memory (pods)
              </div>
              <div class="table-wrap"><table><thead><tr><th>Pod</th><th>Namespace</th><th>Mi</th></tr></thead><tbody id="top-memory-tbody"></tbody></table></div>
            </div>
          </div>
        </div>
      </div>
      </div><!-- /tab-metrics -->

      <div id="tab-storage" class="tab-pane" role="tabpanel">
      <div class="panel">
        <div class="panel-header"><span class="icon-wrap" style="color:#9d9d9d"><svg><use href="#icon-drive"/></svg></span> Storage</div>
        <div class="panel-body" style="padding:1rem">
          <div id="storage-content">
            <p class="storage-note">Ephemeral storage usage (when reported by metrics-server). Disk read/write speed requires Prometheus and node_exporter.</p>
            <div class="table-wrap"><table id="storage-table" style="display:none"><thead><tr><th>Pod</th><th>Namespace</th><th>Mi</th></tr></thead><tbody id="top-storage-tbody"></tbody></table></div>
          </div>
        </div>
      </div>
      </div><!-- /tab-storage -->

      <div id="tab-nodes" class="tab-pane" role="tabpanel">
      <div class="panel">
        <div class="panel-header">Nodes</div>
        <div class="panel-body">
          <div class="table-wrap"><table>
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
          </table></div>
        </div>
      </div>
      </div><!-- /tab-nodes -->

      <div id="tab-pods" class="tab-pane" role="tabpanel">
      <div class="panel">
        <div class="panel-header">Pods</div>
        <div class="panel-body">
          <div class="table-wrap"><table>
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
          </table></div>
        </div>
      </div>
      </div><!-- /tab-pods -->
    <?php endif; ?>
  </div>
  <?php if (!$error): ?>
  <script>
  (function() {
    var chartMetrics = null;
    var pollInterval = 2000;

    function initTabs() {
      var storageKey = 'cluster-dashboard-tab';
      var btns = document.querySelectorAll('.tab-btn');
      var panes = document.querySelectorAll('.tab-pane');
      var saved = sessionStorage.getItem(storageKey) || 'overview';
      function show(tabId) {
        btns.forEach(function(b) {
          b.classList.toggle('active', b.getAttribute('data-tab') === tabId);
        });
        panes.forEach(function(p) {
          p.classList.toggle('active', p.id === 'tab-' + tabId);
        });
        sessionStorage.setItem(storageKey, tabId);
      }
      btns.forEach(function(b) {
        b.addEventListener('click', function() { show(b.getAttribute('data-tab')); });
      });
      show(saved);
    }
    initTabs();

    function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function updateStats(data) {
      var msgEl = document.getElementById('metrics-message');
      if (msgEl) {
        if (data.metrics_message) {
          msgEl.style.display = '';
          msgEl.textContent = data.metrics_message;
        } else {
          msgEl.style.display = 'none';
        }
      }
      ['nodes','pods','running'].forEach(function(k) {
        var el = document.querySelector('[data-live="' + k + '"]');
        if (el && data[k] !== undefined) el.textContent = data[k];
      });
      if (data.cpu != null) {
        var cpuEl = document.querySelector('[data-live="cpu"]');
        var cpuCard = document.getElementById('stat-cpu');
        if (cpuEl && cpuCard) { cpuEl.textContent = data.cpu.toFixed(2); cpuCard.style.display = ''; }
      }
      if (data.memory != null) {
        var memEl = document.querySelector('[data-live="memory"]');
        var memCard = document.getElementById('stat-memory');
        if (memEl && memCard) { memEl.textContent = data.memory; memCard.style.display = ''; }
      }
      if (data.storage_mi != null) {
        var stEl = document.querySelector('[data-live="storage"]');
        var stCard = document.getElementById('stat-storage');
        if (stEl && stCard) { stEl.textContent = data.storage_mi; stCard.style.display = ''; }
      }
      var topCpu = data.top_cpu_pods || [];
      var topMem = data.top_memory_pods || [];
      if (topCpu.length || topMem.length) {
        var panel = document.getElementById('panel-top-consumers');
        if (panel) panel.style.display = '';
        function heatColor(pct) {
          if (pct <= 0) return 'rgb(115, 191, 105)';
          if (pct >= 1) return 'rgb(224, 47, 68)';
          if (pct < 0.5) {
            var t = pct * 2;
            return 'rgb(' + Math.round(115 + (224 - 115) * t) + ',' + Math.round(191 + (47 - 191) * t) + ',' + Math.round(105 + (68 - 105) * t) + ')';
          }
          var t = (pct - 0.5) * 2;
          return 'rgb(' + Math.round(224) + ',' + Math.round(176 + (47 - 176) * t) + ',' + Math.round(0 + (68 - 0) * t) + ')';
        }
        var maxCpu = topCpu.length ? Math.max.apply(null, topCpu.map(function(p) { return p.cpu; })) : 1;
        var maxMem = topMem.length ? Math.max.apply(null, topMem.map(function(p) { return p.memory_mi; })) : 1;
        if (maxCpu <= 0) maxCpu = 1;
        if (maxMem <= 0) maxMem = 1;
        var cpuTbody = document.getElementById('top-cpu-tbody');
        if (cpuTbody) cpuTbody.innerHTML = topCpu.slice(0, 10).map(function(p) {
          var pct = maxCpu > 0 ? Math.min(1, p.cpu / maxCpu) : 0;
          var color = heatColor(pct);
          return '<tr><td class="mono">' + escapeHtml(p.name) + '</td><td class="mono">' + escapeHtml(p.namespace) + '</td><td class="cell-bar"><span class="bar-wrap"><span class="bar-fill" style="width:' + (pct * 100) + '%;background:' + color + '"></span><span class="bar-value">' + p.cpu + '</span></span></td></tr>';
        }).join('');
        var memTbody = document.getElementById('top-memory-tbody');
        if (memTbody) memTbody.innerHTML = topMem.slice(0, 10).map(function(p) {
          var pct = maxMem > 0 ? Math.min(1, p.memory_mi / maxMem) : 0;
          var color = heatColor(pct);
          return '<tr><td class="mono">' + escapeHtml(p.name) + '</td><td class="mono">' + escapeHtml(p.namespace) + '</td><td class="cell-bar"><span class="bar-wrap"><span class="bar-fill" style="width:' + (pct * 100) + '%;background:' + color + '"></span><span class="bar-value">' + p.memory_mi + '</span></span></td></tr>';
        }).join('');
      }
      var topStorage = data.top_storage_pods || [];
      var storageTable = document.getElementById('storage-table');
      var storageTbody = document.getElementById('top-storage-tbody');
      if (topStorage.length && storageTable && storageTbody) {
        storageTable.style.display = '';
        storageTbody.innerHTML = topStorage.slice(0, 10).map(function(p) { return '<tr><td class="mono">' + escapeHtml(p.name) + '</td><td class="mono">' + escapeHtml(p.namespace) + '</td><td>' + p.storage_mi + '</td></tr>'; }).join('');
      }
    }

    function normSeries(arr) {
      var valid = arr.filter(function(v) { return v != null && isFinite(v); });
      if (valid.length === 0) return arr.map(function() { return null; });
      var min = Math.min.apply(null, valid);
      var max = Math.max.apply(null, valid);
      var range = max - min;
      if (range === 0) return arr.map(function(v) { return v != null ? 50 : null; });
      return arr.map(function(v) { return v != null ? Math.round((v - min) / range * 100) : null; });
    }
    function initCharts(history) {
      if (!history || history.length === 0) return;
      var hasMetrics = history.some(function(h) { return h.cpu != null || h.memory != null || h.max_pod_cpu != null || h.max_pod_memory != null; });
      if (!hasMetrics) return;
      var panel = document.getElementById('panel-live-metrics');
      if (panel) panel.style.display = '';
      var labels = history.map(function(h) { return new Date(h.t * 1000).toLocaleTimeString(); });
      var rawCpu = history.map(function(h) { return h.cpu != null ? h.cpu : null; });
      var rawMem = history.map(function(h) { return h.memory != null ? h.memory : null; });
      var rawMaxCpu = history.map(function(h) { return h.max_pod_cpu != null ? h.max_pod_cpu : null; });
      var rawMaxMem = history.map(function(h) { return h.max_pod_memory != null ? h.max_pod_memory : null; });
      var opts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#9d9d9d' } } },
        scales: {
          x: { ticks: { color: '#6d6d6d', maxTicksLimit: 8 } },
          y: {
            min: 0,
            max: 100,
            ticks: { color: '#6d6d6d', callback: function(v) { return v + '%'; } }
          }
        }
      };
      var chartColors = [
        { border: 'rgba(248, 113, 113, 0.95)', fill: ['rgba(248, 113, 113, 0.25)', 'rgba(248, 113, 113, 0.02)'] },
        { border: 'rgba(96, 165, 250, 0.95)', fill: ['rgba(96, 165, 250, 0.25)', 'rgba(96, 165, 250, 0.02)'] },
        { border: 'rgba(251, 146, 60, 0.9)', fill: ['rgba(251, 146, 60, 0.2)', 'rgba(251, 146, 60, 0.02)'] },
        { border: 'rgba(74, 222, 128, 0.9)', fill: ['rgba(74, 222, 128, 0.2)', 'rgba(74, 222, 128, 0.02)'] }
      ];
      function makeGradient(ctx, chart, fillStops) {
        var h = (chart && chart.height) ? chart.height : 260;
        var g = ctx.createLinearGradient(0, 0, 0, h);
        g.addColorStop(0, fillStops[0]);
        g.addColorStop(1, fillStops[1]);
        return g;
      }
      var ds = [
        { label: 'Cluster CPU (%)', data: normSeries(rawCpu), borderColor: chartColors[0].border, backgroundColor: function(c) { var ch = c.chart; return makeGradient(ch.ctx, ch, chartColors[0].fill); }, fill: true, tension: 0.4, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4 },
        { label: 'Cluster Memory (%)', data: normSeries(rawMem), borderColor: chartColors[1].border, backgroundColor: function(c) { var ch = c.chart; return makeGradient(ch.ctx, ch, chartColors[1].fill); }, fill: true, tension: 0.4, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4 },
        { label: 'Max pod CPU (%)', data: normSeries(rawMaxCpu), borderColor: chartColors[2].border, backgroundColor: function(c) { var ch = c.chart; return makeGradient(ch.ctx, ch, chartColors[2].fill); }, fill: true, tension: 0.4, borderWidth: 2, borderDash: [6, 4], pointRadius: 0, pointHoverRadius: 4 },
        { label: 'Max pod Memory (%)', data: normSeries(rawMaxMem), borderColor: chartColors[3].border, backgroundColor: function(c) { var ch = c.chart; return makeGradient(ch.ctx, ch, chartColors[3].fill); }, fill: true, tension: 0.4, borderWidth: 2, borderDash: [6, 4], pointRadius: 0, pointHoverRadius: 4 }
      ];
      opts.layout = { padding: { top: 8, right: 12, bottom: 4, left: 4 } };
      opts.elements = { line: { borderJoinStyle: 'round', borderCapStyle: 'round' } };
      opts.plugins.legend = { labels: { color: '#9d9d9d', usePointStyle: true, padding: 16 } };
      opts.scales.y.grid = { color: 'rgba(255,255,255,0.06)' };
      opts.scales.x.grid = { color: 'rgba(255,255,255,0.04)' };
      if (!chartMetrics) {
        chartMetrics = new Chart(document.getElementById('chart-metrics'), {
          type: 'line',
          data: { labels: labels, datasets: ds },
          options: opts
        });
      } else {
        chartMetrics.data.labels = labels;
        chartMetrics.data.datasets[0].data = normSeries(rawCpu);
        chartMetrics.data.datasets[1].data = normSeries(rawMem);
        chartMetrics.data.datasets[2].data = normSeries(rawMaxCpu);
        chartMetrics.data.datasets[3].data = normSeries(rawMaxMem);
        chartMetrics.update('none');
      }
    }

    function poll() {
      fetch('data.php').then(function(r) { return r.json(); }).then(function(data) {
        updateStats(data);
        if (data.history && data.history.length) initCharts(data.history);
      }).catch(function() {});
    }

    poll();
    setInterval(poll, pollInterval);
  })();
  </script>
  <?php endif; ?>
</body>
</html>
