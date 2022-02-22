<?php

function checkHTTP($curl, $expected_code = 200)
{
	$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	if ($httpcode != $expected_code) throw new Exception("http code $httpcode in " . curl_getinfo($curl, CURLINFO_EFFECTIVE_URL));
}

function generate($login,$password,$m3u8) {
	mb_internal_encoding("UTF-8");
	$ua = "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:52.0) Gecko/20100101 Firefox/52.0";
	$cookfile = "curl.cookies";
	$err = 0;
	$curl = curl_init();
	$fplaylist = false;
	try {
		curl_setopt_array($curl, array(
			CURLOPT_COOKIEFILE => $cookfile,
			CURLOPT_COOKIEJAR => $cookfile,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT => $ua,
			CURLOPT_TIMEOUT_MS => 5000,
			CURLOPT_CONNECTTIMEOUT_MS => 5000,
			CURLOPT_SSL_VERIFYPEER => false
		));
		curl_setopt($curl, CURLOPT_POSTFIELDS, "email=$login&password=$password");
		curl_setopt($curl, CURLOPT_URL, "https://fe.smotreshka.tv/login");
		curl_exec($curl);
		checkHTTP($curl);
		$fplaylist = fopen($m3u8, "w");
		if (!$fplaylist) throw new Exception("could not create $m3u8");
		curl_setopt($curl, CURLOPT_POST, false);
		curl_setopt($curl, CURLOPT_URL, "https://fe.smotreshka.tv/channels");
		$resp = curl_exec($curl);
		checkHTTP($curl);
		$json = json_decode($resp);
		if (!isset($json)) throw new Exception("bad channels json");
		fwrite($fplaylist, "#EXTM3U\r\n");
		
		foreach($json->{"channels"} as $ch) {
			$info = $ch->{"info"};
			if ($info->{"purchaseInfo"}->{"bought"}) {
				curl_setopt($curl, CURLOPT_URL, "https://fe.smotreshka.tv/playback-info/" . $ch->{"id"});
				$resp = curl_exec($curl);
				checkHTTP($curl);
				$json2 = json_decode($resp);
				if (!isset($json2)) throw new Exception("bad playback-info json");
				$url = $json2->{"languages"}[0]->{"renditions"}[0]->{"url"};
				$genres = array( "Детские","Кино","Музыкальные","Новостные","Познавательные","Развлекательные","Спорт","Эфирные","HD-каналы","Региональные" );
				unset ($genre); 
				$group = "Разное";
				foreach(array_reverse ($info->{"metaInfo"}->{"genres"}) as $el) { 
					$genre = array_search(strtolower($el),array_map('strtolower',$genres));
					if ($genre !== false) { $group = $genres[$genre]; break; }
				}
				if ($genre == false) foreach($genres as $el) { 
					if (levenshtein(strtolower(end($info->{"metaInfo"}->{"genres"})),strtolower($el)) <= 3) { $group = $el; break; } 
				}
				$description = "#EXTINF:-1 tvg-id=\"".$ch->{"id"}."\" tvg-name=\"".$info->{"metaInfo"}->{"title"}."\" tvg-logo=\"".$info->{"mediaInfo"}->{"thumbnails"}[0]->url."\" group-title=\"".$group."\",".substr($info->{"metaInfo"}->{"title"}, 4)."";
				fwrite($fplaylist, "$description\r\n$url\r\n");
			}
		}
	}
	catch(Exception $e) {
		echo 'Exception: ', $e->getMessage() , "\n";
		$err = 1;
	}
	finally {
		curl_close($curl);
		if ($fplaylist) fclose($fplaylist);
	}
	if ($err) die($err);
}

function download($m3u8) {
	header("Content-Type: application/octet-stream");
	header("Content-Transfer-Encoding: Binary");
	header("Content-disposition: attachment; filename=\"" . $m3u8 . "\"");
	readfile($m3u8);
	die();
}
 
function initSmotreshka($login,$password,$m3u8) {
	generate($login,$password,$m3u8);
	download($m3u8);
}

initSmotreshka("login","password","index.m3u8");

?>