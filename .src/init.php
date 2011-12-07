<?php
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

class db {
  public $handle;
  public $result;

  public function __construct() {
    $this->handle = new PDO('sqlite:'.APP_DATA);
    $this->handle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    if (!$this->fetch('name', 'sqlite_master', 'WHERE type=?', 'table')) {
      $this->exec(file_get_contents(APP_SRC.'/data/tables.sql'));
    }
  }

  public function __destruct() {
    unset($this->handle);
  }

  public function __get($name) {
    if ($name === 'insert_id') {
      return $this->handle->lastInsertId();
    } elseif ($name === 'error') {
      return print_r($this->handle->errorInfo(), true);
    }
  }

  public function query($query, $vars = false) {
    if (!is_array($vars)) {
      $vars = ($vars === false) ? array() : array($vars);
    }
    $this->result = $this->handle->prepare($query);
    $this->result->execute($vars);
    return $this->result;
  }

  public function exec($query, $vars = false) {
    return ($vars !== false)
      ? $this->query($query, $vars)->rowCount()
      : $this->handle->exec($query);
  }

  public function fetch($field, $table, $statements = null, $vars = false) {
    $this->query(
      'SELECT '.$field.' FROM '
      .$table.($statements ? ' '.$statements : ''),
      $vars
    );
    $rows = $this->result->fetchAll(PDO::FETCH_ASSOC);
    return $rows ? $rows : array();
  }

  public function grab($field, $table, $statements = null, $vars = false) {
    $rows = $this->fetch($field, $table, $statements, $vars);
    if ($rows && count($rows) > 0) {
      if (!strstr($field, ',') && $field !== '*') {
        foreach ($rows as $i => $val) $rows[$i] = $val[$field];
      }
      return (count($rows) > 1) ? $rows : $rows[0];
    }
    return null;
  }

  public function update($table, $rows = null, $vars) {
    $fields = array();
    foreach ($vars as $field => $val) {
      $fields[] = $field.'=:'.$field;
    }
    return $this->exec('UPDATE '.$table
      .' SET '.implode(', ', $fields)
      .($rows ? ' WHERE '.$rows : ''),
    $vars);
  }

  public function insert($table, $vars) {
    return $this->exec('INSERT INTO '.$table.' ('
      .implode(', ', array_keys($vars)).') VALUES(:'
      .implode(', :', array_keys($vars)).')',
    $vars);
  }

  public function delete($table, $rows = null, $vars = false) {
    return $this->exec(
      'DELETE FROM '
      .$table.($rows ? ' WHERE '.$rows : ''),
    $vars);
  }
}

class page {
  public $name;
  public $type;
  public $title;
  public $content;
  public $cache;
  public $canonical;
  public $updated;

  public static function uri() {
    $args = func_get_args();
    if (isset($args[0]) && is_array($args[0])) $args = $args[0];
    return '/'.(!empty($args) ? implode('/', $args) : '');
  }

  public static function redirect($uri, $response = 303) {
    $uri = preg_replace('/\/{2,}/', '/', '/'.str_replace('&amp;', '&', $uri));
    header('Location: http://'.APP_HOST.$uri, true, $response);
    exit;
  }

  public static function parse_markup($text, $indent = 0, $filter = true) {
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

  public function __construct($type = APP_TYPE, $cache = APP_DATA) {
    $this->type = $type;
    $this->updated = filemtime($cache);

    // orchestrate the caching
    if ($_SERVER['REQUEST_METHOD'] === 'GET' 
        && APP_HOST !== '127.0.0.1') {
      header('Last-Modified: '.$this->updated);
      if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $this->updated) {
        header(' ', true, 304);
        exit;
      }

      $file = APP_CACHE.'/'.md5($_SERVER['REQUEST_URI'].APP_LOGIN);
      if (count($dir = glob(APP_CACHE.'/*')) > 300) {
        foreach ($dir as $f) {
          if (is_file($f)) unlink($f);
        }
      } else {
        if (file_exists($file) && filemtime($file) >= $this->updated) {
          exit($this->output(file_get_contents($file)));
        }
      }

      $this->cache = $file;
    }

    $this->db = new db();

    $this->path = preg_match('/^
      (?:[^:\/]+:\/\/[^\/?\#]+)? # scheme-authority
      \/([^?\#]+?)\/?            # path
      (?:\?([^\#]+))?            # query string
      (?:\#.*)?                  # fragment
    $/x', $_SERVER['REQUEST_URI'], $m)
      ? explode('/', $m[1])
      : array();

    $this->query = isset($m[2]) ? $m[2] : null;
  }

  public function error($title, $message = null) {
    if (is_numeric($title)) {
      $response = array(
        400 => 'Bad request.',
        403 => 'You are not allowed to access this.',
        404 => 'Sorry, the page wasn\'t found.',
        410 => 'Sorry, the page you requested is gone.'
      );
      if (!$message && isset($response[$title])) {
        $message = $response[$title];
      }
      $this->status = $title;
    }
    $this->type = APP_TYPE;
    $this->cache = null;
    $this->content = template::parse('section', array(
      'title' => $this->title = $title,
      'content' => template::parse('error', array('message' => $message))
    ));
    $this->build();
  }

  public function build() {
    $this->output(template::parse('root', array(
      'title' => $this->title 
        ? strtolower(htmlspecialchars(
          preg_replace('/<[^>]+>/', '', $this->title)
        )) 
        : false,
      'content' => $this->content,
      'canonical' => $this->canonical,
      'rel' => array($this->name => true)
    )));
  }

  public function output($data) {
    header('Content-Type: '.$this->type.'; charset=utf-8');
    header('Content-Language: en-US');
    header('X-UA-Compatible: IE=Edge,chrome=1');

    if ($this->cache) file_put_contents($this->cache, $data.(
      APP_HOST === '127.0.0.1' && strstr($this->type, 'ml')
        ? '<!-- '.str_replace('-', '.', date('c', time())).' -->'
        : ''
    ), LOCK_EX);

    header(' ', true, isset($this->status)
      ? $this->status
      : 200
    );
    exit($data);
  }
}

class template {
  private static $templates = array();

  public static function load($name) {
    return (self::$templates[$name] = file_get_contents(
      APP_TEMPLATES.'/'.$name
      .(!strstr($name, '.') ? '.html' : '')
    ));
  }

  private static function check($var, $name, $bool = null) {
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

  public static function parse($name, $vars) {
    if (!isset(self::$templates[$name])) {
      self::load($name);
    }

    $tmp = self::$templates[$name];
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
        $subj = $m[2][$i] ? self::check($vars, $m[2][$i]) : $vars;
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
            if (self::check($vars, $m[2][$i], $m[1][$i])) {
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
        $tmp = str_replace('&:'.$var.';', self::check($vars, $var), $tmp);
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
}
?>