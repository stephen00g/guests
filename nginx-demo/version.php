<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
$v = null;
$versionFile = __DIR__ . '/VERSION';
if (is_readable($versionFile)) {
  $raw = trim((string)file_get_contents($versionFile));
  if ($raw !== '') $v = $raw;
}
if ($v === null && is_readable(__DIR__ . '/index.php')) {
  $v = date('Y-m-d H:i:s', filemtime(__DIR__ . '/index.php'));
}
echo json_encode(['version' => $v ?? 'unknown']);
