<?php 
function getFileContents($url) {
	global $user_agent;
	$urlparts = parse_url($url);
	$path = $urlparts['path'];
	$host = $urlparts['host'];
	if ($urlparts['query'] != "")
		$path .= "?".$urlparts['query'];
	if (isset ($urlparts['port'])) {
		$port = (int) $urlparts['port'];
	} else
		if ($urlparts['scheme'] == "http") {
			$port = 80;
		} else
			if ($urlparts['scheme'] == "https") {
				$port = 443;
			}

	if ($port == 80) {
		$portq = "";
	} else {
		$portq = ":$port";
	}

	$all = "*/*";

	$request = "GET $path HTTP/1.0\r\nHost: $host$portq\r\nAccept: $all\r\nAccept-Encoding: identity\r\nUser-Agent: $user_agent\r\n\r\n";

	$fsocket_timeout = 30;
	if (substr($url, 0, 5) == "https") {
		$target = "ssl://".$host;
	} else {
		$target = $host;
	}


	$errno = 0;
	$errstr = "";
	$fp = @ fsockopen($target, $port, $errno, $errstr, $fsocket_timeout);

	if (!$fp) {
		$contents['state'] = "NOHOST";
		printConnectErrorReport($errstr);
		return $contents;
	} else {
		if (!fputs($fp, $request)) {
			$contents['state'] = "Cannot send request";
			return $contents;
		}
		$data = null;
		socket_set_timeout($fp, $fsocket_timeout);
		$status = socket_get_status($fp);
		while (!feof($fp) && !$status['timed_out']) {
			$data .= fgets($fp, 8192);
		}
		fclose($fp);
		if ($status['timed_out'] == 1) {
			$contents['state'] = "timeout";
		} else
			$contents['state'] = "ok";
		$contents['file'] = substr($data, strpos($data, "\r\n\r\n") + 4);
	}
	return $contents;
}

/*
check if file is available and in readable form
*/
function url_status($url) {
	global $user_agent, $index_pdf, $index_doc, $index_xls, $index_ppt;
	$urlparts = parse_url($url);
	$path = $urlparts['path'];
	$host = $urlparts['host'];
	if (isset($urlparts['query']))
		$path .= "?".$urlparts['query'];

	if (isset ($urlparts['port'])) {
		$port = (int) $urlparts['port'];
	} else
		if ($urlparts['scheme'] == "http") {
			$port = 80;
		} else
			if ($urlparts['scheme'] == "https") {
				$port = 443;
			}

	if ($port == 80) {
		$portq = "";
	} else {
		$portq = ":$port";
	}

	$all = "*/*"; //just to prevent "comment effect" in get accept
	$request = "HEAD $path HTTP/1.1\r\nHost: $host$portq\r\nAccept: $all\r\nAccept-Charset: iso-8859-1\r\nAccept-Encoding: identity\r\nUser-Agent: $user_agent\r\n\r\n";

	if (substr($url, 0, 5) == "https") {
		$target = "ssl://".$host;
	} else {
		$target = $host;
	}

	$fsocket_timeout = 30;
	$errno = 0;
	$errstr = "";
	$fp = fsockopen($target, $port, $errno, $errstr, $fsocket_timeout);

	$linkstate = "ok";
	if (!$fp) {
		$status['state'] = "NOHOST";
	} else {
		socket_set_timeout($fp, 30);
		fputs($fp, $request);
		$answer = fgets($fp, 4096);
		$regs = Array ();
		if (ereg("HTTP/[0-9.]+ (([0-9])[0-9]{2})", $answer, $regs)) {
			$httpcode = $regs[2];
			$full_httpcode = $regs[1];

			if ($httpcode <> 2 && $httpcode <> 3) {
				$status['state'] = "Unreachable: http $full_httpcode";
				$linkstate = "Unreachable";
			}
		}

		if ($linkstate <> "Unreachable") {
			while ($answer) {
				$answer = fgets($fp, 4096);

				if (ereg("Location: *([^\n\r ]+)", $answer, $regs) && $httpcode == 3 && $full_httpcode != 302) {
					$status['path'] = $regs[1];
					$status['state'] = "Relocation: http $full_httpcode";
					fclose($fp);
					return $status;
				}

				if (eregi("Last-Modified: *([a-z0-9,: ]+)", $answer, $regs)) {
					$status['date'] = $regs[1];
				}

				if (eregi("Content-Type:", $answer)) {
					$content = $answer;
					$answer = '';
					break;
				}
			}
			$socket_status = socket_get_status($fp);
			if (eregi("Content-Type: *([a-z/.-]*)", $content, $regs)) {
				if ($regs[1] == 'text/html' || $regs[1] == 'text/' || $regs[1] == 'text/plain') {
					$status['content'] = 'text';
					$status['state'] = 'ok';
				} else if ($regs[1] == 'application/pdf' && $index_pdf == 1) {
					$status['content'] = 'pdf';
					$status['state'] = 'ok';                                 
				} else if (($regs[1] == 'application/msword' || $regs[1] == 'application/vnd.ms-word') && $index_doc == 1) {
					$status['content'] = 'doc';
					$status['state'] = 'ok';
				} else if (($regs[1] == 'application/excel' || $regs[1] == 'application/vnd.ms-excel') && $index_xls == 1) {
					$status['content'] = 'xls';
					$status['state'] = 'ok';
				} else if (($regs[1] == 'application/mspowerpoint' || $regs[1] == 'application/vnd.ms-powerpoint') && $index_ppt == 1) {
					$status['content'] = 'ppt';
					$status['state'] = 'ok';
				} else {
					$status['state'] = "Not text or html";
				}

			} else
				if ($socket_status['timed_out'] == 1) {
					$status['state'] = "Timed out (no reply from server)";

				} else
					$status['state'] = "Not text or html";

		}
	}
	fclose($fp);
	return $status;
}

/*
Read robots.txt file in the server, to find any disallowed files/folders
*/
function check_robot_txt($url) {
	global $user_agent;
	$urlparts = parse_url($url);
	$url = 'http://'.$urlparts['host']."/robots.txt";

	$url_status = url_status($url);
	$omit = array ();

	if ($url_status['state'] == "ok") {
		$robot = file($url);
		if (!$robot) {
			$contents = getFileContents($url);
			$file = $contents['file'];
			$robot = explode("\n", $file);
		}

		$regs = Array ();
		$this_agent= "";
		while (list ($id, $line) = each($robot)) {
			if (eregi("^user-agent: *([^#]+) *", $line, $regs)) {
				$this_agent = trim($regs[1]);
				if ($this_agent == '*' || $this_agent == $user_agent)
					$check = 1;
				else
					$check = 0;
			}

			if (eregi("disallow: *([^#]+)", $line, $regs) && $check == 1) {
				$disallow_str = eregi_replace("[\n ]+", "", $regs[1]);
				if (trim($disallow_str) != "") {
					$omit[] = $disallow_str;
				} else {
					if ($this_agent == '*' || $this_agent == $user_agent) {
						return null;
					}
				}
			}
		}
	}

	return $omit;
}

/*
Remove the file part from an url (to build an url from an url and given relative path)
*/
function remove_file_from_url($url) {
	$url_parts = parse_url($url);
	$path = $url_parts['path'];

	$regs = Array ();
	if (eregi('([^/]+)$', $path, $regs)) {
		$file = $regs[1];
		$check = $file.'$';
		$path = eregi_replace($check, "", $path);
	}
	$url = $url_parts['scheme']."://".$url_parts['host'].$path;
	return $url;
}

/*
Extract links from html
*/
function get_links($file, $url, $can_leave_domain, $base) {
	$chunklist = array ();

    // The base URL comes from either the meta tag or the current URL.
    if (empty($base)) {
        $base = $url;
    }

	$chunklist = explode(">", $file);
	$links = array ();
	$regs = Array ();
	while (list ($id, $chunk) = each($chunklist)) {

		if (stristr($chunk, "href")) {
			while (preg_match("/(href)\s*=\s*[\'\"]?(([[a-z]{3,5}:\/\/(([.a-zA-Z0-9-])+(:[0-9]+)*))*([+:%\/\?~=&;\\\(\),._ a-zA-Z0-9-]*))(#[.a-zA-Z0-9-]*)?[\'\" ]?(\s*rel\s*=\s*[\'\"]?(nofollow)[\'\"]?)?/i", $chunk, $regs)) {
				if (!isset ($regs[10])) { //if nofollow is not set
					if (($a = url_purify($regs[2], $base, $can_leave_domain)) != '')
						$links[] = $a;
				}
				$pos = strpos ($chunk, $regs[0]);
				$chunk = substr($chunk, $pos + strlen($regs[0]));

			}
		}

		if (stristr($chunk, "frame") && stristr($chunk, "src")) {
			while (eregi("(frame[^>]*src[[:blank:]]*)=[[:blank:]]*[\'\"]?(([[a-z]{3,5}://(([.a-zA-Z0-9-])+(:[0-9]+)*))*([+:%/?=&;\\\(\),._ a-zA-Z0-9-]*))(#[.a-zA-Z0-9-]*)?[\'\" ]?", $chunk, $regs)) {
				if (($a = url_purify($regs[2], $base, $can_leave_domain)) != '')
					$links[] = $a;

				$chunk = str_replace($regs[0], "", $chunk);
			}
		}

		if (stristr($chunk, "window") && stristr($chunk, "location")) {
			while (eregi("(window[.]location)[[:blank:]]*=[[:blank:]]*[\'\"]?(([[a-z]{3,5}://(([.a-zA-Z0-9-])+(:[0-9]+)*))*([+:%/?=&;\\\(\),._ a-zA-Z0-9-]*))(#[.a-zA-Z0-9-]*)?[\'\" ]?", $chunk, $regs)) {
				if (($a = url_purify($regs[2], $base, $can_leave_domain)) != '')
					$links[] = $a;

				$chunk = str_replace($regs[0], "", $chunk);
			}
		}

		if (stristr($chunk, "http-equiv")) {
			while (eregi("(http-equiv=['\"]refresh['\"] *content=['\"][0-9]+;url)[[:blank:]]*=[[:blank:]]*[\'\"]?(([[a-z]{3,5}://(([.a-zA-Z0-9-])+(:[0-9]+)*))*([+:%/?=&;\\\(\),._ a-zA-Z0-9-]*))(#[.a-zA-Z0-9-]*)?[\'\" ]?", $chunk, $regs)) {
				if (($a = url_purify($regs[2], $base, $can_leave_domain)) != '')
					$links[] = $a;

				$chunk = str_replace($regs[0], "", $chunk);
			}
		}

		if (stristr($chunk, "window") && stristr($chunk, "open")) {
			while (eregi("(window[.]open[[:blank:]]*[(])[[:blank:]]*[\'\"]?(([[a-z]{3,5}://(([.a-zA-Z0-9-])+(:[0-9]+)*))*([+:%/?=&;\\\(\),._ a-zA-Z0-9-]*))(#[.a-zA-Z0-9-]*)?[\'\" ]?", $chunk, $regs)) {
				if (($a = url_purify($regs[2], $base, $can_leave_domain)) != '')
					$links[] = $a;

				$chunk = str_replace($regs[0], "", $chunk);
			}
		}

	}

	return $links;
}

/*
Function to build a unique word array from the text of a webpage, together with the count of each word 
*/
function unique_array($arr) {
	global $min_word_length;
	global $common;
	global $word_upper_bound;
	global $index_numbers, $stem_words;
	
	if ($stem_words == 1) {
		$newarr = Array();
		foreach ($arr as $val) {
			$newarr[] = stem($val);
		}
		$arr = $newarr;
	}
	sort($arr);
	reset($arr);
	$newarr = array ();

	$i = 0;
	$counter = 1;
	$element = current($arr);

	if ($index_numbers == 1) {
		$pattern = "/[a-z0-9]+/";
	} else {
		$pattern = "/[a-z]+/";
	}

	$regs = Array ();
	for ($n = 0; $n < sizeof($arr); $n ++) {
		//check if word is long enough, contains alphabetic characters and is not a common word
		//to eliminate/count multiple instance of words
		$next_in_arr = next($arr);
		if ($next_in_arr != $element) {
			if (strlen($element) >= $min_word_length && preg_match($pattern, remove_accents($element)) && (@ $common[$element] <> 1)) {
				if (preg_match("/^(-|\\\')(.*)/", $element, $regs))
					$element = $regs[2];

				if (preg_match("/(.*)(\\\'|-)$/", $element, $regs))
					$element = $regs[1];

				$newarr[$i][1] = $element;
				$newarr[$i][2] = $counter;
				$element = current($arr);
				$i ++;
				$counter = 1;
			} else {
				$element = $next_in_arr;
			}
		} else {
				if ($counter < $word_upper_bound)
					$counter ++;
		}

	}
	return $newarr;
}

/*
Checks if url is legal, relative to the main url.
*/
function url_purify($url, $parent_url, $can_leave_domain) {
	global $ext, $mainurl, $apache_indexes, $strip_sessids;


	reset($ext);

	$url = convert_url($url);
	while (list ($id, $excl) = each($ext))
		if (eregi("\.$excl$", $url))
			return '';

	if (substr($url, -1) == '\\') {
		return '';
	}

	$urlparts = parse_url($url);

	if (isset($urlparts['query'])) {
		if ($apache_indexes[$urlparts['query']]) {
			return '';
		}
	}

	if (eregi("[/]?mailto:|[/]?javascript:|[/]?news:", $url)) {
		return '';
	}
	if (isset($urlparts['scheme'])) {
		$scheme = $urlparts['scheme'];
	} else {
		$scheme ="";
	}



	//only http and https links are followed
	if (!($scheme == 'http' || $scheme == '' || $scheme == 'https')) {
		return '';
	}

	//parent url might be used to build an url from relative path
	$parent_url = remove_file_from_url($parent_url);
	$parent_url_parts = parse_url($parent_url);


	if (substr($url, 0, 1) == '/') {
		$url = $parent_url_parts['scheme']."://".$parent_url_parts['host'].$url;
	} else
		if (!isset($urlparts['scheme'])) {
			$url = $parent_url.$url;
		}

	$url_parts = parse_url($url);

	$urlpath = $url_parts['path'];
	if ($urlpath=='')
		$urlpath=$urlpath."/";

	$regs = Array ();
	while (ereg("[^/]*/[.]{2}/", $urlpath, $regs)) {
		$urlpath = str_replace($regs[0], "", $urlpath);
	}

	//remove relative path instructions like ../ etc 
	$urlpath = str_replace("./", "", ereg_replace("^[.]/", "", ereg_replace("[^/]*/[.]{2}/", "", ereg_replace("^[.]/", "", ereg_replace("/+", "/", $urlpath)))));
	$query = "";
	if (isset($url_parts['query'])) {
		$query = "?".$url_parts['query'];
	}
	$url = $url_parts['scheme']."://".$url_parts['host'].$urlpath.$query;

	//if we index sub-domains
	if ($can_leave_domain == 1) {
		return $url;
	}

	$mainurl = remove_file_from_url($mainurl);
	
	if ($strip_sessids == 1) {
		$url = remove_sessid($url);
	}
	//only urls in staying in the starting domain/directory are followed	
	if (strstr($url, $mainurl) == false)
		return '';
	else
		return $url;
}

function save_keywords($wordarray, $link_id, $domain) {
	global $mysql_table_prefix;
	reset($wordarray);

	while ($thisword = each($wordarray)) {
		$word = $thisword[1][1];
		$wordmd5 = substr(md5($word), 0, 1);
		$weight = $thisword[1][2];
		if (strlen($word)<= 30) {
			$result = mysql_query("select keyword_ID from ".$mysql_table_prefix."keywords where keyword='$word'");
			echo mysql_error();
			$rows = mysql_num_rows($result);

			if ($rows == 0) {
				mysql_query("insert into ".$mysql_table_prefix."keywords (keyword) values ('$word')");
				$keyword_id = mysql_insert_id();
				echo mysql_error();
			} else {
				$row = mysql_fetch_row($result);
				$keyword_id = $row[0];
			}
			$inserts[$wordmd5] .= ",($link_id, $keyword_id, $weight, $domain)"; 
		}
	}
	for ($i=0;$i<=15; $i++) {
		$char = dechex($i);
		$values= substr($inserts[$char], 1);
		if ($values!="") {
			mysql_query("insert into ".$mysql_table_prefix."link_keyword$char (link_id, keyword_id, weight, domain) values $values");
			echo mysql_error();
		}
		
	
	}
}

function get_head_data($file) {
	$headdata = "";
	$first = strpos(strtolower($file), "<head");
	$next = strpos(strtolower($file), "</head>");
	if ($next > $first) {
		$headdata = substr($file, $first, $next -1);
	}
	$description = "";
	$robots = "";
	$keywords = "";
    $base = "";
	$res = Array ();
	if ($headdata != "") {
		preg_match("/<meta +name *= *[\"']?robots[\"']? *content=[\"']?([^<>'\"]+)[\"']?/i", $headdata, $res);
		if (isset ($res)) {
			$robots = $res[1];
		}

		preg_match("/<meta +name *= *[\"']?description[\"']? *content=[\"']?([^<>'\"]+)[\"']?/i", $headdata, $res);
		if (isset ($res)) {
			$description = $res[1];
		}

		preg_match("/<meta +name *= *[\"']?keywords[\"']? *content=[\"']?([^<>'\"]+)[\"']?/i", $headdata, $res);
		if (isset ($res)) {
			$keywords = $res[1];
		}

        // e.g. <base href="http://www.consil.co.uk/index.php" />
		preg_match("/<base +href *= *[\"']?([^<>'\"]+)[\"']?/i", $headdata, $res);
		if (isset ($res)) {
			$base = $res[1];
		}

		$keywords = preg_replace("/[, ]+/", " ", $keywords);
		$robots = explode(",", strtolower($robots));
		$nofollow = 0;
		$noindex = 0;
		foreach ($robots as $x) {
			if (trim($x) == "noindex") {
				$noindex = 1;
			}
			if (trim($x) == "nofollow") {
				$nofollow = 1;
			}
		}
		$data['description'] = addslashes($description);
		$data['keywords'] = addslashes($keywords);
		$data['nofollow'] = $nofollow;
		$data['noindex'] = $noindex;
		$data['base'] = $base;
	}
	return $data;
}

function clean_file($file, $url, $type) {

	global $entities, $index_host, $index_meta_keywords;
	$urlparts = parse_url($url);
	$host = $urlparts['host'];
	//remove filename from path
	$path = eregi_replace('([^/]+)$', "", $urlparts['path']);

	$file = eregi_replace("<link rel[^<>]*>", " ", $file);

	$first = strpos(strtolower($file), "<!--sphider_noindex-->");
	$count = 0;

	while (!($first === FALSE) && $count < 20) {
		$count ++;
		$next = strpos(strtolower($file), "<!--/sphider_noindex-->");
		$file = str_replace(substr($file, $first, $next - $first +23), " ", $file);
		$first = strpos(strtolower($file), "<!--sphider_noindex-->");
	}
	
	$first = strpos($file, "<!--");
	$count = 0;
	while(!($first === FALSE) && $count < 200) {
		$count ++;
		$next = strpos($file, "-->", $first);
		$file = str_replace(substr($file, $first, $next - $first +3), " ", $file);
		$first = strpos($file, "<!--");
	}


	$first = strpos(strtolower($file), "<script");
	$count = 0;
	while (!($first === FALSE) && $count < 20) {
		$count ++;
		$next = strpos(strtolower($file), "</script");
		$file = str_replace(substr($file, $first, $next - $first +9), " ", $file);
		$first = strpos(strtolower($file), "<script");
	}

	$headdata = get_head_data($file);

	$regs = Array ();
	if (eregi("<title *>([^<>]*)</title *>", $file, $regs)) {
		$title = trim($regs[1]);
		$file = str_replace($regs[0], "", $file);
	} else if ($type == 'pdf' || $type == 'doc') { //the title of a non-html file is its first few words
		$title = substr($file, 0, strrpos(substr($file, 0, 40), " "));
	}

	$file = eregi_replace("(<style[^>]*>[^<>]*</style>)", " ", $file);

	//create spaces between tags, so that remove tags doesnt concatenate strings
	$file = preg_replace("/<[\w ]+>/", "\\0 ", $file);

	$file = preg_replace("/<\/[\w ]+>/", "\\0 ", $file);

	$file = preg_replace("/\s+/", " ", $file);

	$file = strip_tags($file);

	$file = preg_replace("/&nbsp;/", " ", $file);

	$file = preg_replace("/ +/", " ", $file);

	$fulltext = $file;
	$file .= " ".$title;
	if ($index_host == 1) {
		$file = $file." ".$host." ".$path;
	}
	if ($index_meta_keywords == 1) {
		$file = $file." ".$headdata['keywords'];
	}
	reset($entities);
	while ($char = each($entities)) {
		$file = eregi_replace($char[0], $char[1], $file);
	}

	//replace codes with ascii chars
	$file = ereg_replace('&#([0-9]+);', chr('\1'), $file);
	$file = strtolower($file);

	$file = ereg_replace("&[a-z]{1,6};", " ", $file);

	$file = preg_replace("/[\*\^\+\?\\\.\[\]\^\$\|\{\)\(\}~!\"\/@#$%&=`;><:,]+/", " ", $file);
	$file = preg_replace("/ +/", " ", $file);


	$data['fulltext'] = addslashes($fulltext);
	$data['content'] = addslashes($file);
	$data['title'] = addslashes($title);
	$data['description'] = $headdata['description'];
	$data['keywords'] = $headdata['keywords'];
	$data['host'] = $host;
	$data['path'] = $path;
	$data['nofollow'] = $headdata['nofollow'];
	$data['noindex'] = $headdata['noindex'];
	$data['base'] = $headdata['base'];
	return $data;
}

function calc_weights($wordarray, $title, $host, $path, $keywords) {
	global $index_host, $index_meta_keywords;
	$hostarray = unique_array(explode(" ", eregi_replace("[^[:alnum:]-]+", " ", strtolower($host))));
	$patharray = unique_array(explode(" ", eregi_replace("[^[:alnum:]-]+", " ", strtolower($path))));
	$titlearray = unique_array(explode(" ", eregi_replace("[^[:alnum:]-]+", " ", strtolower($title))));
	$keywordsarray = unique_array(explode(" ", eregi_replace("[^[:alnum:]-]+", " ", strtolower($keywords))));
	$path_depth = countSubstrs($path, "/");

	while (list ($wid, $word) = each($wordarray)) {
		$word_in_path = 0;
		$word_in_domain = 0;
		$word_in_title = 0;
		$meta_keyword = 0;
		if ($index_host == 1) {
			while (list ($id, $path) = each($patharray)) {
				if ($path[1] == $word[1]) {
					$word_in_path = 1;
					break;
				}
			}
			reset($patharray);

			while (list ($id, $host) = each($hostarray)) {
				if ($host[1] == $word[1]) {
					$word_in_domain = 1;
					break;
				}
			}
			reset($hostarray);
		}

		if ($index_meta_keywords == 1) {
			while (list ($id, $keyword) = each($keywordsarray)) {
				if ($keyword[1] == $word[1]) {
					$meta_keyword = 1;
					break;
				}
			}
			reset($keywordsarray);
		}
		while (list ($id, $tit) = each($titlearray)) {
			if ($tit[1] == $word[1]) {
				$word_in_title = 1;
				break;
			}
		}
		reset($titlearray);

		$wordarray[$wid][2] = (int) (calc_weight($wordarray[$wid][2], $word_in_title, $word_in_domain, $word_in_path, $path_depth, $meta_keyword));
	}
	reset($wordarray);
	return $wordarray;
}

function isDuplicateMD5($md5sum) {
	global $mysql_table_prefix;
	$result = mysql_query("select link_id from ".$mysql_table_prefix."links where md5sum='$md5sum'");
	echo mysql_error();
	if (mysql_num_rows($result) > 0) {
		return true;
	}
	return false;
}

function check_include($link, $inc, $not_inc) {
	$url_inc = Array ();
	$url_not_inc = Array ();
	if ($inc != "") {
		$url_inc = explode("\n", $inc);
	}
	if ($not_inc != "") {
		$url_not_inc = explode("\n", $not_inc);
	}
	$oklinks = Array ();

	$include = true;
	foreach ($url_inc as $str) {
		$str = trim($str);
		if ($str != "") {
			if (substr($str, 0, 1) == '*') {
				if (!preg_match(substr($str, 1), $link)) {
					$include = false;
					break;
				}
			} else {
				if (strpos($link, $str) === false) {
					$include = false;
					break;
				}
			}
		}
	}
	if ($include) {
		foreach ($url_not_inc as $str) {
			$str = trim($str);
			if ($str != "") {
				if (substr($str, 0, 1) == '*') {
					if (preg_match(substr($str, 1), $link)) {
						$include = false;
						break;
					}
				} else {
					if (!(strpos($link, $str) === false)) {
						$include = false;
						break;
					}
				}
			}
		}
	}
	return $include;
}

function check_for_removal($url) {
	global $mysql_table_prefix;
	global $command_line;
	$result = mysql_query("select link_id, visible from ".$mysql_table_prefix."links"." where url='$url'");
	echo mysql_error();
	if (mysql_num_rows($result) > 0) {
		$row = mysql_fetch_row($result);
		$link_id = $row[0];
		$visible = $row[1];
		if ($visible > 0) {
			$visible --;
			mysql_query("update ".$mysql_table_prefix."links set visible=$visible where link_id=$link_id");
			echo mysql_error();
		} else {
			mysql_query("delete from ".$mysql_table_prefix."links where link_id=$link_id");
			echo mysql_error();
			for ($i=0;$i<=15; $i++) {
				$char = dechex($i);
				mysql_query("delete from ".$mysql_table_prefix."link_keyword$char where link_id=$link_id");
				echo mysql_error();
			}
			printStandardReport('pageRemoved',$command_line);
		}
	}
}

function convert_url($url) {
	$url = str_replace("&amp;", "&", $url);
	$url = str_replace(" ", "%20", $url);
	return $url;
}

function extract_text($contents, $source_type) {
	global $tmp_dir, $pdftotext_path, $catdoc_path, $xls2csv_path, $catppt_path;

	$temp_file = "tmp_file";
	$filename = $tmp_dir."/".$temp_file ;
	if (!$handle = fopen($filename, 'w')) {
		die ("Cannot open file $filename");
	}

	if (fwrite($handle, $contents) === FALSE) {
		die ("Cannot write to file $filename");
	}
	
	fclose($handle);
	if ($source_type == 'pdf') {
		$command = $pdftotext_path." $filename -";
		$a = exec($command,$result, $retval);
	} else if ($source_type == 'doc') {
		$command = $catdoc_path." $filename";
		$a = exec($command,$result, $retval);
	} else if ($source_type == 'xls') {
		$command = $xls2csv_path." $filename";
		$a = exec($command,$result, $retval);
	} else if ($source_type == 'ppt') {
		$command = $catppt_path." $filename";
		$a = exec($command,$result, $retval);
	}

	unlink ($filename);
	return implode(' ', $result); 

}

//function to calculate the weight of pages
function calc_weight ($words_in_page, $word_in_title, $word_in_domain, $word_in_path, $path_depth, $meta_keyword) {
	global $title_weight, $domain_weight, $path_weight,$meta_weight;
	$weight = ($words_in_page + $word_in_title * $title_weight +
			  $word_in_domain * $domain_weight +
			  $word_in_path * $path_weight + $meta_keyword * $meta_weight) *10 / (0.8 +0.2*$path_depth);

	return $weight;
}
 
function  remove_sessid($url) {
		return preg_replace("/(\?|&)(PHPSESSID|JSESSIONID|ASPSESSIONID|sid)=[0-9a-zA-Z]+$/", "", $url);
}
?>



<?php



function breadCrumb($PATH_INFO) {
	global $page_title, $root_url;

	// Remove these comments if you like, but only distribute 
	// commented versions.
	
	// Replace all instances of _ with a space
	$PATH_INFO = str_replace("_", " ", $PATH_INFO);
	// split up the path at each slash
	$pathArray = explode("/",$PATH_INFO);
	
	// Initialize variable and add link to home page
	if(!isset($root_url)) { $root_url=""; }
	$breadCrumbHTML = '<a href="'.$root_url.'/" title="Home Page">Home</a> &gt; ';
	
	// initialize newTrail
	$newTrail = $root_url."/";
	
	// starting for loop at 1 to remove root
	for($a=1;$a<count($pathArray)-1;$a++) {
		// capitalize the first letter of each word in the section name
		$crumbDisplayName = ucwords($pathArray[$a]);
		// rebuild the navigation path
		$newTrail .= $pathArray[$a].'/';
		// build the HTML for the breadcrumb trail
		$breadCrumbHTML .= '<a href="'.$newTrail.'">'.$crumbDisplayName.'</a> &gt; ';
	}
	// Add the current page
	if(!isset($page_title)) { $page_title = "Current Page"; }
	$breadCrumbHTML .= '<strong>'.$page_title.'</strong>';
	
	return $breadCrumbHTML;
}

?>
