<?php
// Live test — simulates ?lat=10.5333&lng=122.9333 (Bago City area)
$_GET['lat'] = '10.5333';
$_GET['lng'] = '122.9333';
include __DIR__ . '/nearest_hospital.php';
