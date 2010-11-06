<?php
	require($_SERVER['DOCUMENT_ROOT'].'/init.php');
	
	$page = new page();
	$page->title = 'Articles';
	
	if (isset($_POST['post'])) {
		if ($_POST['post'] == 'Post Article') {
			if (!APP_LOGIN) $page->error(403);
			if (strlen($_POST['title']) < 5 || strlen($_POST['content']) < 20) 
				$page->error('Form Error', 'You must enter a title and content.');
			$page->db->insert('articles', array(
				'title' => $_POST['title'],
				'id' => ($slug = $_POST['id'] 
					? $_POST['id'] : preg_replace('/[^\w]/', '', str_replace(' ', '_', strtolower($_POST['title'])))),
				'timestamp' => time(),
				'content' => $_POST['content'],
				'comments' => empty($_POST['comments']) ? null : 1
			));
		} elseif ($_POST['post'] == 'Post Comment') {
			if (!APP_LOGIN) {
				$last_post = $page->db->fetch('timestamp', 'comments', 'WHERE poster_ip=? ORDER BY timestamp DESC LIMIT 10, 1', $_SERVER['REMOTE_ADDR']);
				if ($last_post && $last_post > (time() - 86400))
					$page->error('Post Error', 'You have already posted 10 times today.');
				if (!$_POST['poster_name'] || strlen($_POST['poster_name']) < 2 || preg_match('/[^\w\s]/', $_POST['poster_name']) 
				|| ($_POST['poster_email'] && !preg_match('/[\w.\-]*@[\w.\-]*\.[a-z]{2,}/i', $_POST['poster_email'])) 
				|| ($_POST['poster_site'] && preg_match('/[^\w\:;\/?&~.,\-_@#]/i', $_POST['poster_site']))
				|| (!$_POST['content'] || strlen(trim($_POST['content'])) < 10 || strlen($_POST['content']) > 2000)) 
					$page->error('Form Error', 'All form fields must be entered and completed properly.');
			}
			$page->db->insert('comments', array(
				'parent' => $_POST['id'],
				'poster_ip' => APP_LOGIN ? 'admin' : $_SERVER['REMOTE_ADDR'], 
				'poster_name' => trim($_POST['poster_name']), 
				'poster_email' => trim($_POST['poster_email']),
				'poster_site' => trim($_POST['poster_site']), 
				'timestamp' => time(),
				'content' => $_POST['content'],
			));
		} elseif ($_POST['post'] == 'Edit Post') {
			if (!APP_LOGIN) $page->error(403);
			unset($_POST['post']);
			if (isset($_POST['title'])) $_POST['comments'] = empty($_POST['comments']) ? null : 1;
				else $slug = $page->db->fetch('parent', 'comments', 'WHERE id=?', $_POST['id']);
			$page->db->update((isset($_POST['title']) ? 'articles' : 'comments'), 'id=:id', $_POST);
		} 
		page::redirect(page::uri((isset($slug) ? $slug : $_POST['id']))); 
	}
	
	if (isset($_GET[0])) {
		if ($_GET[0] == 'post') {
			if (!APP_LOGIN) $page->error(403);
			$page->title = 'Post Article';
			$page->content = template::parse('section', array( 
				'title' => 'Post Article', 'content' => template::parse('form', array('name' => 'Post Article', 'title' => true, 'slug' => true)),
			));
		} elseif (!$post_data = $page->db->fetch('*', (!is_numeric($_GET[0]) ? 'articles' : 'comments'), 'WHERE id=?', $_GET[0])) {
			$page->error(404);
		}
	} else {
		$post_data = $page->db->fetch('*', 'articles', 'ORDER BY timestamp DESC LIMIT 0, 1');
	}
	
	if (isset($_GET[1])) {
		if ($_GET[1] == 'edit') {
			if (!APP_LOGIN) $page->error(403);
			$page->title = $post_data['name'] = 'Edit Post';
			$post_data['content'] = preg_replace('/\r?\n/', '&#x0A;', htmlspecialchars($post_data['content'], ENT_QUOTES));
			$page->content = template::parse('section', array('title' => 'Edit Post', 'content' => template::parse('form', $post_data)));
		} elseif ($_GET[1] == 'delete') {
			if (!APP_LOGIN) $page->error(403);
			if (!isset($_GET['yes'])) {
				$page->content = template::parse('section', array('title' => 'Delete Post', 
					'content' => '<p>Are you sure you want to delete: &quot;'.$post_data['id'].'&quot;?</p>'.
						'<p><a href="?yes">Delete Post</a></p>'));
			} else {
				$page->db->delete(!isset($post_data['parent']) ? 'articles' : 'comments', 'id=?', $post_data['id']);
				page::redirect(!isset($post_data['parent']) ? page::uri() : page::uri($post_data['parent']));
			}
		} 
	} elseif (isset($post_data)) {
		if (isset($post_data['parent'])) 
			page::redirect(page::uri($post_data['parent']));
		if (isset($_GET[0]) && $post_data['comments']) {
			$comments = array();
			if ($rows = $page->db->fetch('*', 'comments', 'WHERE parent=? ORDER BY timestamp ASC', $post_data['id'], true)) {
				foreach($rows as $comment) {
					$comments[] = template::parse('comment', array(
						'id' => $comment['id'],
						'poster_rel' => ($comment['poster_ip'] == 'admin') ? 'author' : 'external',
						'poster_name' => htmlspecialchars($comment['poster_name']),
						'poster_site' => $comment['poster_site'] ? htmlspecialchars($comment['poster_site']) : false,
						'post_date' => date('F j<\s\u\p>S</\s\u\p>, Y @ g:i a', $comment['timestamp']),
						'datetime' => date('c', $comment['timestamp']),
						'avatar' =>  'http://www.gravatar.com/avatar/'.
							md5(strtolower(trim(!empty($comment['poster_email']) ? $comment['poster_email'] : 'example@abc.abc'))).'?s=35&amp;d=mm',
						'content' => page::parse_markup($comment['content'], 4, true),
						'edit_href' => APP_LOGIN ? page::uri($comment['id'], 'edit') : false,
						'delete_href' => APP_LOGIN ? page::uri($comment['id'], 'delete') : false
					));
				}
			}
		}
		$page->content = template::parse('article', array(
			'title' => $page->title = $post_data['title'],
			'permalink' => $permalink = page::uri($post_data['id']),
			'datetime' => date('c', $post_data['timestamp']),
			'date' => date('F j<\s\u\p>S</\s\u\p>, Y', $post_data['timestamp']),
			'content' => page::parse_markup($post_data['content'], 2, false, !isset($_GET[0])),
			'comments' => (isset($comments) ? implode($comments) : false), 
			'post_form' => (isset($comments) ? template::parse('form', array(
				'name' => 'Post Comment',
				'id' => $post_data['id'], 
				'poster_name' => true, 
				'poster_email' => true,
				'poster_site' => true
			)) : false), 
			'footer' => PHP_EOL."\t\t\t".
				(($previous = $page->db->fetch('id, title', 'articles', 'WHERE timestamp < ? ORDER BY timestamp DESC LIMIT 0, 1', $post_data['timestamp']))
						? '<a href="'.page::uri($previous['id']).'" rel="prev" title="Previous Article">'.$previous['title'].'</a>'
						: '<a href="'.$permalink.'" rel="bookmark first" title="Current Article">Permalink</a>').PHP_EOL."\t\t\t".
				(($next = $page->db->fetch('id, title', 'articles', 'WHERE timestamp > ? ORDER BY timestamp ASC LIMIT 0, 1', $post_data['timestamp']))
						? '<a href="'.page::uri($next['id']).'" rel="next" title="Next Article">'.$next['title'].'</a>'
						: '<a href="'.$permalink.'" rel="bookmark last" title="Current Article">Permalink</a>').
				(APP_LOGIN
					? PHP_EOL."\t\t\t".'<a href="'.page::uri($post_data['id'], 'edit').'">Edit</a> <a href="'.page::uri($post_data['id'], 'delete').'">Delete</a>'. 
						PHP_EOL."\t\t\t".'<a href="'.page::uri('post').'">Post</a> <a href="'.page::uri('admin').'">Admin</a>' 
					: '').
				PHP_EOL."\t\t", 
		));
	}
	exit($page->build());
?>