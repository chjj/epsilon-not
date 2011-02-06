<?php
require('./init.php');

$page = new page();
$page->title = 'Admin Panel';

if (isset($_POST['password']) && hash('tiger192,4', $_POST['password']) == APP_PASSWORD) {
	setcookie('epsilon_user', base64_encode($_POST['password']), time()+(2*7*24*60*60), '/');
	page::redirect(page::uri('admin'));
} elseif (isset($page->path[1]) && $page->path[1] == 'logout') {
	setcookie('epsilon_user', '', time()-60, '/');
	page::redirect(page::uri());
}

if (APP_LOGIN) {
	if (isset($page->path[1]) && $page->path[1] == 'clear') {
		foreach (glob(APP_CACHE.'/*') as $file) 
			if (is_file($file)) unlink($file); //perhaps make this a separate function?
		page::redirect(page::uri('admin'));
	}
	//a simple command line to test code on my local server
	if ($_SERVER['SERVER_ADDR'] == '127.0.0.1' && isset($_POST['code'])) {
		ob_start();
		$output = eval($_POST['code']);
		$output = ob_get_clean().$output;
		if (@$_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
			header('Content-Type: text/plain; charset=utf-8');
			exit($output);
		}
	}
}

$page->content = template::parse('section', array(
	'title' => $page->title, 
	'content' => template::parse('admin', array(
		'login' => APP_LOGIN,
		'local' => ($_SERVER['SERVER_ADDR'] == '127.0.0.1'),
		'code' => (isset($_POST['code']) ? $_POST['code'] : 'return 1;'),
		'output' => (isset($output) ? $output : 'No result.')
	))
));

exit($page->build());
?>