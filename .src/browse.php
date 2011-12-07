<?php
require('./init.php');

$page = new page();
$page->title = 'Browse';
$page->name = 'article';

$list = array();

if (isset($_GET['search'])) {
  if (empty($_GET['search'])) {
    $page->error(404, 'Please enter a search term.');
  }

  // convenient way to access the admin page
  if ($_GET['search'] == '/') page::redirect(page::uri('admin'));

  // need to remove "_" and "%" due to
  // their special meaning in LIKE queries
  $page->title = 'Search for '.($_GET['search'] = str_replace(
    array('_', '%'), '', trim($_GET['search'])
  ));
  $items = $page->db->fetch('id, title, timestamp',
    'articles', 'WHERE (title LIKE :s OR content LIKE :s)',
    array('s' => '%'."\x20".$_GET['search']."\x20".'%')
  );
} else {
  if (isset($page->path[1])) {
    if (is_numeric($page->path[1])) {
      $year = $page->path[1];
      if (isset($page->path[2]) && is_numeric($page->path[2])) {
        $month = $page->path[2];
      }
      $page->title = (isset($month)
        ? date('F', mktime(0, 0, 0, $month)).' '
        : '').$year;
      $items = $page->db->fetch('id, title, timestamp', 'articles',
        'WHERE timestamp >= ? AND timestamp < ? ORDER BY timestamp DESC',
        array(
          mktime(
            0, 0, 0,
            (isset($month) ? $month : 1),
            1, $year
          ),
          mktime(
            0, 0, 0,
            (isset($month) ? $month+1 : 1),
            1, (isset($month) ? $year : $year+1)
          )
        )
      );
    } elseif ($page->path[1] == 'latest') {
      $page->name = 'latest';
      $page->title = 'Latest';

      //grab the last 5 articles, comments, and remarks
      $items = $page->db->fetch(
        'id, title, timestamp', 'articles',
        $w = 'ORDER BY timestamp DESC LIMIT 0, 5'
      );

      foreach ($page->db->fetch('*', 'comments', $w) as $c) {
        $c['id'] = $page->db->grab(
          'parent',
          'comments', 'WHERE id=?',
          $c['id']
        ).'#'.$c['id'];
        $c['title'] = 'Comment by '.$c['poster_name'];
        $items[] = $c;
      }

      //need to manually sort them according to timestamp
      usort($items, create_function('$a, $b',
        'return $a["timestamp"] > $b["timestamp"] ? -1 : 1;'
      ));
    }
  } else {
    $year = date('Y'); $month = date('n');
    $earliest = $page->db->grab(
      'timestamp',
      'articles',
      'ORDER BY timestamp ASC LIMIT 0, 1'
    );
    if ($earliest) do {
      $month_time = mktime(0, 0, 0, $month, 1, $year);
      $num_articles = $page->db->grab('COUNT(*)',
        'articles', 'WHERE timestamp >= ? AND timestamp < ?',
        array($month_time, mktime(0, 0, 0, $month+1, 1, $year))
      );
      if ($num_articles > 0) $list[] = array(
        'href' => page::uri('browse', $year, date('m', $month_time)),
        'text' => date('F', $month_time)
          .' '.$year.' ('.$num_articles.' article'
          .($num_articles > 1 ? 's' : '').')'
      );
      if (--$month < 1) { $month = 12; $year--; }
    } while ($month_time >= $earliest);
  }
}

if (empty($list)) {
  if (empty($items)) {
    $page->error(404, 'No articles found.');
  }
  foreach ($items as $item) $list[] = array(
    'href' => page::uri($item['id']),
    'text' => $item['title'],
    'datetime' => date('c', $item['timestamp']),
    'time' => date('F j, Y @ g:i a', $item['timestamp'])
  );
}

$page->content = template::parse('section', array(
  'title' => 'Archives: '.($page->title = htmlspecialchars($page->title)),
  'content' => template::parse('browse', $list)
));

exit($page->build());
?>