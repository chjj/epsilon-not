<?php
require('./init.php');

$page = new page();

if (isset($_POST['post'])) {
	if ($_POST['post'] == 'Post Article') {
		if (!APP_LOGIN) $page->error(403);
		if (strlen($_POST['title']) < 5 || strlen($_POST['content']) < 20) 
			$page->error('Form Error', 'You must enter a title and content.');
		$page->db->insert('articles', array(
			'title' => $_POST['title'],
			'id' => ($slug = preg_replace('/[^\w]/', '', str_replace(' ', '_', 
				strtolower($_POST['id'] ? $_POST['id'] : $_POST['title'])
			))),
			'timestamp' => strtotime($_POST['timestamp']),
			'content' => $_POST['content'],
			'comments' => isset($_POST['comments']) ? 1 : null
		));
	} elseif ($_POST['post'] == 'Post Comment') {
		if (!APP_LOGIN) {
			$last_post = $page->db->fetch('timestamp', 'comments', 
				'WHERE poster_ip=? ORDER BY timestamp DESC LIMIT 10, 1', 
				$_SERVER['REMOTE_ADDR']
			);
			if ($last_post && $last_post > (time() - 86400))
				$page->error('Post Error', 'You have already posted 10 times today.');
			if (
			!$_POST['poster_name'] || strlen($_POST['poster_name']) > 25 || preg_match('/[^\w\x20]/', $_POST['poster_name']) 
			|| (!$_POST['poster_email'] || !preg_match('/[\w.\-]*@[\w.\-]*\.[a-z]{2,}/i', $_POST['poster_email'])) 
			|| ($_POST['poster_site'] && preg_match('/[^\w\:;\/?&~.,\-_@#]/i', $_POST['poster_site']))
			|| (!$_POST['content'] || strlen(trim($_POST['content'])) < 10 || strlen($_POST['content']) > 2000)
			) $page->error('Form Error', 'All form fields must be entered and completed properly.');
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
		if (isset($_POST['title'])) $_POST['comments'] = isset($_POST['comments']) ? 1 : null;
			else $slug = $page->db->fetch('parent', 'comments', 'WHERE id=?', $_POST['id']);
		$_POST['timestamp'] = strtotime($_POST['timestamp']);
		$page->db->update((isset($_POST['title']) ? 'articles' : 'comments'), 'id=:id', $_POST);
	}
	page::redirect(page::uri((isset($slug) ? $slug : $_POST['id']))); 
}

if (isset($page->path[0])) {
	if ($page->path[0] == 'post') {
		if (!APP_LOGIN) $page->error(403);
		$page->content = template::parse('section', array( 
			'title' => $page->title = 'Post Article', 
			'content' => template::parse('form', array(
				'name' => 'Post Article', 'title' => true,
				'slug' => true, 'timestamp' => date('F jS, Y g:ia', time())
			))
		));
	} elseif (!$data = $page->db->fetch('*', 
		(strstr($page->path[0], '.') ? 'comments' : 'articles'), 
		'WHERE id=?', str_replace('comment.', '', $page->path[0])
	)) {
		$page->error(404);
	}
} else {
	if (!$data = $page->db->fetch('*', 'articles', 'ORDER BY timestamp DESC LIMIT 0, 1'))
		$page->error('Welcome', 'No articles currently exist here.');
}

if (isset($page->path[1])) {
	if ($page->path[1] == 'edit') {
		if (!APP_LOGIN) $page->error(403);
		$page->title = $data['name'] = 'Edit Post';
		if (isset($data['title'])) $data['title'] = htmlspecialchars($data['title']);
		$data['content'] = preg_replace('/\r?\n/', '&#x0A;', htmlspecialchars($data['content']));
		$data['timestamp'] = date('F jS, Y g:ia', $data['timestamp']);
		$page->content = template::parse('section', array(
			'title' => $page->title, 
			'content' => template::parse('form', $data)
		));
	} elseif ($page->path[1] == 'delete') {
		if (!APP_LOGIN) $page->error(403);
		$page->title = 'Delete Post';
		if (!isset($_GET['confirm'])) {
			$page->content = template::parse('section', array(
				'title' => $page->title, 
				'content' => template::parse('confirm', array('id' => $data['id']))
			));
		} else {
			$page->db->delete(!isset($data['parent']) ? 'articles' : 'comments', 'id=?', $data['id']);
			page::redirect(!isset($data['parent']) ? page::uri() : page::uri($data['parent']));
		}
	} 
} elseif (isset($data)) {
	if (isset($data['parent'])) 
		page::redirect(page::uri($data['parent']));
	if ($page->path[0]) {
		header('X-Pingback: http://'.$_SERVER['HTTP_HOST'].'/pingback');
		$page->title = $data['title'];
		if ($data['comments']) {
			$comments = array();
			if ($rows = $page->db->fetch('*', 'comments', 
			'WHERE parent=? ORDER BY timestamp ASC', $data['id'], true)) {
				foreach ($rows as $i => $comment) $comments[] = template::parse('comment', array(
					'id' => $i + 1,
					'poster_rel' => ($comment['poster_ip'] == 'admin') ? 'related' : 'external',
					'poster_name' => htmlspecialchars($comment['poster_name'], ENT_QUOTES, 'UTF-8', false),
					'poster_site' => $comment['poster_site'] 
						? htmlspecialchars($comment['poster_site'], ENT_QUOTES, 'UTF-8', false) : false,
					'date' => date('F j<\s\u\p>S</\s\u\p>, Y @ g:i a', $comment['timestamp']),
					'datetime' => date('c', $comment['timestamp']),
					'avatar' => !empty($comment['poster_email']) 
						? md5(strtolower(trim($comment['poster_email']))) : false,
					'content' => page::parse_markup($comment['content'], 3, true),
					'edit' => APP_LOGIN ? page::uri('comment.'.$comment['id'], 'edit') : false
				));
			}
		}
	} else {
		header('Link: <'.page::uri($data['id']).'>; rel=canonical');
	}
	$prev = $page->db->fetch('id, title', 'articles', 'WHERE timestamp < ? ORDER BY timestamp DESC LIMIT 0, 1', 
		$data['timestamp']);
	$next = $page->db->fetch('id, title', 'articles', 'WHERE timestamp > ? ORDER BY timestamp ASC LIMIT 0, 1', 
		$data['timestamp']);
	$page->content = template::parse('article', array(
		'title' => $data['title'],
		'permalink' => page::uri($data['id']),
		'datetime' => date('c', $data['timestamp']),
		'date' => date('F j<\s\u\p>S</\s\u\p>, Y', $data['timestamp']),
		'content' => page::parse_markup($data['content'], 1, false, !isset($page->path[0])),
		'comments' => (isset($comments) ? implode($comments) : false), 
		'form' => (isset($comments) ? template::parse('form', array(
			'name' => 'Post Comment',
			'id' => $data['id'], 
			'poster_name' => true, 
			'poster_email' => true,
			'poster_site' => true
		)) : false), 
		'prev_href' => $prev ? page::uri($prev['id']) : false,
		'prev_title' => $prev ? $prev['title'] : false,
		'next_href' => $next ? page::uri($next['id']) : false,
		'next_title' => $next ? $next['title'] : false,
		'edit' => APP_LOGIN ? page::uri($data['id'], 'edit') : false
	));
}
exit($page->build());
?>