<?php
require('./init.php');

$page = new page();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($page->path[0])) {
  $data = array_map('trim', $_POST);
  if (empty($data)) $page->error(400);

  if ($page->path[0] === 'post') {
    if (!APP_LOGIN) $page->error(403);

    if (strlen($data['title']) < 5 || strlen($data['content']) < 20) {
      $page->error('Form Error', 'You must enter a title and content.');
    }

    $page->db->insert('articles', array(
      'title' => $data['title'],
      'id' => ($slug = preg_replace('/[^\w\-]/', '', str_replace("\x20", '-',
        strtolower($data['slug'] ? $data['slug'] : $data['title'])
      ))),
      'timestamp' => strtotime($data['timestamp']),
      'content' => $data['content'],
      'allow_comments' => isset($data['allow_comments']) ? 1 : null
    ));
  } elseif (isset($page->path[1])) {
    if (!APP_LOGIN) $page->error(403);

    $table = strstr($page->path[0], 'comment-') ? 'comments' : 'articles';

    if (!$page->db->grab('id', $table,
      'WHERE id=?', ($data['id'] = str_replace('comment-', '', $page->path[0]))
    )) $page->error(404);

    if ($table === 'comments') {
      $slug = $page->db->grab('parent', 'comments', 'WHERE id=?', $data['id']);
    }

    if ($page->path[1] === 'edit') {
      if ($table === 'articles') {
        $slug = $page->path[0];
        $data['allow_comments'] = isset($data['allow_comments']) ? 1 : null;
      }
      $data['timestamp'] = strtotime($data['timestamp']);
      $page->db->update($table, 'id=:id', $data);
    } elseif ($page->path[1] === 'delete') {
      if ($table === 'articles') $slug = null;
      $page->db->delete($table, 'id=?', $data['id']);
    }
  } else {
    if (!$page->db->grab('allow_comments',
                         'articles', 'WHERE id=?', $page->path[0])) {
      $page->error(
        'Post Error',
        'This article either does not exist or allow comments.'
      );
    }
    if (!APP_LOGIN) {
      $last_post = $page->db->grab('timestamp', 'comments',
        'WHERE poster_ip=? ORDER BY timestamp DESC LIMIT 10, 1',
        $_SERVER['REMOTE_ADDR']
      );
      if ($last_post && $last_post > (time() - 86400)) {
        $page->error('Post Error', 'You have already posted 10 times today.');
      }
      if (
        (!$data['poster_name']
          || strlen($data['poster_name']) < 2
          || strlen($data['poster_name']) > 25
          || preg_match('/[^\w\x20\-]/', $data['poster_name'])
        )
        || (!$data['poster_email']
          || strlen($data['poster_email']) < 6
          || strlen($data['poster_email']) > 250
          || !preg_match('/[\w.\-]*@[\w.\-]*\.[a-z]{2,}/i',
                          $data['poster_email'])
        )
        || ($data['poster_site'] && (
             strlen($data['poster_site']) < 11
          || strlen($data['poster_site']) > 250
          || preg_match('/[^\w\:;\/?&~.,\-_@#]/i', $data['poster_site'])
        ))
        || (!$data['content']
          || strlen($data['content']) < 10
          || strlen($data['content']) > 2000
        )
      ) $page->error(
        'Form Error',
        'All form fields must be entered and completed properly.'
      );
    }
    $page->db->insert('comments', array(
      'parent' => $slug = $page->path[0],
      'poster_ip' => APP_LOGIN ? 'admin' : $_SERVER['REMOTE_ADDR'],
      'poster_name' => $data['poster_name'],
      'poster_email' => $data['poster_email'],
      'poster_site' => $data['poster_site'],
      'timestamp' => time(),
      'content' => $data['content']
    ));
  }
  page::redirect(page::uri($slug));
}

if (isset($page->path[0])) {
  if ($page->path[0] === 'post') {
    if (!APP_LOGIN) $page->error(403);
    $page->content = template::parse('section', array(
      'title' => $page->title = 'Post Article',
      'content' => template::parse('form', array(
        'name' => 'Post Article',
        'title' => true,
        'slug' => true,
        'timestamp' => date('F jS, Y g:ia', time()),
        'action' => $_SERVER['REQUEST_URI']
      ))
    ));
  } else {
    $data = $page->db->grab('*',
      (strstr($page->path[0], 'comment-') ? 'comments' : 'articles'),
      'WHERE id=?', str_replace('comment-', '', $page->path[0])
    );
    if (!$data) $page->error(404);
  }
} else {
  $data = $page->db->grab('*', 'articles',
                          'ORDER BY timestamp DESC LIMIT 0, 1');
  if (!$data) {
    $page->error('Welcome', 'No articles currently exist here.');
  }
}

if (isset($page->path[1])) {
  if ($page->path[1] === 'edit') {
    if (!APP_LOGIN) $page->error(403);

    $page->title = $data['name'] = 'Edit Post';
    if (isset($data['title'])) {
      $data['title'] = htmlspecialchars($data['title']);
    }

    $page->content = template::parse('section', array(
      'title' => $page->title,
      'content' => template::parse('form', array_merge($data, array(
        'content' => preg_replace(
          '/\r?\n/', '&#x0A;',
          htmlspecialchars($data['content'])
        ),
        'timestamp' => date('F j, Y g:ia', $data['timestamp']),
        'action' => $_SERVER['REQUEST_URI']
      )))
    ));
  } elseif ($page->path[1] === 'delete') {
    if (!APP_LOGIN) $page->error(403);
    $page->title = 'Delete Post';
    $page->content = template::parse('section', array(
      'title' => $page->title,
      'content' => template::parse('confirm', array(
        'id' => (!isset($data['title']) ? 'comment-' : '').$data['id']
      ))
    ));
  }
} elseif (isset($data)) {
  if (isset($data['parent'])) {
    page::redirect(page::uri($data['parent']));
  }

  if (isset($page->path[0])) {
    $page->name = 'article';
    $page->title = $data['title'];
    header('X-Pingback: http://'.APP_HOST.page::uri('pingback'));
    if ($data['allow_comments']) {
      $comments = array();
      if ($rows = $page->db->fetch('*', 'comments',
        'WHERE parent=? ORDER BY timestamp ASC', $data['id']
      )) foreach ($rows as $i => $comment) {
        $comments[] = template::parse('comment', array(
          'id' => $comment['id'], //$i + 1,
          'poster_rel' => ($comment['poster_ip'] === 'admin')
            ? 'related' : 'external',
          'poster_name' => htmlspecialchars(
            $comment['poster_name'],
            ENT_QUOTES, 'UTF-8', false
          ),
          'poster_site' => $comment['poster_site']
            ? htmlspecialchars(
              $comment['poster_site'],
              ENT_QUOTES, 'UTF-8', false
            ) : false,
          'date' => date('F j, Y @ g:i a', $comment['timestamp']),
          'datetime' => date('c', $comment['timestamp']),
          'avatar' => !empty($comment['poster_email'])
            ? md5(strtolower($comment['poster_email'])) : false,
          'content' => page::parse_markup($comment['content'], 3, true),
          'edit' => APP_LOGIN
            ? page::uri('comment-'.$comment['id'], 'edit') : false
        ));
      }
    }
  } else {
    $page->name = 'home';
    $page->canonical = page::uri($data['id']);

    //this may not work because google is stupid
    //header('Link: <'.page::uri($data['id']).'>; rel=canonical');
  }

  $prev = $page->db->grab('id, title', 'articles',
    'WHERE timestamp < ? ORDER BY timestamp DESC LIMIT 0, 1',
    $data['timestamp']
  );
  $next = $page->db->grab('id, title', 'articles',
    'WHERE timestamp > ? ORDER BY timestamp ASC LIMIT 0, 1',
    $data['timestamp']
  );

  $page->content = template::parse('article', array(
    'title' => $data['title'],
    'permalink' => page::uri($data['id']),
    'datetime' => date('c', $data['timestamp']),
    'date' => explode('.', date('F j.S., Y', $data['timestamp'])),
    'content' => page::parse_markup(
      $data['content'], 1, false,
      !isset($page->path[0])
    ),
    'comments' => (isset($comments) ? implode($comments) : false),
    'form' => (isset($comments) ? template::parse('form', array(
      'name' => 'Post Comment',
      'action' => $_SERVER['REQUEST_URI'],
      'poster_name' => true,
      'poster_email' => true,
      'poster_site' => true
    )) : false),
    'prev' => array(
      'href' => $prev ? page::uri($prev['id']) : false,
      'title' => $prev ? htmlspecialchars($prev['title']) : false
    ),
    'next' => array(
      'href' => $next ? page::uri($next['id']) : false,
      'title' => $next ? htmlspecialchars($next['title']) : false
    ),
    'edit' => APP_LOGIN ? page::uri($data['id'], 'edit') : false
  ));
}
exit($page->build());
?>
