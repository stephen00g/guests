<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$token_path = '/var/run/secrets/kubernetes.io/serviceaccount/token';
$ca_path = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
$api = 'https://kubernetes.default.svc';
$history_file = '/tmp/dashboard_history.json';
$max_history = 120;

$out = [
  'nodes' => 0, 'pods' => 0, 'running' => 0,
  'cpu' => null, 'memory' => null,
  'top_cpu_pods' => [], 'top_memory_pods' => [],
  'max_pod_cpu' => null, 'max_pod_memory' => null,
  'storage_mi' => null, 'top_storage_pods' => [],
  'history' => []
];

$token = (file_exists($token_path) && is_readable($token_path)) ? trim((string)@file_get_contents($token_path)) : '';
if ($token === '' || !file_exists($ca_path)) {
  echo json_encode($out);
  exit;
}
$ch = function($path) use ($api, $token, $ca_path) {
  $c = curl_init($api . $path);
  curl_setopt_array($c, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    CURLOPT_CAINFO => $ca_path,
    CURLOPT_TIMEOUT => 4,
  ]);
  $body = curl_exec($c);
  $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
  curl_close($c);
  return $code === 200 ? json_decode($body, true) : null;
};

function parseQuantity($s) {
  $s = trim((string)$s);
  if (preg_match('/^(\d+)n$/', $s, $m)) return (int)$m[1] / 1e9;
  if (preg_match('/^(\d+)u$/', $s, $m)) return (int)$m[1] / 1e6;
  if (preg_match('/^(\d+)m$/', $s, $m)) return (int)$m[1] / 1e3;
  if (preg_match('/^(\d+)$/', $s, $m)) return (int)$m[1];
  if (preg_match('/^(\d+)Ki$/', $s, $m)) return (int)$m[1] * 1024;
  if (preg_match('/^(\d+)Mi$/', $s, $m)) return (int)$m[1] * 1024 * 1024;
  if (preg_match('/^(\d+)Gi$/', $s, $m)) return (int)$m[1] * 1024 * 1024 * 1024;
  return 0;
}

$nodesData = $ch('/api/v1/nodes');
$podsData = $ch('/api/v1/pods?limit=500');
if ($nodesData === null && $podsData === null) {
  echo json_encode($out);
  exit;
}

$nodes = $nodesData['items'] ?? [];
$pods = $podsData['items'] ?? [];
$running = count(array_filter($pods, fn($p) => ($p['status']['phase'] ?? '') === 'Running'));

$cpu = null;
$memory = null;
$nodeMetrics = $ch('/apis/metrics.k8s.io/v1beta1/nodes');
if ($nodeMetrics !== null && !empty($nodeMetrics['items'])) {
  $cpu = 0;
  $memory = 0;
  foreach ($nodeMetrics['items'] as $m) {
    $cpu += parseQuantity($m['usage']['cpu'] ?? '0');
    $memory += parseQuantity($m['usage']['memory'] ?? '0');
  }
  $memory = round($memory / (1024 * 1024), 1);
}

$podMetrics = $ch('/apis/metrics.k8s.io/v1beta1/pods?limit=500');
$podUsageList = [];
$maxPodCpu = null;
$maxPodMem = null;
$storageTotal = 0;
$storageList = [];
$topCpuPods = [];
$topMemoryPods = [];
$topStoragePods = [];

if ($podMetrics !== null && !empty($podMetrics['items'])) {
  foreach ($podMetrics['items'] as $pm) {
    $ns = $pm['metadata']['namespace'] ?? '';
    $name = $pm['metadata']['name'] ?? '';
    $podCpu = 0;
    $podMem = 0;
    $podStorage = 0;
    foreach ($pm['containers'] ?? [] as $cont) {
      $u = $cont['usage'] ?? [];
      $podCpu += parseQuantity($u['cpu'] ?? '0');
      $podMem += parseQuantity($u['memory'] ?? '0');
      if (isset($u['ephemeral-storage'])) {
        $podStorage += parseQuantity($u['ephemeral-storage']);
      }
    }
    $podMemMi = round($podMem / (1024 * 1024), 2);
    $podStorageMi = round($podStorage / (1024 * 1024), 1);
    $podUsageList[] = ['namespace' => $ns, 'name' => $name, 'cpu' => round($podCpu, 3), 'memory_mi' => $podMemMi, 'storage_mi' => $podStorageMi];
    if ($podCpu > 0 && ($maxPodCpu === null || $podCpu > $maxPodCpu)) $maxPodCpu = $podCpu;
    if ($podMemMi > 0 && ($maxPodMem === null || $podMemMi > $maxPodMem)) $maxPodMem = $podMemMi;
    if ($podStorageMi > 0) {
      $storageTotal += $podStorageMi;
      $storageList[] = ['namespace' => $ns, 'name' => $name, 'storage_mi' => $podStorageMi];
    }
  }
  usort($podUsageList, fn($a, $b) => $b['cpu'] <=> $a['cpu']);
  $topCpuPods = array_slice($podUsageList, 0, 10);
  usort($podUsageList, fn($a, $b) => $b['memory_mi'] <=> $a['memory_mi']);
  $topMemoryPods = array_slice($podUsageList, 0, 10);
  usort($storageList, fn($a, $b) => $b['storage_mi'] <=> $a['storage_mi']);
  $topStoragePods = array_slice($storageList, 0, 10);
}

$out['nodes'] = count($nodes);
$out['pods'] = count($pods);
$out['running'] = $running;
$out['cpu'] = $cpu;
$out['memory'] = $memory;
$out['top_cpu_pods'] = $topCpuPods;
$out['top_memory_pods'] = $topMemoryPods;
$out['max_pod_cpu'] = $maxPodCpu;
$out['max_pod_memory'] = $maxPodMem;
$out['storage_mi'] = $storageTotal > 0 ? round($storageTotal, 1) : null;
$out['top_storage_pods'] = $topStoragePods;
$out['t'] = time();

$history = [];
if (is_readable($history_file)) {
  $raw = @file_get_contents($history_file);
  if ($raw !== false) $history = json_decode($raw, true) ?: [];
}
$history[] = [
  't' => $out['t'],
  'nodes' => $out['nodes'],
  'pods' => $out['pods'],
  'running' => $out['running'],
  'cpu' => $out['cpu'],
  'memory' => $out['memory'],
  'max_pod_cpu' => $out['max_pod_cpu'],
  'max_pod_memory' => $out['max_pod_memory'],
];
if (count($history) > $max_history) $history = array_slice($history, -$max_history);
@file_put_contents($history_file, json_encode($history), LOCK_EX);

$out['history'] = $history;
echo json_encode($out);
