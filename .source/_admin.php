<?php
require('./init.php');

page_cache(APP_CACHE);
$path = page_path();

if (isset($_POST['password']) 
  && hash('tiger192,4', $_POST['password']) === APP_PASSWORD) { 
  header('Set-Cookie:'
    .'user='.rawurlencode(base64_encode($_POST['password']))
    .'; expires='.gmdate('D, d-M-Y h:i:s', time()+(30*24*60*60)).' GMT'
    .'; path=/'
  );
  redirect(uri('admin'));
} elseif (isset($path[1]) && $path[1] === 'logout') {
  header(
    'Set-Cookie: user=none; expires='
    .gmdate('D, d-M-Y h:i:s', time()-60).' GMT; path=/'
  );
  redirect(uri());
}

if (APP_LOGIN) {
  if (isset($_POST['clear'])) { 
    foreach (glob(APP_CACHE.'/*') as $file) {
      if (is_file($file)) unlink($file);
    }
    redirect(uri('admin'));
  }
}

$content = render('section', array(
  'title' => 'Admin Panel', 
  'content' => template_parse('admin', array(
    'login' => APP_LOGIN
  ))
));

exit(page_build($content, 'Admin Panel', 'admin'));
?>