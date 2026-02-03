<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$token_path = '/var/run/secrets/kubernetes.io/serviceaccount/token';
$ca_path = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
$api = 'https://kubernetes.default.svc';
$history_file = '/tmp/dashboard_history.json';
$max_history = 120;

$out = [ 'nodes' => 0, 'pods' => 0, 'running' => 0, 'cpu' => null, 'memory' => null, 'history' => [] ];

if (!file_exists($token_path) || !file_exists($ca_path)) {
  echo json_encode($out);
  exit;
}

$token = trim(file_get_contents($token_path));
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

function parseQuantity($s) {
  $s = trim($s);
  if (preg_match('/^(\d+)n$/', $s, $m)) return (int)$m[1] / 1e9;
  if (preg_match('/^(\d+)u$/', $s, $m)) return (int)$m[1] / 1e6;
  if (preg_match('/^(\d+)m$/', $s, $m)) return (int)$m[1] / 1e3;
  if (preg_match('/^(\d+)$/', $s, $m)) return (int)$m[1];
  if (preg_match('/^(\d+)Ki$/', $s, $m)) return (int)$m[1] * 1024;
  if (preg_match('/^(\d+)Mi$/', $s, $m)) return (int)$m[1] * 1024 * 1024;
  if (preg_match('/^(\d+)Gi$/', $s, $m)) return (int)$m[1] * 1024 * 1024 * 1024;
  return 0;
}

$out['nodes'] = count($nodes);
$out['pods'] = count($pods);
$out['running'] = $running;
$out['cpu'] = $cpu;
$out['memory'] = $memory;
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
];
if (count($history) > $max_history) $history = array_slice($history, -$max_history);
@file_put_contents($history_file, json_encode($history), LOCK_EX);

$out['history'] = $history;
echo json_encode($out);
