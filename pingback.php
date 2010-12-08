<?php
	//pingbacks - not ready yet
	
	require($_SERVER['DOCUMENT_ROOT'].'/init.php');
	header('Content-Type: text/xml'); //the xmlrpc spec says to use text/xml
	
	//test the script
	if ($_SERVER['HTTP_HOST'] == 'localhost' && $_GET && ini_get('allow_url_fopen')) {
		(@!$_GET['sourceURI']) && $_GET['sourceURI'] = 'generated_content_support';
		(@!$_GET['targetURI']) && $_GET['targetURI'] = 'css_dream';
		exit(file_get_contents('http://'.$_SERVER['HTTP_HOST'].'/pingback', false, 
			stream_context_create(array(
				'http' => array(
					'method' => 'POST',
					'header' => 'Content-Type: application/xml',
					'content' => ('<?xml version="1.0"?>'.PHP_EOL.
						'<methodRequest>'.PHP_EOL.
						'	<methodName>pingback.ping</methodName>'.PHP_EOL.
						'	<value><struct>'.PHP_EOL.
						'		<member>'.PHP_EOL.
						'			<name>sourceURI</name>'.PHP_EOL.
						'			<value><string>http://'.$_SERVER['HTTP_HOST'].'/'.$_GET['sourceURI'].'</string></value>'.PHP_EOL.
						'		</member>'.PHP_EOL.
						'		<member>'.PHP_EOL.
						'			<name>targetURI</name>'.PHP_EOL.
						'			<value><string>http://'.$_SERVER['HTTP_HOST'].'/'.$_GET['targetURI'].'</string></value>'.PHP_EOL.
						'		</member>'.PHP_EOL.
						'	</struct></value>'.PHP_EOL.
						'</methodRequest>')
				)
			))
		));
	} else {
		exit;
	}
	
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
			'<?xml version="1.0"?>'.PHP_EOL.
			'<methodResponse>'.PHP_EOL.
			'	<fault><value><struct>'.PHP_EOL.
			'		<member>'.PHP_EOL.
			'			<name>faultCode</name>'.PHP_EOL.
			'			<value><int>'.$fault_code.'</int></value>'.PHP_EOL.
			'		</member>'.PHP_EOL.
			'		<member>'.PHP_EOL.
			'			<name>faultString</name>'.PHP_EOL.
			'			<value><string>'.$faults[$fault_code].'</string></value>'.PHP_EOL.
			'		</member>'.PHP_EOL.
			'	</struct></value></fault>'.PHP_EOL.
			'</methodResponse>'
		);
	}
	
	//make sure the request is a pingback
	$body = file_get_contents('php://input');
	if (isset($_SERVER['CONTENT_TYPE']) && stristr($_SERVER['CONTENT_TYPE'], '/xml') 
	&& !empty($body) && strstr($body, '<methodName>pingback.ping</methodName>')) {
		//look for the uri's with a regex
		if (preg_match_all('/<name>([^<]+)<\/name>\s*<value>\s*<string>([^<]+)<\/string>\s*<\/value>/', $body, $m)) {
			$db = new db();
			
			//grab the uri's
			for ($i = 0; $i < count($m[0]); $i++) {
				if ($m[1][$i] == 'sourceURI') 
					$source_uri = str_replace('&amp;', '&', $m[2][$i]);
				elseif ($m[1][$i] == 'targetURI') 
					$target_uri = str_replace('&amp;', '&', $m[2][$i]);
			}
			
			//make sure they're not the same
			($source_uri == $target_uri) && rpc_fault(33);
			
			//check to make sure the uri's exist and are valid urls
			(isset($source_uri) && preg_match(($regex = '/^https?:\/\/[^\/]+\/.*$/i'), $source_uri)) || rpc_fault(16);
			(isset($target_uri) && preg_match($regex, $target_uri)) || rpc_fault(33); 
			
			//get the uri slug to identify the article
			preg_match('/^http:\/\/[^\/]+\/(\w+)\/?(?:#.+)?$/i', $target_uri, $m) || rpc_fault(33); 
			
			//make sure the slug corresponds to an existing article that allows comments
			$db->fetch('id', 'articles', 'WHERE id=? AND comments IS NOT NULL', $m[1]) || rpc_fault(32);
			
			//make sure the pingback wasnt already recorded
			$db->fetch('id', 'comments', 'WHERE parent=? AND poster_site=?', array($slug = $m[1], $source_uri)) && rpc_fault(48);
			
			//make sure the source uri exists and retreive the text of the page
			if (ini_get('allow_url_fopen') && $page = file_get_contents($source_uri, false, 
				stream_context_create(array(
					'http' => array(
						'method' => 'GET',
						'max_redirects' => 2,
						'timeout' => 5
					)
				))
			)) {
				//get the title of the page
				if (preg_match('/<title>([^<]+)<\/title>/i', $page, $m)) 
					$title = substr(trim($m[1]), 0, 75);
				
				//make sure the link to the target uri actually exists on the page and grab an excerpt
				if (stristr($page, $target_uri)) {
					//replace the link with placeholder tags to mark its position
					$page = preg_replace('/<a[^>]+'.preg_quote($target_uri, '/').'[^>]+>(.+?)<\/a>/is', '___LINK___$1___LINK___', $page);
					
					//remove all markup
					$page = preg_replace('/<[^>]+>/', '', $page);
					
					//find the link again and grab 10 words on each side of it
					preg_match('/((?:[^\s]+\s+){0,10})___LINK___(.+?)___LINK___((?:\s+[^\s]+){0,10})/s', $page, $m) || rpc_fault(0);
					
					//add the pingback to the article as a comment
					$db->insert('comments', array(
						'parent' => $slug,
						'timestamp' => time(),
						'poster_name' => $title,
						'poster_site' => $source_uri,
						'poster_ip' => 'pingback',
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
		'<?xml version="1.0"?>'.PHP_EOL.
		'<methodResponse>'.PHP_EOL.
		'	<params><param>'.PHP_EOL.
		'		<value><string>Success.</string></value>'.PHP_EOL.
		'	</param></params>'.PHP_EOL.
		'</methodResponse>'
	);
?>