<?php
// i was toying with the idea of using a completely procedural backend
// a long time ago. this was my attempt at one. it abuses static variables
// like crazy because php's scoping is stupid. still needs a lot of work.
// very sloppy.

error_reporting($_SERVER['SERVER_ADDR'] === '127.0.0.1' ? E_ALL|E_STRICT : 0);

date_default_timezone_set('US/Central');

define('APP_TYPE', 'text/html');
define('APP_HOST',
  $_SERVER['SERVER_ADDR'] === '127.0.0.1'
    ? '127.0.0.1' : 'epsilon-not.net'
);
require('./_pass.php'); // define('APP_PASSWORD', __);
define('APP_LOGIN', isset($_COOKIE['user'])
  ? (hash('tiger192,4', base64_decode($_COOKIE['user'])) === APP_PASSWORD)
  : false
);
define('APP_DIR', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
define('APP_SRC', APP_DIR.'/.src');
define('APP_DATA', APP_SRC.'/data/content.sqlite');
define('APP_TEMPLATES', APP_SRC.'/templates');
define('APP_CACHE', APP_SRC.'/cache');

function __db() {
  static $db;
  if (!isset($db)) {
    $db = sqlite_open(APP_DATA);
    if (!$db) {
      trigger_error('DB not found.', E_ERROR);
      exit;
    }
    if (!db_fetch('name', 'sqlite_master', 'WHERE type=?', 'table')) {
      db_exec(file_get_contents(APP_SRC.'/data/tables.sql'));
    }
  }
  return $db;
}

function escape_query($query, $vars = null) {
  if (!$vars) return $query;

  foreach ($vars as $key => $val) {
    $val = sqlite_quote($val);
    if (is_numeric($key)) {
      $query = preg_replace('/\?/', $val, $query, 1);
    } else {
      $query = str_replace(':'.$key, $val, $query);
    }
  }

  return $query;
}

function db_query($query, $vars = false) {
  if (!is_array($vars)) {
    $vars = ($vars === false) ? array() : array($vars);
  }
  $query = escape_query($query, $vars);
  $result = sqlite_query(__db(), $query);
  $result = sqlite_fetch_array($result, SQLITE_ASSOC);
  return $result;
}

function db_exec($query, $vars = false) {
  if ($vars !== false) {
    db_query($query, $vars);
  } else {
    sqlite_exec(__db(), escape_query($query, $vars));
  }
}

function db_fetch($field, $table, $statements = null, $vars = false) {
  $rows = db_query(
    'SELECT '.$field.' FROM '
    .$table.($statements ? ' '.$statements : ''),
    $vars
  );
  return $rows ? $rows : array();
}

function db_grab($field, $table, $statements = null, $vars = false) {
  $rows = db_fetch($field, $table, $statements, $vars);
  if ($rows && count($rows) > 0) {
    if (!strstr($field, ',') && $field !== '*') {
      foreach ($rows as $i => $val) $rows[$i] = $val[$field];
    }
    return (count($rows) > 1) ? $rows : $rows[0];
  }
  return null;
}

function db_update($table, $rows = null, $vars) {
  $fields = array();
  foreach ($vars as $field => $val) {
    $fields[] = $field.'=:'.$field;
  }
  return db_exec('UPDATE '.$table
    .' SET '.implode(', ', $fields)
    .($rows ? ' WHERE '.$rows : ''),
  $vars);
}

function db_insert($table, $vars) {
  return db_exec('INSERT INTO '.$table.' ('
    .implode(', ', array_keys($vars)).') VALUES(:'
    .implode(', :', array_keys($vars)).')',
  $vars);
}

function db_delete($table, $rows = null, $vars = false) {
  return db_exec(
    'DELETE FROM '.$table
    .($rows ? ' WHERE '.$rows : ''),
  $vars);
}


function uri() {
  $args = func_get_args();
  if (isset($args[0]) && is_array($args[0])) $args = $args[0];
  return '/'.(!empty($args) ? implode('/', $args) : '');
}

function redirect($uri, $response = 303) {
  $uri = preg_replace('/\/{2,}/', '/', '/'.str_replace('&amp;', '&', $uri));
  header('Location: http://'.APP_HOST.$uri, true, $response);
  exit;
}

function parse_markup($text, $indent = 0, $filter = true) {
  // clean up the text and make sure newlines are consistent
  $text = str_replace(
    array("\x00", "\r\n", "\r"),
    array('', "\n", "\n"),
    trim($text)
  );

  // replace contents of CDATA declarations with entities
  $text = preg_replace_callback(
    '/<!\[CDATA\[(.+?)\]\]>|`([^`]+)`/s',
    create_function('$m',
      'return htmlspecialchars($m[1] ? $m[1] : $m[2], ENT_QUOTES);'
    ),
  $text);

  // sanitize the markup
  if ($filter) {
    // remove bad elements, attributes, and character references
    $text = preg_replace(array(
      '/<\s*(?!\/?(?:'
        .'a|br|b|i|strong|em|small|cite|ins|del|code'
        .'|samp|kbd|dfn|abbr|mark|time|var|sub|sup|q'
      .')(?:\s|>)).+?>/is',
      '/<.+(?<!title|lang|dir|type|href|cite)\s*=.+>/is',
      '/&(?!lt|gt|amp|apos|quot)[^;]*;/'
    ), '', $text);

    // turn newlines into new paragraphs
    $text = preg_replace(
      array('/<([^>\s]+)([^>]*)>(.*?)\n{2,}(.*?)<\/\1>/s', '/\n{2,}/'),
      array('<$1$2>$3 $4</$1>'                           , '</p><p>' ),
      $text
    );

    // make the markup lowercase
    $text = preg_replace_callback('/<[^>]+>/',
      create_function('$m', 'return strtolower($m[0]);'),
      '<p>'.$text.'</p>'
    );
  }

  // replace <pre> elements with placeholder text so it doesn't get indented
  $placeholders = array();
  if (preg_match_all('/<pre>.+?<\/pre>/is', $text, $m)) {
    for ($i = 0; $i < count($m[0]); $i++) {
      $placeholders[] = preg_replace('/\n+/', '&#x0A;', $m[0][$i]);
      $text = str_replace($m[0][$i],
        '<PLACEHOLDER ID="'
        .(count($placeholders)-1).'"/>',
        $text
      );
    }
  }

  // wrap and indent everything
  $text = ($indent = str_repeat("\x20\x20", $indent)).preg_replace(
    array(
      '/[\t\r\n]+/',
      '/(?:<\/[^>]+>|\/>)(?=\s*<\w)/',
      '/.{100,}?\s+(?![^<]+>)/'
    ),
    array('', '$0'."\n".$indent, '$0'."\n".$indent),
    $text
  );

  // replace the placeholders
  foreach ($placeholders as $i => $placeholder) {
    $text = str_replace('<PLACEHOLDER ID="'.$i.'"/>', $placeholder, $text);
  }

  // make sure the markup is well-formed
  if (!xml_parse(xml_parser_create('UTF-8'), '<x>'.$text.'</x>', true)) {
    $text = '<p>'.preg_replace('/<[^>]+>/', '', $text).'</p>'."\n"
      .'<p><small>The above text contained poorly formed markup.'
      .'All markup has been removed.</small></p>'
    ;
  }

  return $text;
}


function page_cache($relevant_file = APP_DATA) {
  static $cache;
  if (isset($cache)) {
    return $cache;
  }

  $updated = filemtime($relevant_file);

  // orchestrate the caching
  if ($_SERVER['REQUEST_METHOD'] === 'GET'
      && APP_HOST !== '127.0.0.1') {
    header('Last-Modified: '.$updated);
    if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] === $updated) {
      header(' ', true, 304);
      exit;
    }

    $file = APP_CACHE.'/'.md5($_SERVER['REQUEST_URI'].APP_LOGIN);
    if (count($dir = glob(APP_CACHE.'/*')) > 300) {
      foreach ($dir as $f) {
        if (is_file($f)) unlink($f);
      }
    } else {
      if (file_exists($file) && filemtime($file) >= $updated) {
        exit(page_output(file_get_contents($file)));
      }
    }

    $cache = $file;
  } else {
    $cache = null;
  }

  return $cache;
}

function page_path() {
  static $path;
  if (isset($path)) {
    return $path;
  }

  $path = preg_match('/^
    (?:[^:\/]+:\/\/[^\/?\#]+)? # scheme-authority
    \/([^?\#]+?)\/?            # path
    (?:\?([^\#]+))?            # query string
    (?:\#.*)?                  # fragment
  $/x', $_SERVER['REQUEST_URI'], $m)
    ? explode('/', $m[1])
    : array();

  return $path;
}

function page_error($title, $message = null) {
  static $response;
  if (!isset($response)) {
    $response = array(
      400 => 'Bad request.',
      403 => 'You are not allowed to access this.',
      404 => 'Sorry, the page wasn\'t found.',
      410 => 'Sorry, the page you requested is gone.'
    );
  }
  $status = $title = null;
  if (is_numeric($title)) {
    if (!$message && isset($response[$title])) {
      $message = $response[$title];
    }
    $status = $title;
  }
  $content = template_parse('section', array(
    'title' => $title,
    'content' => template_parse('error', array('message' => $message))
  ));
  page_build($content, $title, null, null, $status, APP_TYPE);
}

function page_build($content, $title = null,
                    $name = null, $canonical = null,
                    $status = null, $type = null) {
  page_output(template_parse('root', array(
    'title' => $title
      ? strtolower(htmlspecialchars(
        preg_replace('/<[^>]+>/', '', $title)
      ))
      : false,
    'content' => $content,
    'canonical' => $canonical,
    'rel' => array($name => true)
  )), $status, $type);
}

function page_output($data, $status = null, $type = 'text/html') {
  header('Content-Type: '.$type.'; charset=utf-8');
  header('Content-Language: en-US');
  header('X-UA-Compatible: IE=Edge,chrome=1');

  $cache = page_cache();

  if ($cache) file_put_contents($cache, $data.(
    APP_HOST === '127.0.0.1' && strstr($type, 'ml')
      ? '<!-- '.str_replace('-', '.', date('c', time())).' -->'
      : ''
  ), LOCK_EX);

  header(' ', true, isset($status)
    ? $status
    : 200
  );
  exit($data);
}


function template_check($var, $name, $bool = null) {
  $name = preg_replace('/\]/', '', preg_replace('/\[|\./', '#', $name));
  foreach (explode('#', $name) as $key) {
    $var = (isset($var[$key]) ? $var[$key] : null);
    if (!is_array($var)) break;
  }
  if ($ret = ($bool !== null)) {
    $bool = ($bool != '!');
  }
  if ($var === true) {
    return $ret ? $bool : '';
  } elseif ($var === false || $var === null) {
    return $ret ? !$bool : '';
  } else {
    return $ret ? $bool : $var;
  }
}

function template_parse($name, $vars) {
  static $templates = array();

  if (!isset($templates[$name])) {
    $templates[$name] = file_get_contents(
      APP_TEMPLATES.'/'.$name
      .(!strstr($name, '.') ? '.html' : '')
    )
  }

  $tmp = $templates[$name];
  $tmp = str_replace(array("\r\n", "\r"), "\n", $tmp)."\n";
  $tmp = preg_replace_callback(
    '/\\\(.)/s',
    create_function('$m', 'return ":\\r".ord($m[1])."\\r:";'),
    $tmp
  );

  if (strstr($tmp, '];')
      && preg_match_all('/(\s+)&:([^\s\[]*)\[(.+?)\];/s', $tmp, $m)) {
    foreach ($m[0] as $i => $_) {
      $iterations = array();
      $subj = $m[2][$i] ? template_check($vars, $m[2][$i]) : $vars;
      foreach ($subj as $key => $v) {
        $iterations[] = str_replace(
          '&:this',
          '&:'.($m[2][$i] ? $m[2][$i].'#' : '').$key,
          $m[3][$i]
        );
      }
      $tmp = str_replace(
        $m[0][$i],
        $m[1][$i].implode($m[1][$i], $iterations),
        $tmp
      );
    }
  }

  if (strstr($tmp, '&:')) {
    $tmp = preg_replace('/(\n)[ \t]+(?=!*&:)/', '$1', $tmp);
    while (preg_match('/{[^{}]+}/', $tmp, $m)) {
      $span = $m[0]; $cond = $total = 0;
      if (preg_match_all('/(!*)&:([^\s;]+);/', $span, $m)) {
        foreach ($m[0] as $i => $_) {
          $total++;
          if (template_check($vars, $m[2][$i], $m[1][$i])) {
            $cond++;
          }
        }
      }
      $tmp = ($cond === $total)
        ? str_replace($span, substr($span, 1, -1), $tmp)
        : str_replace($span, '', $tmp);
    }
    $tmp = preg_replace('/!+&:[^\s;]+;/', '', $tmp);
    preg_match_all('/&:([^\s;]+);/', $tmp, $m);
    foreach ($m[1] as $var) {
      $tmp = str_replace('&:'.$var.';', template_check($vars, $var), $tmp);
    }
  }

  $tmp = preg_replace_callback(
    '/:\r(\d+)\r:/s',
    create_function('$m', 'return chr($m[1]);'),
    $tmp
  );
  $tmp = preg_replace('/(\n)\n+|(\n)[\x20\t]+\n/', '$1$2', $tmp);

  return $tmp;
}

function template($name, $vars) {
  return template_parse($name, $vars);
}

function render($name, $vars) {
  return template_parse($name, $vars);
}

?>
