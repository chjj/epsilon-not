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
			<p>Welcome back! Options are below. More options to come.</p>
			<h3>Options</h3>
			<ul>
				<li><a href="'.page::uri('post').'">Post Article</a></li>
				<li><a href="'.page::uri('admin', 'logout').'">Logout</a></li>
			</ul>
		';
		
		//a simple command line to test code on my local server
		if ($_SERVER['HTTP_HOST'] == 'localhost') {
			if (isset($_POST['code'])) {
				$output = eval($_POST['code']);
				if (@$_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
					header('Content-Type: text/plain; charset=utf-8');
					if ($output) echo($output);
					exit;
				}
			}
			$content .= preg_replace('/(\n)\t/', '$1', '
				<h3>Command Line</h3>
				<pre>'.(isset($output) ? $output : 'No result.').'</pre>
				<form method="POST" action="'.page::uri('admin').'">
					<label>Code:<textarea name="code" spellcheck="false">'.
						(isset($output) ? $output : 'return 1;').'</textarea></label>
					<input type="submit" value="Execute" />
				</form>
				<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
				<script>
					$(\'form\').bind(\'submit\', function() {
						if ($(\'textarea\').val().match(/^\$\s+/)) {
							$(\'pre\').text(eval(\'(function() { \
								\'+$(\'textarea\').val().replace(/^\$\s+/, \'\')+\' \
							})();\'));
						} else {
							$.post(\''.page::uri('admin').'\', $(this).serialize(), 
								function(data) { $(\'pre\').html(data); }
							);
						}
						$(\'input[type="submit"]\').blur();
						return false;
					}); 
				</script>
			');
		}
	} else {
		$content = '
			<form method="POST" action="'.page::uri('admin').'">
				<label>Password: <input type="password" name="password" required="" /></label>
				<input type="submit" value="Login" />
			</form>
		';
	}
	
	$page->content = template::parse('section', array(
		'title' => $page->title, 
		'content' => preg_replace('/(\n)\t/', '$1', $content)
	));
	
	exit($page->build());
?>