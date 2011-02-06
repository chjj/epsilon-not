<?php
require('./init.php');
header('Content-Type: application/xml; charset=utf-8');

$db = new db();

$data = array(APP_HOST.page::uri(), null, 0);
foreach ($db->fetch('id, timestamp, title', 'articles') as $article) 
	array_push($data, APP_HOST.page::uri($article['id']), date('c', $article['timestamp']), '0.5');

exit(template::parse('sitemap.xml', $data));
?>