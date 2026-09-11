<?php
/**
 * BHW no longer creates clinical referrals. Redirect to community follow-up.
 */
header('Location: status.php', true, 302);
exit;
