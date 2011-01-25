<?php
//pingbacks - not completely ready yet

require('./init.php');
header('Content-Type: text/xml'); //the xmlrpc spec says to use text/xml

error_reporting(E_ALL);
set_error_handler('rpc_fault');

function rpc_fault($fault_code, $str = null) {
	$str && $fault_code = 0;
	$faults = array(
		0 => 'Error.',
		16 => 'The sourceURI does not exist.',
		17 => 'The sourceURI does not contain a link to the targetURI.',
		32 => 'The specified targetURI does not exist.',
		33 => 'The specified targetURI cannot be used as a target.',
		48 => 'The pingback has already been registered.',
		49 => 'Access denied.'
	);
	exit(
		'<?xml version="1.0"?>'."\n".
		'<methodResponse>'."\n".
		'	<fault><value><struct>'."\n".
		'		<member>'."\n".
		'			<name>faultCode</name>'."\n".
		'			<value><int>'.$fault_code.'</int></value>'."\n".
		'		</member>'."\n".
		'		<member>'."\n".
		'			<name>faultString</name>'."\n".
		'			<value><string>'.$faults[$fault_code].'</string></value>'."\n".
		'		</member>'."\n".
		'	</struct></value></fault>'."\n".
		'</methodResponse>'
	);
}

//grab a url using curl or file_get_contents
function request_url($url) {
	if (function_exists('curl_init')) {
		$request = curl_init();
		curl_setopt($request, CURLOPT_URL, $url);
		curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($request, CURLOPT_HEADER, false);
		curl_setopt($request, CURLOPT_TIMEOUT, 5);
		curl_setopt($request, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($request, CURLOPT_MAXREDIRS, 1);
		$response = curl_exec($request);
		curl_close($request);
		return $response;
	} elseif (ini_get('allow_url_fopen')) {
		return file_get_contents($url, false, 
			stream_context_create(array(
				'http' => array(
					'method' => 'GET',
					'max_redirects' => 1,
					'timeout' => 5
				)
			))
		);
	}
}

//make sure the request is a pingback
if ($_SERVER['REQUEST_METHOD'] == 'POST' && @stristr($_SERVER['CONTENT_TYPE'], '/xml'))
	$body = file_get_contents('php://input');
if (!empty($body) && strstr($body, '<methodName>pingback.ping</methodName>')) {
	//look for the uri's with a regex
	if (preg_match_all('/
		<name>\s*([^<]+?)\s*<\/name>\s*
		<value>\s*<string>\s*(?:<!\[CDATA\[)?(.+?)(?:\]\]>)?\s*<\/string>\s*<\/value>
	/sx', $body, $m)) {
		$db = new db();
		
		//grab the uri's
		for ($i = 0; $i < count($m[0]); $i++) {
			if ($m[1][$i] == 'sourceURI') 
				$source_uri = str_replace('&amp;', '&', trim($m[2][$i]));
			elseif ($m[1][$i] == 'targetURI') 
				$target_uri = str_replace('&amp;', '&', trim($m[2][$i]));
		}
		
		//make sure they're not the same
		($source_uri == $target_uri) && rpc_fault(33);
		
		//check to make sure the uri's exist and are valid urls
		(isset($source_uri) && preg_match(($regex = '/^https?:\/\/[^\/]+\/.*$/i'), $source_uri)) || rpc_fault(16);
		(isset($target_uri) && preg_match($regex, $target_uri)) || rpc_fault(33); 
		
		//get the uri slug to identify the article
		preg_match('/^https?:\/\/[^\/]+\/(\w+)\/?(?:#.+)?$/i', $target_uri, $m) || rpc_fault(33); 
		
		//make sure the slug corresponds to an existing article that allows comments
		$db->fetch('id', 'articles', 'WHERE id=? AND comments IS NOT NULL', $m[1]) || rpc_fault(32);
		
		//make sure the pingback wasnt already recorded
		$db->fetch('id', 'comments', 'WHERE parent=? AND poster_site=?', array($slug = $m[1], $source_uri)) && rpc_fault(48);
		
		$no_request = true;
		
		//add the pingback to comments
		if (isset($no_request)) $db->insert('comments', array(
			'parent' => $slug,
			'timestamp' => time(),
			'poster_name' => $host = preg_replace('/^\w+:\/\/([^\/]+)\/.*$/', '$1', $source_uri), 
			'poster_site' => $source_uri,
			'poster_ip' => 'admin',
			'content' => 'Pingback received from <a href="'.htmlspecialchars($source_uri).'" rel="external">'.$host.'</a>.'
		));
		
		//make sure the source uri exists and retreive the text of the page
		if(!isset($no_request)) if ($page = request_url($source_uri)) {
			//get the title of the page
			if (preg_match('/<title>([^<]+)<\/title>/i', $page, $m)) 
				$title = trim($m[1]);
			if (!isset($title) || strlen($title) > 25)
				$title = preg_replace('/^\w+:\/\/([^\/]+)\/.*$/', '$1', $source_uri);
			
			//make sure the link to the target uri actually exists on the page and grab an excerpt
			if (stristr($page, $target_uri)) {
				//replace the link with placeholder tags to mark its position
				$page = preg_replace('/<a[^>]+'.preg_quote($target_uri, '/').'[^>]+>(.+?)<\/a>/is', ':L:$1:L:', $page);
				
				//remove all markup
				$page = preg_replace('/<[^>]+>/', '', $page);
				
				//find the link again and grab 10 words on each side of it
				preg_match('/((?:[^\s]+\s+){0,10}):L:(.+?):L:((?:\s+[^\s]+){0,10})/s', $page, $m) || rpc_fault(0);
				
				//add the pingback to the article as a comment
				$db->insert('comments', array(
					'parent' => $slug,
					'timestamp' => time(),
					'poster_name' => $title,
					'poster_site' => $source_uri,
					'poster_ip' => 'admin',
					//put the excerpt together, make sure its not more than 300 characters long
					'content' => '<q cite="'.htmlspecialchars($source_uri).'">[...] '
						.htmlspecialchars(substr(trim($m[1].' '.$m[2].' '.$m[3]), 0, 300)).' [...]</q>'
				));
			} else {
				rpc_fault(17);
			} 
		} else {
			rpc_fault(16);
		} 
	} else {
		rpc_fault(16);
	}
} else {
	rpc_fault(0);
}
exit(
	'<?xml version="1.0"?>'."\n".
	'<methodResponse>'."\n".
	'	<params><param>'."\n".
	'		<value><string>Success.</string></value>'."\n".
	'	</param></params>'."\n".
	'</methodResponse>'
);
?>