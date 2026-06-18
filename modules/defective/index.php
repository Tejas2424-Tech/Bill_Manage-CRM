<?php
/**
 * Defective Replacement now lives in the unified Exchange / Replacement page.
 * Redirect any old links/bookmarks there, pre-selecting defective mode.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$mobile = isset($_GET['mobile']) ? '&mobile=' . urlencode($_GET['mobile']) : '';
redirect(BASE_URL . '/modules/exchange/index.php?mode=defective' . $mobile);
