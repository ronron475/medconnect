<?php
require __DIR__ . '/../../bootstrap.php';
// Lightweight: DomainScope + MessageGuard + AiFallback if possible without full DB
require_once BASE_PATH . '/app/core/FaqChatbotDomainScope.php';
require_once BASE_PATH . '/app/core/FaqChatbotMessageGuard.php';

$inputs = ['lkfdlkgrl','asdfgh','hello','masakit akon ulo','masakit akon olo','How do I book an appointment?','haha test lang'];
foreach ($inputs as $t) {
  $g = FaqChatbotMessageGuard::evaluate($t, 'probe');
  $c = FaqChatbotDomainScope::classify($t);
  $unclear = FaqChatbotDomainScope::looksUnclear($t) ? 'Y' : 'N';
  $health = FaqChatbotDomainScope::isHealthcareRelated($t) ? 'Y' : 'N';
  echo $t, " | guard=", $g['action'] ?? '?', " score=", $g['score'] ?? '?', " | scope=", $c['scope'] ?? '?', " unclear=$unclear health=$health\n";
}
