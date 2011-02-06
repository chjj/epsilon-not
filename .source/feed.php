<?php
require('./init.php');
header('Content-Type: application/atom+xml; charset=utf-8');

$db = new db();
$data = array('host' => APP_HOST);

foreach ($db->fetch('*', 'articles', 'ORDER BY timestamp DESC LIMIT 0, 10', false, true) as $article) {
	$updated = $article['timestamp'];
	if (preg_match_all('/(?<=datetime=")[^"]+/i', $article['content'], $m)) 
		foreach ($m[0] as $t) if (($t=@strtotime($t)) > $updated) $updated = $t;
	array_push($data, 
		htmlspecialchars($article['title']), 
		page::uri($article['id']), 
		'tag:'.APP_HOST.','
			.date('Y', $article['timestamp']).':'.$article['id'],
		date(DATE_ATOM, $article['timestamp']), 
		date(DATE_ATOM, $updated),
		preg_replace(
			'/(?<=h)\d(?=>)/ie', '($0-1)', 
			page::parse_markup($article['content'], 3, false)
		)
	);
	if (!isset($data['latest_update']) || $updated > $data['latest_update']) 
		$data['latest_update'] = $updated;
}
$data['latest_update'] = date(DATE_ATOM, $data['latest_update']);

exit(template::parse('feed.xml', $data));
?>