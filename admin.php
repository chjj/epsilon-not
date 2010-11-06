<?php
	require($_SERVER['DOCUMENT_ROOT'].'/init.php');
	
	$page = new page();
	$page->title = 'Admin Panel';
	
	if (isset($_POST['password']) && hash('tiger192,4', $_POST['password']) == APP_PASSWORD) {
		setcookie('epsilon_user', base64_encode($_POST['password']), time()+(2*7*24*60*60), '/');
		page::redirect(page::uri('admin'));
	} elseif (isset($_GET[0]) && $_GET[0] == 'logout') {
		setcookie('epsilon_user', '', time()-60, '/');
		page::redirect(page::uri());
	}
	
	if (APP_LOGIN) {
		$content = '
			<p>More stuff here soon.</p>
			<ul>
				<li><a href="'.page::uri('post').'">Post Article</a></li>
				<li><a href="'.page::uri('admin', 'logout').'">Logout</a></li>
			</ul>
		';
	} else {
		$content = '
			<form method="post" action="'.page::uri('admin').'">
				<label>Password: <input type="password" name="password" required="" /></label>
				<input type="submit" value="Login" />
			</form>
		';
	}
	
	$page->content = template::parse('section', array(
		'title' => $page->title, 
		'content' => preg_replace('/(\r?\n|\r\n?)\t/', '$1', $content)
	));
	
	exit($page->build());
?>