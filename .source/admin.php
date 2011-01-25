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
	//a simple command line to test code on my local server
	if ($_SERVER['HTTP_HOST'] == 'localhost' && isset($_POST['code'])) {
		$output = eval($_POST['code']);
		if (@$_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
			@header('Content-Type: text/plain; charset=utf-8');
			if ($output) echo($output);
			exit;
		}
	}
}

$page->content = template::parse('section', array(
	'title' => $page->title, 
	'content' => template::parse('admin', array(
		'login' => APP_LOGIN,
		'local' => ($_SERVER['HTTP_HOST'] == 'localhost'),
		'code' => (isset($_POST['code']) ? $_POST['code'] : 'return 1;'),
		'output' => (isset($output) ? $output : 'No result.')
	))
));

exit($page->build());
?>