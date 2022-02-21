<?php

function checkHTTP($curl, $expected_code = 200)
{
	$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	if ($httpcode != $expected_code) throw new Exception("http code $httpcode in " . curl_getinfo($curl, CURLINFO_EFFECTIVE_URL));
}

function xmlEscape($string) {
    return str_replace(
        array("&",     "<",    ">",    '"',      "'"),
        array("&amp;", "&lt;", "&gt;", "&quot;", "&apos;"), 
        $string
    );
}

function generate($login,$password,$xmltv) {
	mb_internal_encoding("UTF-8");
	date_default_timezone_set('Europe/Moscow');
	$ua = "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:52.0) Gecko/20100101 Firefox/52.0";
	$cookies = "../curl.cookies";
	$channels="xmltv.channels.xml";
	$programmes="xmltv.programmes.xml";
	$error = 0;
	$curl = curl_init();
	$fileXMLTV = false;
	$fileChannels = false;
	$fileProgrammes = false;
	try {
		curl_setopt_array($curl, array(
			CURLOPT_COOKIEFILE => $cookies,
			CURLOPT_COOKIEJAR => $cookies,
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
		$fileXMLTV = fopen($xmltv, "w"); if (!$fileXMLTV) throw new Exception("could not create $xmltv"); 
		$fileChannels = fopen($channels, "w"); if (!$fileChannels) throw new Exception("could not create $channels");
		$fileProgrammes = fopen($programmes, "w"); if (!$fileProgrammes) throw new Exception("could not create $programmes");
		curl_setopt($curl, CURLOPT_POST, false);
		curl_setopt($curl, CURLOPT_URL, "https://fe.smotreshka.tv/channels");
		$resp = curl_exec($curl);
		checkHTTP($curl);
		$jsonChannels = json_decode($resp);
		if (!isset($jsonChannels)) throw new Exception("bad channels json");
		
		
		fwrite($fileXMLTV, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n");
		fwrite($fileXMLTV, "<!DOCTYPE tv SYSTEM \"xmltv.dtd\">\r\n");
		fwrite($fileXMLTV, "<tv source-info-name=\"Смотрешка/\" source-info-url=\"https://smotreshka.tv/\" generator-info-name=\"smotreshka.xmltv/1.0 ".date("Y/m/d H:m:s")."\">\r\n");
		
		foreach($jsonChannels->{"channels"} as $channel) {
			if ($channel->{"info"}->{"purchaseInfo"}->{"bought"}) {
				curl_setopt($curl, CURLOPT_URL, "https://fe.smotreshka.tv/playback-info/" . $channel->{"id"});
				$resp = curl_exec($curl);
				checkHTTP($curl);
				$jsonPlayback = json_decode($resp);
				if (!isset($jsonPlayback)) throw new Exception("bad playback-info json");
				fwrite($fileChannels, "\t<channel id=\"".$channel->{"id"}."\">\r\n");
				fwrite($fileChannels, "\t\t<display-name lang=\"".strtok($jsonPlayback->{"languages"}[0]->{"id"},'-')."\">".xmlEscape(substr($channel->{"info"}->{"metaInfo"}->{"title"}, 4))."</display-name>\r\n");
				fwrite($fileChannels, "\t\t<display-name lang=\"".strtok($jsonPlayback->{"languages"}[0]->{"id"},'-')."\">".xmlEscape($channel->{"info"}->{"metaInfo"}->{"title"})."</display-name>\r\n");
				fwrite($fileChannels, "\t\t<icon src=\"".xmlEscape($channel->{"info"}->{"mediaInfo"}->{"thumbnails"}[0]->url)."\"/>\r\n");
				fwrite($fileChannels, "\t</channel>\r\n");	
				curl_setopt($curl, CURLOPT_URL, "https://fe.smotreshka.tv/channels/".$channel->{"id"}."/programs");
				$resp = curl_exec($curl);
				checkHTTP($curl);
				$jsonPrograms = json_decode($resp);
				if (!isset($jsonPrograms)) throw new Exception("bad programs json");
				foreach($jsonPrograms->{"programs"} as $programs) {		
					fwrite($fileProgrammes, "\t<programme start=\"".date('YmdHis O', $programs->{"scheduleInfo"}->{"start"})."\" stop=\"".date('YmdHis O', $programs->{"scheduleInfo"}->{"end"})."\" channel=\"".$channel->{"id"}."\">\r\n");
					fwrite($fileProgrammes, "\t\t<title lang=\"".strtok($jsonPlayback->{"languages"}[0]->{"id"},'-')."\">".xmlEscape($programs->{"metaInfo"}->{"title"})."</title>\r\n");
					fwrite($fileProgrammes, "\t\t<desc lang=\"".strtok($jsonPlayback->{"languages"}[0]->{"id"},'-')."\">".xmlEscape($programs->{"metaInfo"}->{"description"})."</desc>\r\n");
					fwrite($fileProgrammes, "\t\t<category lang=\"".strtok($jsonPlayback->{"languages"}[0]->{"id"},'-')."\">".$programs->{"metaInfo"}->{"age_rating"}."+</category>\r\n");
					fwrite($fileProgrammes, "\t\t<icon src=\"".xmlEscape($programs->{"mediaInfo"}->{"thumbnails"}[0]->url)."\"/>\r\n");
					fwrite($fileProgrammes, "\t\t<rating system=\"age\">\r\n");
					fwrite($fileProgrammes, "\t\t\t<value>".$programs->{"metaInfo"}->{"age_rating"}."+</value>\r\n");
					fwrite($fileProgrammes, "\t\t</rating>\r\n");
					fwrite($fileProgrammes, "\t</programme>\r\n");
				}
			}
		}
	}
	catch(Exception $e) {
		echo 'Exception: ', $e->getMessage() , "\n";
		$error = 1;
	}
	finally {
		curl_close($curl);
		if ($fileChannels) fclose($fileChannels);
		if ($fileProgrammes) fclose($fileProgrammes);
		fwrite($fileXMLTV, file_get_contents($channels));
		fwrite($fileXMLTV, file_get_contents($programmes));
		fwrite($fileXMLTV, "</tv>");
		if ($fileXMLTV) fclose($fileXMLTV);	
		$gz = gzopen ($xmltv.".gz", 'w9');
		gzwrite ($gz, file_get_contents($xmltv));
		gzclose($gz);
	}
	if ($error) die($error);
}

function download($xmltv) {
	header("Content-Type: application/octet-stream");
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"" . $xmltv.".gz" . "\"");
    readfile($xmltv.".gz");
	die();
}
 
function initSmotreshka($login,$password,$xmltv) {
	generate($login,$password,$xmltv);
	download($xmltv);
}

initSmotreshka("login","password","xmltv.xml");

?>