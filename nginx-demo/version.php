<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
$v = '—';
if (file_exists(__DIR__ . '/VERSION')) {
  $v = trim((string)file_get_contents(__DIR__ . '/VERSION'));
}
echo json_encode(['version' => $v]);
