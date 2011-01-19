<?php 
//initialize the page and define functions/classes

error_reporting($_SERVER['HTTP_HOST'] == 'localhost' ? E_ALL|E_STRICT : 0);

date_default_timezone_set('US/Central');

define('APP_DBTYPE', 'sqlite');
define('APP_DBNAME', $_SERVER['DOCUMENT_ROOT'].'/../sqlite/epsilon.db');
define('APP_PASSWORD', '45cceb16100239fc2028a82db1fcd2a0d669bb5722806777');
define('APP_LOGIN', (isset($_COOKIE['epsilon_user']) 
	? (hash('tiger192,4', base64_decode($_COOKIE['epsilon_user'])) == APP_PASSWORD) 
	: false));
define('APP_TEMPLATES', $_SERVER['DOCUMENT_ROOT'].'/front/templates');

class db {
	public $handle;
	public $result;
	
	public function __construct() {
		if (!defined('APP_DBHOST')) {
			$this->handle = new PDO(APP_DBTYPE.':'.APP_DBNAME); 
		} else {
			$this->handle = new PDO(
				APP_DBTYPE.':dbname='.APP_DBNAME.';host='.APP_DBHOST, 
				APP_DBUSERNAME, APP_DBPASSWORD, array(PDO::ATTR_AUTOCOMMIT => true)
			);
		}
		$this->handle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
		if (file_exists($sql = $_SERVER['DOCUMENT_ROOT'].'/tables.sql') && (
			(APP_DBTYPE == 'sqlite' && !$this->fetch('name', 'sqlite_master', 'WHERE type=?', 'table')) 
			|| (APP_DBTYPE != 'sqlite' && !$this->query('SHOW TABLES'))
		)) $this->exec(file_get_contents($sql));
	}
	
	public function __destruct() {
		$this->handle = null;
	}
	
	public function __get($name) {
		if ($name == 'insert_id') {
			return $this->handle->lastInsertId();
		} elseif ($name == 'error') {
			return print_r($this->handle->errorInfo(), true);
		}
	}
	
	public function query($query, $vars = false) {
		if (!is_array($vars)) 
			$vars = ($vars === false) ? array() : array($vars);
		$this->result = $this->handle->prepare($query);
		$this->result->execute($vars);
		return $this->result;
	}
	
	public function exec($query, $vars = false) {
		return ($vars !== false)
			? $this->query($query, $vars)->rowCount()
			: $this->handle->exec($query);
	}
	
	public function fetch($field, $table, $statements = null, $vars = false, $all = false) {
		$this->query('SELECT '.$field.' FROM '.$table.($statements ? ' '.$statements : ''), $vars);	
		if ($rows = $this->result->fetchAll(PDO::FETCH_ASSOC)) {
			if (!strstr($field, ',') && $field != '*') 
				foreach ($rows as $i => $val) $rows[$i] = $val[$field]; 
			return ($all || count($rows) > 1) ? $rows : $rows[0];
		}
	}
	
	public function update($table, $row = null, $vars) {
		$fields = array(); 
		foreach ($vars as $field => $val) 
			$fields[] = $field.'=:'.$field;
		return $this->exec('UPDATE '.$table.' SET '.implode(', ', $fields).($row ? ' WHERE '.$row : ''), $vars);
	}
	
	public function insert($table, $vars) {
		return $this->exec('INSERT INTO '.$table.' ('.implode(', ', array_keys($vars)).') VALUES(:'
			.implode(', :', array_keys($vars)).')', $vars);
	}
	
	public function delete($table, $row = null, $vars = false) {
		return $this->exec('DELETE FROM '.$table.($row ? ' WHERE '.$row : ''), $vars);
	}
}

class page {
	public $title;
	public $content;
	
	public static function uri() {
		$args = func_get_args();
		return '/'.(!empty($args) ? implode('/', $args) : '');
	}
	
	public static function redirect($url, $response = 303) {
		$url = preg_replace('/\/{2,}/', '/', '/'.str_replace('&amp;', '&', $url));
		header('Location: http://'.$_SERVER['HTTP_HOST'].$url, true, $response);
		exit;
	}
	
	public static function parse_markup($text, $indent = 0, $filter = true) {
		//clean up the text and make sure newlines are consistent
		$text = str_replace(array("\x00", "\r\n", "\r"), array('', "\n", "\n"), trim($text));
		
		//replace contents of CDATA declarations with entities
		$text = preg_replace_callback('/<!\[CDATA\[(.+?)\]\]>/s',
			create_function('$m', 'return htmlspecialchars($m[1], ENT_QUOTES);'), 
		$text);
		
		//sanitize the markup
		if ($filter) {
			//remove bad elements, attributes, and entities
			$text = preg_replace(
				array(
					'/<\s*(?!\/?(?:'
						.'a|br|b|i|strong|em|small|cite|ins|del|code|samp|kbd|dfn|abbr|mark|time|var|sub|sup|q'
						.')(?:\s|>)).+?>/is',
					'/<.+(?<!title|lang|dir|type|href|rel|cite)\s*=.+>/is',
					'/&(?!lt|gt|amp|apos|quot)[^;]*;/'
				), array('', '', ''), $text
			);
			
			//turn newlines into new paragraphs
			$text = preg_replace(
				array('/<([^>\s]+)([^>]*)>(.*?)\n{2,}(.*?)<\/\1>/s', '/\n{2,}/'),
				array('<$1$2>$3 $4</$1>'                           , '</p><p>' ),
				$text
			);
			
			//make the markup lowercase
			$text = preg_replace_callback('/<[^>]+>/', 
				create_function('$m', 'return strtolower($m[0]);'), 
				'<p>'.$text.'</p>'
			);
		}
		
		//replace <pre> elements with placeholder text so it doesn't get indented
		$placeholders = array();
		if (preg_match_all('/<pre>.+?<\/pre>/is', $text, $m)) {
			for ($i = 0; $i < count($m[0]); $i++) {
				$placeholders[] = preg_replace('/\n+/', '&#x0A;', $m[0][$i]);
				$text = str_replace($m[0][$i], '<PLACEHOLDER:'.(count($placeholders)-1).'/>', $text);
			}
		}
		
		//wrap and indent everything
		$text = ($indent = str_repeat("\t", $indent)).preg_replace(
			array('/[\t\r\n]+/', '/(?:<\/[^>]+>|\/>)(?=\s*<\w)/', '/.{100,}?\s+(?![^<]+>)/' ), 
			array(''           , '$0'."\n".$indent              , '$0'."\n".$indent         ),
		$text);
		
		//replace the placeholders
		foreach ($placeholders as $i => $placeholder) 
			$text = str_replace('<PLACEHOLDER:'.$i.'/>', $placeholder, $text);
		
		//make sure the markup is well-formed
		if (!xml_parse(xml_parser_create('UTF-8'), '<root>'.$text.'</root>', true))
			$text = '<p>'.preg_replace('/<[^>]+>/', '', $text).'</p>'."\n"
				.'<p><small>The above text contained poorly formed markup. All markup has been removed.</small></p>';
		
		return $text;
	}
	
	public function __construct() {
		$this->db = new db();
		$this->path = preg_match('/^\/(.+?)\/?(?:\?.*)?$/', strtolower($_SERVER['REQUEST_URI']), $m) 
			? explode('/', $m[1]) : null;
		
		if (@$_SERVER['REDIRECT_STATUS'] >= 400) 
			$this->error($_SERVER['REDIRECT_STATUS']);
	}
	
	public function error($title, $message = null) {
		if (is_numeric($title)) {
			$response = array(
				403 => array('403 Forbidden', 'You are not allowed to access this.'),
				404 => array('404 Not Found', 'Sorry, the page wasn\'t found. You\'re welcome to try the search below.'),
				410 => array('410 Gone', 'Sorry, the page you requested is gone for good.')
			);
			if (isset($response[$title])) {
				if (!$message) $message = $response[$title][1];
				header($_SERVER['SERVER_PROTOCOL'].' '.($title = $response[$title][0]));
			}
		}
		$this->content = template::parse('section', 
			array('title' => ($this->title = $title), 'content' => '<p>'.$message.'</p>')
		);
		exit($this->build());
	}
	
	public function build() {
		header('Content-Type: text/html; charset=utf-8');
		header('Content-Language: en-US');
		header('X-UA-Compatible: IE=Edge,chrome=1');
		
		$articles = array();
		foreach ($this->db->fetch('*', 'articles', 'ORDER BY timestamp DESC LIMIT 0, 4', false, true) as $article) 
			array_push(
				$articles, self::uri($article['id']), 
				htmlspecialchars($article['title']), 
				date('Y-m-d', $article['timestamp'])
			);
		
		$html = template::parse('root', array_merge(array(
			'title' => $this->title ? strtolower($this->title) : false,
			'content' => $this->content,
			(!$this->path ? 'home' : (
				strstr('development|about|admin', $this->path[0]) 
					? $this->path[0] : 'article'
			)) => true,
			'msie' => strstr(@$_SERVER['HTTP_USER_AGENT'], 'MSIE')
		), $articles));
		
		if (@str_replace('"', '', $_SERVER['HTTP_IF_NONE_MATCH']) == ($etag = md5($html))) {
			header($_SERVER['SERVER_PROTOCOL'].' 304 Not Modified');
			exit;
		} else {
			header('ETag: "'.$etag.'"');
			return $html;
		}
	}
}

class template {
	private static $templates = array();
	
	public static function load($name) {
		return (self::$templates[$name] = file_get_contents(APP_TEMPLATES.'/'.$name.(!strstr($name, '.') ? '.html' : '')));
	}
	
	private static function check($vars, $var, $bool = null) {
		$var = (isset($vars[$var]) ? $vars[$var] : null);
		if ($ret = ($bool !== null)) 
			$bool = ($bool != '!');
		if ($var === true) {
			return $ret ? $bool : '';
		} elseif ($var === false || $var === null) {
			return $ret ? !$bool : '';
		} else {
			return $ret ? $bool : $var;
		}
	}
	
	public static function parse($template_name, $vars) {
		if (!isset(self::$templates[$template_name])) 
			self::load($template_name);
		$template = self::$templates[$template_name];
		if (strstr($template, '&[') && preg_match('/(\s+)&\[(.+?)\];/s', $template, $m)) {
			$num_lines = max(array_keys($vars)) / ($num_vars = substr_count($m[2], '@_;'));
			for ($line = 0; $line < $num_lines; $line++) {
				$str = $m[2];
				for ($var = 0; $var < $num_vars; $var++) 
					$str = preg_replace('/(?<=@)_(?=;)/', ($var+$line*$num_vars), $str, 1); 
				$lines[] = $str;
			}
			$template = str_replace($m[0], $m[1].implode($m[1], $lines), $template);
		}
		if (strstr($template, '@')) { 
			$template = preg_replace('/\t+(?=[!+]?@)/', '', $template).PHP_EOL;
			while (preg_match('/{[^{}]+}/', $template, $m)) {
				$span = $m[0];
				$cond = $total = 0;
				if (preg_match_all('/([!+]?)@([^\s]+?);/', $span, $m)) {
					for ($i = 0; $i < count($m[0]); $i++) {
						$total += 1;
						if (self::check($vars, $m[2][$i], $m[1][$i])) 
							$cond += 1;
					}
					$template = ($cond == $total) 
						? str_replace($span, substr($span, 1, -1), $template)
						: str_replace($span, '', $template);
				}
			}
			$template = preg_replace('/[!+]@[^\s]+?;/', '', $template);
			preg_match_all('/@([^\s]+?);/', $template, $m);
			foreach ($m[1] as $var) 
				$template = str_replace('@'.$var.';', self::check($vars, $var), $template);
		}
		$template = str_replace(array("\r\n", "\r"), array("\n", "\n"), $template);
		$template = preg_replace('/(?<=\n)\n+|\t+\n/', '', $template);
		return $template;
	}
}
?>