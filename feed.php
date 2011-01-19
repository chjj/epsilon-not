<?php
require($_SERVER['DOCUMENT_ROOT'].'/init.php');
header('Content-Type: application/atom+xml; charset=utf-8');

$db = new db();
$data = array('host' => $_SERVER['HTTP_HOST']);

foreach ($db->fetch('*', 'articles', 'ORDER BY timestamp DESC LIMIT 0, 10', false, true) as $article) {
	array_push($data, 
		htmlspecialchars($article['title']), 
		page::uri($article['id']), 
		'tag:'.$_SERVER['HTTP_HOST'].','
			.date('Y', $article['timestamp']).':'.$article['id'],
		$date = date(DATE_ATOM, $article['timestamp']), $date,
		page::parse_markup($article['content'], 3, false)
	);
	$data['latest_update'] = $article['timestamp'];
}
$data['latest_update'] = date(DATE_ATOM, $data['latest_update']);

exit(template::parse('feed.xml', $data));
?>