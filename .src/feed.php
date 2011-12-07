<?php
require('./init.php');

$page = new page('application/atom+xml');

$entries = array();
$articles = $page->db->fetch(
  '*', 'articles',
  'ORDER BY timestamp DESC LIMIT 0, 10'
);

foreach ($articles as $article) {
  $updated = $article['timestamp'];
  if (preg_match_all('/(?<=datetime=")[^"]+/i', $article['content'], $m)) {
    foreach ($m[0] as $t) if (($t=@strtotime($t)) > $updated) $updated = $t;
  }
  $entries[] = array(
    'title' => htmlspecialchars($article['title']),
    'href' => page::uri($article['id']),
    'id' => 'tag:'.APP_HOST.','
      .date('Y', $article['timestamp']).':'.$article['id'],
    'published' => date(DATE_ATOM, $article['timestamp']),
    'updated' => date(DATE_ATOM, $updated),
    'content' => preg_replace(
      '/(?<=h)\d(?=>)/ie', '($0-1)',
      page::parse_markup($article['content'], 3, false)
    )
  );
  if (!isset($last_update) || $updated > $last_update) {
    $last_update = $updated;
  }
}

exit($page->output(
  template::parse('feed.xml', array(
    'host' => APP_HOST,
    //'title' => 'articles',
    'self' => page::uri('feed'),
    'alternate' => page::uri(),
    //'index' - tag:&:host;,2010:&:id;
    'id' => 'tag:'.APP_HOST.',2010:index',
    'updated' => date(DATE_ATOM, $last_update),
    'entries' => $entries
  ))
));
?>