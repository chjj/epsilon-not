<?php
require('./init.php');

$page = new page('application/xml');

$urls = array();
$articles = $page->db->fetch('id, timestamp, title', 'articles');

foreach ($articles as $article) {
  $urls[] = array(
    'loc' => APP_HOST.page::uri($article['id']),
    'lastmod' => date('c', $article['timestamp']),
    'priority' => '0.5'
  );
}

//set the home page priority to zero
$urls[] = array('loc' => APP_HOST.page::uri(), 'priority' => 0);

exit($page->output(template::parse('sitemap.xml', $urls)));
?>
