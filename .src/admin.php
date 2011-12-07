<?php
require('./init.php');

$page = new page();
$page->title = 'Admin Panel';
$page->name = 'admin';

if (isset($_POST['password'])
    && hash('tiger192,4', $_POST['password']) === APP_PASSWORD) {
  header('Set-Cookie:'
    .'user='.rawurlencode(base64_encode($_POST['password']))
    .'; expires='.gmdate('D, d-M-Y h:i:s', time() + (30*24*60*60)).' GMT'
    .'; path=/'
  );
  page::redirect(page::uri('admin'));
} elseif (isset($page->path[1]) && $page->path[1] === 'logout') {
  header(
    'Set-Cookie:user=none; expires='
    .gmdate('D, d-M-Y h:i:s', time() - 60).' GMT; path=/'
  );
  page::redirect(page::uri());
}

if (APP_LOGIN) {
  if (isset($_POST['clear'])) {
    foreach (glob(APP_CACHE.'/*') as $file) {
      if (is_file($file)) unlink($file);
    }
    page::redirect(page::uri('admin'));
  }
}

$page->content = template::parse('section', array(
  'title' => $page->title,
  'content' => template::parse('admin', array(
    'login' => APP_LOGIN
  ))
));

exit($page->build());
?>