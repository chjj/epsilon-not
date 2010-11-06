<?php
	require($_SERVER['DOCUMENT_ROOT'].'/init.php');
	header('Content-Type: application/xml');
	
	$db = new db();
	
	$data = array();
	foreach($db->fetch('id, timestamp, title', 'articles') as $article) 
		array_push($data, $_SERVER['HTTP_HOST'].page::uri($article['id']), date('c', $article['timestamp']));
	exit(template::parse('sitemap.xml', $data));
?>