<?php
require($_SERVER['DOCUMENT_ROOT'].'/init.php');

$page = new page();
$page->title = 'Browse';

$list = array();

if (isset($_GET['search'])) {
	if (empty($_GET['search'])) $page->error(404, 'Please enter a search term.');
	if ($_GET['search'] == '/') page::redirect(page::uri('admin'));
	$subtitle = 'Search for '.($_GET['search'] = str_replace(
		array('_', '%'), array('', ''), trim($_GET['search'])
	));
	$articles = $page->db->fetch('*', 
		'articles', 'WHERE (title LIKE :s OR content LIKE :s)', 
		array('s' => '%'."\x20".$_GET['search']."\x20".'%'), 
	true);
} else {
	if (isset($page->path[1]) && is_numeric($page->path[1])) {
		$year = $page->path[1];
		if (isset($page->path[2]) && is_numeric($page->path[2])) 
			$month = $page->path[2];
		$subtitle = (isset($month) ? date('F', mktime(0, 0, 0, $month)).' ' : '').$year;
		$articles = $page->db->fetch('*', 
			'articles', 'WHERE timestamp >= ? AND timestamp < ? ORDER BY timestamp DESC', 
			array(
				mktime(0, 0, 0, (isset($month) ? $month : 1), 1, $year), 
				mktime(0, 0, 0, (isset($month) ? $month+1 : 1), 1, (isset($month) ? $year : $year+1))
			), 
		true);
	} else {
		$subtitle = 'All';
		$year = date('Y'); $month = date('n');
		$earliest = $page->db->fetch('timestamp', 'articles', 'ORDER BY timestamp ASC LIMIT 0, 1');
		do {
			$month_time = mktime(0, 0, 0, $month, 1, $year);
			$num_articles = $page->db->fetch('COUNT(*)', 
				'articles', 'WHERE timestamp >= ? AND timestamp < ?', 
				array($month_time, mktime(0, 0, 0, $month+1, 1, $year))
			);
			if ($num_articles > 0) {
				array_push($list, 
					page::uri('browse', $year, date('m', $month_time)), 
					date('F', $month_time).' '.$year.' ('.$num_articles.' article'
						.($num_articles > 1 ? 's' : '').')', 
					false, false
				);
			}
			if (($month -= 1) < 1) { $month = 12; $year -= 1; } 
		} while ($month_time >= $earliest);
	}
}
if (empty($list)) {
	if (empty($articles))
		$page->error(404, 'No articles found.');
	foreach ($articles as $article) 
		array_push($list, 
			page::uri($article['id']), $article['title'], date('c', $article['timestamp']), 
			date('F j<\s\u\p>S</\s\u\p>, Y @ g:i a', $article['timestamp'])
		);
}
$page->content = template::parse('section', array(
	'title' => 'Archives: '.htmlspecialchars($subtitle), 
	'content' => template::parse('browse', $list)
));

exit($page->build());
?>