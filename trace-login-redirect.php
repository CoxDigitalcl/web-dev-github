<?php
/**
 * IECT – Trace login redirects (read-only logger)
 * Loggea quién dispara redirecciones a login para encontrar al culpable.
 */
if (!defined('ABSPATH')) exit;

add_filter('wp_redirect', function($location, $status){
  // ¿Nos están enviando al login?
  if (strpos($location, '/login') !== false || strpos($location, 'wp-login.php') !== false) {
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $lines = [];
    foreach ($bt as $i => $t) {
      $fn   = ($t['class'] ?? '').($t['type'] ?? '').($t['function'] ?? '');
      $file = isset($t['file']) ? basename($t['file']) : '';
      $line = $t['line'] ?? '';
      $lines[] = "#$i $fn $file:$line";
    }
    @file_put_contents(
      WP_CONTENT_DIR.'/login-redirect.log',
      date('c')." URI=".$_SERVER['REQUEST_URI']."\n".
      "REDIRECT TO: ".$location."\n".
      implode("\n",$lines)."\n\n",
      FILE_APPEND
    );
  }
  return $location; // no cambiamos nada
}, 999, 2);
