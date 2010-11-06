<?php
	require($_SERVER['DOCUMENT_ROOT'].'/init.php');
	header('Content-Type: application/atom+xml');
	
	$db = new db();
	$data = array('HTTP_HOST' => $_SERVER['HTTP_HOST']);
	
	foreach($db->fetch('*', 'articles', 'ORDER BY timestamp ASC', false, true) as $article) {
		array_push($data, 
			$article['title'], 
			page::uri($article['id']), 
			'tag:'.$_SERVER['HTTP_HOST'].','
				.date('Y', $article['timestamp']).':'.$article['id'],
			date(DATE_ATOM, $article['timestamp']), 
			preg_replace('/<[^>]+>/', '', substr($article['content'], 0, 250)).'...',
			preg_replace('/<[^>]+>/', '', $article['content'])
		);
		$data['latest_update'] = $article['timestamp'];
	}
	$data['latest_update'] = date(DATE_ATOM, $data['latest_update']);
	
	exit(template::parse('feed.xml', $data));
?>