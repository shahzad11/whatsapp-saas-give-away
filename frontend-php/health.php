<?php
// Container healthcheck endpoint. Deliberately reveals nothing beyond
// liveness — no version, no database name, no backend address.
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
