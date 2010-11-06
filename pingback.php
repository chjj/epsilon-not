<?php
	//pingbacks - not ready yet
	
	require($_SERVER['DOCUMENT_ROOT'].'/init.php');
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
				if ($m[1][$i] == 'sourceURI') $source_uri = $m[2][$i];
				elseif ($m[1][$i] == 'targetURI') $target_uri = $m[2][$i];
			}
			
			//check to make sure the uri's exist and are valid urls
			(isset($source_uri) && preg_match(($regex = '/^http:\/\/[\w\-]+(?:\.[\w\-]+)+\/.*$/i'), $source_uri)) || rpc_fault(16);
			(isset($target_uri) && preg_match($regex, $target_uri)) || rpc_fault(33); 
			
			//get the uri slug to identify the article
			preg_match('/^http:\/\/.+\/(\w+)/?(?:#.+)?$/i', $target_uri, $m) || rpc_fault(33); 
			
			//make sure the slug corresponds to an existing article that allows comments
			$db->fetch('id', 'articles', 'WHERE id=? AND comments IS NOT NULL', $m[1]) || rpc_fault(32);
			
			//make sure the pingback wasnt already recorded
			$db->fetch('id', 'comments', 'WHERE parent=? AND poster_site=?', array($slug = $m[1], $source_uri)) && rpc_fault(48);
			
			rpc_fault(0); //not ready yet
			//make sure the source uri exists and retreive the text of the page
			if ($page = file_get_contents($source_uri)) {
				//get the title of the page
				if (preg_match('/<title>(.+?)<\/title>/is', $page, $m)) 
					$title = htmlspecialchars(trim($m[1]));
				
				//make sure the link to the target uri actually exists on the page and grab an excerpt 
				if (preg_match('/((?:\w+\s){0,10})<a[^>]+'.preg_quote($target_uri, '/').'[^>]+>(.+?)<\/a>((?:\w+\s){0,10})/is', $page, $m)) { 
					//add the pingback to the article
					$db->insert('comments', array(
						'parent' => $slug,
						'timestamp' => time(),
						'poster_name' => $title,
						'poster_site' => $source_url,
						'content' => '<q cite="'.htmlspecialchars($source_url).'">...'.
							htmlspecialchars(substr(trim($m[1].' '.preg_replace('/<[^>]+>/', '', $m[2]).' '.$m[3]), 0, 300)).'...</q>'
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