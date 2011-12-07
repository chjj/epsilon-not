<?php
require('./init.php');

//RewriteRule ^.+\.css$ /.source/style.php [NC,L]
//$css = APP_DIR.current(explode('?', $_SERVER['REQUEST_URI'], 2));

$page = new page('text/css', ($css = APP_DIR.'/front/style.css'));

if (file_exists($css) && ($css = file_get_contents($css))) {
  // variable support
  if (preg_match_all('/@var\s+(\$[^\s;]+)\s+([^\n;]+);/i', $css, $m)) {
    $vars = array();
    foreach ($m[0] as $i => $_) $vars[$m[1][$i]] = $m[2][$i];
    uksort($vars, create_function(
      '$a, $b',
      'return strlen($a) > strlen($b) ? -1 : 1;'
    ));
    $css = str_replace(array_keys($vars), array_values($vars), $css);
    $css = preg_replace('/@var[^;]+;\s*/', '', $css);
  }

  // base64 encode images
  $css = preg_replace_callback('/url\(([^)]+)\)/', create_function('$m', '
    $p = APP_DIR."/".ltrim(trim($m[1], "\'\\""), "/");
    if (!stristr($m[1], ".png") || (!$f=@file_get_contents($p))) return $m[0];
    return "url(\\"data:image/png;base64,".base64_encode($f)."\\")";
  '), $css);

  // minify on live
  if (APP_HOST !== '127.0.0.1') $css = preg_replace(array(
    '/\/\*.*?\*\//s', '/\s+({|})/', '/(;|,|:|{|}|^)\s+/',
    '/\s+(>|\+|~)\s+/', '/\\\r?\n\s+/', '/;(})/'
  ), '$1', $css);

  exit($page->output($css));
} else {
  $page->error(404);
}
?>