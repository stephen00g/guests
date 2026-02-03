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
    .stat-card { display: flex; align-items: flex-start; gap: 0.6rem; }
    .stat-card .icon-wrap { flex-shrink: 0; margin-top: 0.15rem; }
    .stat-card .icon-wrap svg { width: 20px; height: 20px; opacity: 0.9; }
    .stat-card .value { font-size: 1.5rem; font-weight: 600; color: #5794f2; }
    .stat-card .label { color: #9d9d9d; font-size: 0.75rem; margin-top: 0.25rem; }
    .live-indicator { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.7rem; color: #73bf69; margin-left: auto; }
    .live-dot { width: 6px; height: 6px; border-radius: 50%; background: #73bf69; animation: pulse 1.5s ease-in-out infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
    .charts-row { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    .chart-wrap { position: relative; height: 200px; }
    .top-consumers { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 800px) { .top-consumers { grid-template-columns: 1fr; } }
    .panel-header .icon-wrap { display: inline-flex; align-items: center; gap: 0.4rem; }
    .panel-header .icon-wrap svg { width: 18px; height: 18px; opacity: 0.9; }
    .storage-note { color: #9d9d9d; font-size: 0.8rem; margin-top: 0.5rem; }
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

      <div class="panel">
        <div class="panel-header">Live metrics</div>
        <div class="panel-body" style="padding:1rem">
          <div class="charts-row">
            <div class="chart-wrap">
              <canvas id="chart-counts"></canvas>
            </div>
            <div class="chart-wrap" id="chart-metrics-wrap" style="display:none">
              <canvas id="chart-metrics"></canvas>
            </div>
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
              <table><thead><tr><th>Pod</th><th>Namespace</th><th>CPU</th></tr></thead><tbody id="top-cpu-tbody"></tbody></table>
            </div>
            <div>
              <div class="panel-header" style="border-bottom:0;padding:0 0 0.5rem 0">
                <span class="icon-wrap" style="color:#5794f2"><svg><use href="#icon-mem"/></svg></span> Top Memory (pods)
              </div>
              <table><thead><tr><th>Pod</th><th>Namespace</th><th>Mi</th></tr></thead><tbody id="top-memory-tbody"></tbody></table>
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header"><span class="icon-wrap" style="color:#9d9d9d"><svg><use href="#icon-drive"/></svg></span> Storage</div>
        <div class="panel-body" style="padding:1rem">
          <div id="storage-content">
            <p class="storage-note">Ephemeral storage usage (when reported by metrics-server). Disk read/write speed requires Prometheus and node_exporter.</p>
            <table id="storage-table" style="display:none"><thead><tr><th>Pod</th><th>Namespace</th><th>Mi</th></tr></thead><tbody id="top-storage-tbody"></tbody></table>
          </div>
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
  <?php if (!$error): ?>
  <script>
  (function() {
    var chartCounts = null;
    var chartMetrics = null;
    var pollInterval = 2000;

    function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function updateStats(data) {
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
        var cpuTbody = document.getElementById('top-cpu-tbody');
        if (cpuTbody) cpuTbody.innerHTML = topCpu.slice(0, 10).map(function(p) { return '<tr><td class="mono">' + escapeHtml(p.name) + '</td><td class="mono">' + escapeHtml(p.namespace) + '</td><td>' + p.cpu + '</td></tr>'; }).join('');
        var memTbody = document.getElementById('top-memory-tbody');
        if (memTbody) memTbody.innerHTML = topMem.slice(0, 10).map(function(p) { return '<tr><td class="mono">' + escapeHtml(p.name) + '</td><td class="mono">' + escapeHtml(p.namespace) + '</td><td>' + p.memory_mi + '</td></tr>'; }).join('');
      }
      var topStorage = data.top_storage_pods || [];
      var storageTable = document.getElementById('storage-table');
      var storageTbody = document.getElementById('top-storage-tbody');
      if (topStorage.length && storageTable && storageTbody) {
        storageTable.style.display = '';
        storageTbody.innerHTML = topStorage.slice(0, 10).map(function(p) { return '<tr><td class="mono">' + escapeHtml(p.name) + '</td><td class="mono">' + escapeHtml(p.namespace) + '</td><td>' + p.storage_mi + '</td></tr>'; }).join('');
      }
    }

    function initCharts(history) {
      if (!history || history.length === 0) return;
      var labels = history.map(function(h) { return new Date(h.t * 1000).toLocaleTimeString(); });
      var opts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#9d9d9d' } } },
        scales: {
          x: { ticks: { color: '#6d6d6d', maxTicksLimit: 8 } },
          y: { ticks: { color: '#6d6d6d' } }
        }
      };
      if (!chartCounts) {
        chartCounts = new Chart(document.getElementById('chart-counts'), {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              { label: 'Pods', data: history.map(function(h) { return h.pods; }), borderColor: '#5794f2', backgroundColor: 'rgba(87,148,242,0.1)', fill: true, tension: 0.3 },
              { label: 'Running', data: history.map(function(h) { return h.running; }), borderColor: '#73bf69', backgroundColor: 'rgba(115,191,105,0.1)', fill: true, tension: 0.3 },
              { label: 'Nodes', data: history.map(function(h) { return h.nodes; }), borderColor: '#e0b000', backgroundColor: 'rgba(224,176,0,0.05)', fill: true, tension: 0.3 }
            ]
          },
          options: opts
        });
      } else {
        chartCounts.data.labels = labels;
        chartCounts.data.datasets[0].data = history.map(function(h) { return h.pods; });
        chartCounts.data.datasets[1].data = history.map(function(h) { return h.running; });
        chartCounts.data.datasets[2].data = history.map(function(h) { return h.nodes; });
        chartCounts.update('none');
      }
      var hasMetrics = history.some(function(h) { return h.cpu != null || h.memory != null || h.max_pod_cpu != null || h.max_pod_memory != null; });
      if (hasMetrics) {
        var wrap = document.getElementById('chart-metrics-wrap');
        if (wrap) wrap.style.display = '';
        var ds = [
          { label: 'Cluster CPU', data: history.map(function(h) { return h.cpu != null ? h.cpu : null; }), borderColor: '#e02f44', backgroundColor: 'rgba(224,47,68,0.1)', fill: true, tension: 0.3 },
          { label: 'Cluster Memory (Mi)', data: history.map(function(h) { return h.memory != null ? h.memory : null; }), borderColor: '#5794f2', backgroundColor: 'rgba(87,148,242,0.1)', fill: true, tension: 0.3 },
          { label: 'Max pod CPU', data: history.map(function(h) { return h.max_pod_cpu != null ? h.max_pod_cpu : null; }), borderColor: '#ff6b6b', backgroundColor: 'rgba(255,107,107,0.05)', fill: true, tension: 0.3, borderDash: [4,2] },
          { label: 'Max pod Memory (Mi)', data: history.map(function(h) { return h.max_pod_memory != null ? h.max_pod_memory : null; }), borderColor: '#73bf69', backgroundColor: 'rgba(115,191,105,0.05)', fill: true, tension: 0.3, borderDash: [4,2] }
        ];
        if (!chartMetrics) {
          chartMetrics = new Chart(document.getElementById('chart-metrics'), {
            type: 'line',
            data: { labels: labels, datasets: ds },
            options: opts
          });
        } else {
          chartMetrics.data.labels = labels;
          chartMetrics.data.datasets[0].data = history.map(function(h) { return h.cpu != null ? h.cpu : null; });
          chartMetrics.data.datasets[1].data = history.map(function(h) { return h.memory != null ? h.memory : null; });
          chartMetrics.data.datasets[2].data = history.map(function(h) { return h.max_pod_cpu != null ? h.max_pod_cpu : null; });
          chartMetrics.data.datasets[3].data = history.map(function(h) { return h.max_pod_memory != null ? h.max_pod_memory : null; });
          chartMetrics.update('none');
        }
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
