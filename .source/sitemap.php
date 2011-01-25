<?php
require('./init.php');
header('Content-Type: application/xml; charset=utf-8');

$db = new db();

$data = array($_SERVER['HTTP_HOST'].page::uri(), null, 0);
foreach ($db->fetch('id, timestamp, title', 'articles') as $article) 
	array_push($data, $_SERVER['HTTP_HOST'].page::uri($article['id']), date('c', $article['timestamp']), null);

exit(template::parse('sitemap.xml', $data));
?>