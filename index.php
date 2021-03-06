<?php
/*
    Projet Autoblog 0.3-beta
    Code: https://github.com/mitsukarenai/Projet-Autoblog
    Authors:
        Mitsu https://www.suumitsu.eu/
        Oros https://www.ecirtam.net/
        Arthur Hoaro http://hoa.ro
    License: Public Domain

    Instructions:
     (by default, autoblog creation is allowed: you can set this to "FALSE" in config.php)
     (by default, Cross-Site Autoblog Farming [XSAF] imports a few autoblogs from https://github.com/mitsukarenai/xsaf-bootstrap/blob/master/3.json you can uncomment and add xsafimports in xsaf3.php (jump at end of file) )
     (by default, database and media transfer via XSAF is allowed)

    - upload all files on your server (PHP 5.3+ required)
    - PROFIT!

*/

define('XSAF_VERSION', 3);
define('ROOT_DIR', __DIR__);

$error = array();
$success = array();

if(file_exists("config.php")){
    require_once "config.php";
}
if(file_exists("functions.php")){
    require_once "functions.php";
}else{
    echo "functions.php not found!";
    die;
}

$youtorr_db_error = FALSE;
if(ENABLE_YOUTORR == TRUE){
  try { #Connect to the db
      switch($youtorr_db_engine){
          case "sqlite" :
              $youtorr_db = new PDO('sqlite:'.$youtorr_db_name);	
              break;
          case "mysql" :
              $youtorr_db = new PDO('mysql:host='.$youtorr_db_host.';port='.$youtorr_db_port.';dbname='.$youtorr_db_name,
              $youtorr_db_user,$youtorr_db_password);
              break;
      }
  } catch (PDOException $e ) {
  	$youtorr_db_error = TRUE;
  }
}
function get_title_from_feed($url) {
    return get_title_from_datafeed(file_get_contents($url));
}

function get_title_from_datafeed($data) {
    if($data === false) { return 'url inaccessible';  }
    $dom = new DOMDocument;
    $dom->loadXML($data) or die('xml malformé');
    $title = $dom->getElementsByTagName('title');
    return $title->item(0)->nodeValue;
}

function get_link_from_feed($url) {
    return get_link_from_datafeed(file_get_contents($url));
}

function get_link_from_datafeed($data) {
    if($data === false) { return 'url inaccessible';  }
    $xml = simplexml_load_string($data); // quick feed check

    // ATOM feed && RSS 1.0 /RDF && RSS 2.0
    if (!isset($xml->entry) && !isset($xml->item) && !isset($xml->channel->item))
        { die('le flux n\'a pas une syntaxe valide');}

    $check = substr($data, 0, 5);
    if($check !== '<?xml') {
        die('n\'est pas un flux valide');
    }

    $xml = new SimpleXmlElement($data);
    $channel['link'] = $xml->channel->link;
    if($channel['link'] === NULL) {
        $dom = new DOMDocument;
        $dom->loadXML($data) or die('xml malformé');
        $link = $dom->getElementsByTagName('uri');
        return $link->item(0)->nodeValue;
    }
    else {
        return escape($channel['link']);
    }
}

function get_size($doc) {
    $symbol = array('o', 'Kio', 'Mio', 'Gio', 'Tio');
    $size = filesize($doc);
    $exp = floor(log($size) / log(1024));
    $nicesize = $size / pow(1024, floor($exp));
    return sprintf('%d %s', $nicesize, $symbol[$exp]);
}

function serverUrl($return_subfolder = false)
{
    $https = (!empty($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS'])=='on')) || $_SERVER["SERVER_PORT"]=='443'; // HTTPS detection.
    $serverport = ($_SERVER["SERVER_PORT"]=='80' || ($https && $_SERVER["SERVER_PORT"]=='443') ? '' : ':'.$_SERVER["SERVER_PORT"]);
    if($return_subfolder === true) {
        $path = pathinfo( $_SERVER['PHP_SELF'] );
        $finalslash = ( $path['dirname'] != '/' ) ? '/' : '';
        $subfolder = $path['dirname'] . $finalslash;
    } else $subfolder = '';
    return 'http'.($https?'s':'').'://'.$_SERVER["SERVER_NAME"].$serverport.$subfolder;
}

function objectCmp($a, $b) {
    return strcasecmp ($a->site_title, $b->site_title);
}

function generate_antibot() {
    $letters = array('zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf', 'vingt');
    return $letters[mt_rand(1, 20)];
}

function check_antibot($number, $text_number) {
    $letters = array('zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf', 'vingt');
    return ( array_search( $text_number, $letters ) === intval($number) ) ? true : false;
}

function create_from_opml($opml) {
    global $error, $success;
    $cpt = 0;
    foreach( $opml->body->outline as $outline ) {
        if ( !empty( $outline['title'] ) && !empty( $outline['text'] ) && !empty( $outline['xmlUrl']) && !empty( $outline['htmlUrl'] )) {
            try {
                $sitename = escape( $outline['title'] );
                $siteurl = escape($outline['htmlUrl']);
                
                // Lighten process by checking folderExists first
                // A CHANGER SELON ISSUE #20
                if(folderExists($siteurl))
                    throw new Exception('Erreur : l\'autoblog '. $sitename .' existe déjà.');
                    
                $sitetype = escape($outline['text']); 
                if ( $sitetype != 'microblog' && $sitetype != 'shaarli' && $sitetype != 'twitter' && $sitetype != 'youtube') 
                    $sitetype = 'generic'; 
                    
                $rssurl = DetectRedirect(escape($outline['xmlUrl']));

                createAutoblog( $sitetype, $sitename, $siteurl, $rssurl );
                
                $message = 'Autoblog "'. $sitename .'" crée avec succès. &rarr; <a target="_blank" href="'. urlToFolder( $siteurl, $rssurl ) .'">afficher l\'autoblog</a>.';
                // Do not print iframe on big import (=> heavy and useless)
                if( ++$cpt < 10 )
                    $message .= '<iframe width="1" height="1" frameborder="0" src="'. urlToFolder( $siteurl, $rssurl ) .'/index.php"></iframe>';
                $success[] = $message;
            }
            catch (Exception $e) {
                $error[] = $e->getMessage();
            }
        }
    }
}

/**
 * Simple version check
 **/
function versionCheck() {
    $versionfile = 'version';
    $lastestUrl = 'https://raw.github.com/mitsukarenai/Projet-Autoblog/master/version';

    $expire = time() - 84600 ; // 23h30 en secondes
    $lockfile = '.versionlock';

    if (file_exists($lockfile) && filemtime($lockfile) > $expire) {
        if( file_get_contents($lockfile) == 'NEW' ) {
            // No new version installed
            if( filemtime( $lockfile ) > filemtime( $versionfile ) )
                return true;
            else unlink($lockfile);
        }
        else return false;
    }

    if (file_exists($lockfile) && filemtime($lockfile) < $expire) { unlink($lockfile); }

    if( file_get_contents($versionfile) != file_get_contents($lastestUrl) ) {
        file_put_contents($lockfile, 'NEW');
        return true;
    }
    file_put_contents($lockfile, '.');
    return false;
 }
 $update_available = (ALLOW_CHECK_UPDATE) ? versionCheck() : false;

/**
*   RSS Feed
*   
**/
if( !file_exists(RESOURCES_FOLDER.'rss.json')) {
	file_put_contents(RESOURCES_FOLDER.'rss.json', '', LOCK_EX);
}

if (isset($_GET['rss'])) {
    displayXML();
    die;
}

/**
 * SVG
 **/
if (isset($_GET['check']))
{
    header('Content-type: image/svg+xml');
    $randomtime=rand(86400, 259200); /* intervalle de mise à jour: de 1 à 3 jours  (pour éviter que le statut de tous les autoblogs soit rafraichi en bloc et bouffe le CPU) */
    $expire=time() -$randomtime ;

    /* SVG minimalistes */

function svg_status($fill, $text, $back)
	{
	$svg = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><svg xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:cc="http://creativecommons.org/ns#" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:svg="http://www.w3.org/2000/svg" xmlns="http://www.w3.org/2000/svg" version="1.1" width="15" height="15">'.$back.'<text style="font-size:10px;font-weight:bold;text-anchor:middle;font-family:Arial;fill:'.$fill.';"><tspan x="8" y="14">'.$text.'</tspan></text></svg>';
	return $svg;
	}

	$svg_ok=svg_status('#008000', 'ok', '');
	$svg_mv=svg_status('#0000ff', 'mv', '<rect width="100%" height="100%" fill="#ffd800"/>');
	$svg_err=svg_status('#000000', 'err', '<rect width="100%" height="100%" fill="#ff0000"/>');

    $errorlog="./".escape( $_GET['check'] ) ."/error.log";

    $oldvalue = null;
    if(file_exists($errorlog)) { $oldvalue = file_get_contents($errorlog); };
    if(file_exists($errorlog) && filemtime($errorlog) < $expire) { unlink($errorlog); } /* errorlog périmé ? Suppression. */
    if(file_exists($errorlog)) /* errorlog existe encore ? se contenter de lire sa taille pour avoir le statut */
    {
        if(filesize($errorlog) == "0") {die($svg_ok);}
        else if(filesize($errorlog) == "1") {die($svg_mv);}
        else {die($svg_err);}
    }
    else /* ..sinon, lancer la procédure de contrôle */
    {
        $ini = parse_ini_file("./". escape( $_GET['check'] ) ."/vvb.ini") or die;
        $headers = get_headers($ini['FEED_URL']);
        
        if(!empty($headers)) 
            $code=explode(" ", $headers[0]);
        
        /* le flux est indisponible (typiquement: erreur DNS ou possible censure) - à vérifier */
        if(empty($headers) || $headers === FALSE || (!empty($code) && ($code[1] == '500' || $code[1] == '404'))) {
            if( $oldvalue !== null && $oldvalue != '..' ) {
				updateXML('unavailable', 'nxdomain', escape($_GET['check']), $ini['SITE_TITLE'], $ini['SITE_URL'], $ini['FEED_URL']);
            }
            file_put_contents($errorlog, '..');
            die($svg_err);
        }
        /* code retour 200: flux disponible */
        if($code[1] == "200") {
            if( $oldvalue !== null && $oldvalue != '' ) {
				updateXML('available', '200', escape($_GET['check']), $ini['SITE_TITLE'], $ini['SITE_URL'], $ini['FEED_URL']);
            }
            file_put_contents($errorlog, '');
            die($svg_ok);
        }
        /* autre code retour: un truc a changé (redirection, changement de CMS, .. bref vvb.ini doit être corrigé) */
        else {
            if( $oldvalue !== null && $oldvalue != '.' ) {
				updateXML('moved', '3xx', escape($_GET['check']), $ini['SITE_TITLE'], $ini['SITE_URL'], $ini['FEED_URL']);
            }
            file_put_contents($errorlog, '.');
            die($svg_mv);
        }
    }
}

/**
 * JSON Export
 **/
if (isset($_GET['export'])) {
    header('Content-Type: application/json');
    $subdirs = glob(AUTOBLOGS_FOLDER . "*");

    foreach($subdirs as $unit) {
        if(is_dir($unit)) {
            $unit=substr($unit, 2);
            $ini = parse_ini_file($unit.'/vvb.ini');
            $config = new stdClass;

            foreach ($ini as $key=>$value) {
                $key = strtolower($key);
                $config->$key = $value;
            }
            unset($ini);

            $feed=$config->feed_url;
            $type=$config->site_type;
            $title=$config->site_title;
            $url=$config->site_url;
            $reponse[$unit] = array("SITE_TYPE"=>"$type", "SITE_TITLE"=>"$title", "SITE_URL"=>"$url", "FEED_URL"=>"$feed");

        }
    }
    echo json_encode( array( "meta"=> array("xsaf-version"=>XSAF_VERSION,"xsaf-db_transfer"=>"true","xsaf-media_transfer"=>"true"),
                                "autoblogs"=>$reponse));
    die;
}

/**
 * JSON Allowed Twitter accounts export
 **/
if (isset($_GET['export_twitter'])) {
    header('Content-Type: application/json');
    $subdirs = glob(AUTOBLOGS_FOLDER . "*");
    $response = array();

    foreach($subdirs as $unit) {
        if(is_dir($unit)) {
            $unit=substr($unit, 2);
            $ini = parse_ini_file($unit.'/vvb.ini');
            if( $ini['SITE_TYPE'] == 'twitter' ) {
                preg_match('#twitter\.com/(.+)#', $ini['SITE_URL'], $username);
                $response[] = $username[1];
            }
            unset($ini);
        }
    }
    echo json_encode( $response );
    die;
}

/**
 *  OPML Full Export
 **/
if (isset($_GET['exportopml'])) // OPML
{
    //header('Content-Type: application/octet-stream');
    header('Content-type: text/xml');
    header('Content-Disposition: attachment; filename="autoblogs-'. $_SERVER['SERVER_NAME'] .'.xml"');

    $opmlfile = new SimpleXMLElement('<opml></opml>');
    $opmlfile->addAttribute('version', '1.0');
    $opmlhead = $opmlfile->addChild('head');
    $opmlhead->addChild('title', 'Autoblog OPML export from '. $_SERVER['SERVER_NAME'] );
    $opmlhead->addChild('dateCreated', date('r', time()));
    $opmlbody = $opmlfile->addChild('body');

    $subdirs = glob(AUTOBLOGS_FOLDER . "*");

    foreach($subdirs as $unit) {
        if(is_dir($unit)) {
            $unit=substr($unit, 2);
            $ini = parse_ini_file($unit.'/vvb.ini');
            $config = new stdClass;

            foreach ($ini as $key=>$value) {
                $key = strtolower($key);
                $config->$key = $value;
            }
            unset($ini);

            $outline = $opmlbody->addChild('outline');
            $outline->addAttribute('title', escape($config->site_title));
            $outline->addAttribute('text', escape($config->site_type));
            $outline->addAttribute('htmlUrl', escape($config->site_url));
            $outline->addAttribute('xmlUrl', escape($config->feed_url));
        }
    }
    echo $opmlfile->asXML();
    exit;
}

/**
 * Site map
 **/
if (isset($_GET['sitemap']))
{
    header('Content-Type: application/xml');
	$proto=(!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])=='on')?"https://":"http://";
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
			echo "<url>\n <loc>".$proto."{$_SERVER['HTTP_HOST']}".str_replace('?sitemap', '', $_SERVER['REQUEST_URI'])."</loc>\n";
            echo '  <lastmod>'.date('c', time())."</lastmod>\n";
            echo "  <changefreq>daily</changefreq>\n</url>\n";
    $subdirs = glob(AUTOBLOGS_FOLDER . "*");
    foreach($subdirs as $unit) {
        if(is_dir($unit)) {
            $unit=substr($unit, 2);
            echo "<url>\n <loc>".$proto.$_SERVER['SERVER_NAME'].substr($_SERVER['PHP_SELF'], 0, -9)."$unit/"."</loc>\n";
            echo ' <lastmod>'.date('c', filemtime($unit))."</lastmod>\n";
            echo " <changefreq>hourly</changefreq>\n</url>\n\n";
        }
    }
    echo '</urlset>';
    die;
}

/**
 * Update ALL autblogs (except .disabled)
 * This action can be very slow and consume CPU if you have a lot of autoblogs
 **/
if( isset($_GET['updateall']) && ALLOW_FULL_UPDATE) {
    $max_exec_time=time()+4; // scipt have 4 seconds to update autoblogs
    $expire = time() - 84600 ; // 23h30 en secondes
    $lockfile = ".updatealllock";
    $lockfile_contents = array();
    if (file_exists($lockfile)){
        $lockfile_contents = file_get_contents($lockfile);
        if( !isset($lockfile_contents[0]) || $lockfile_contents[0] != "a") { // détection d'une serialisation
            if( filemtime($lockfile) > $expire){
                echo "too early";
                die;
            }else{
                // need update of all autoblogs
                unlink($lockfile);
            }
        }
        // else we need to update some autoblogs
    } 
    if( file_put_contents($lockfile, date(DATE_RFC822)) ===FALSE) {
        echo "Merci d'ajouter des droits d'écriture sur le fichier.";
        die;
    }

    if(!empty($lockfile_contents)) {
        $subdirs = unserialize($lockfile_contents);
        unset($lockfile_contents);
    }else{
        $subdirs = glob(AUTOBLOGS_FOLDER . "*");
    }    
    $todo_subdirs = $subdirs;
    foreach($subdirs as $key => $unit) {
        if(is_dir($unit)) {
            if( !file_exists(ROOT_DIR . '/' . $unit . '/.disabled')) {
                file_get_contents(serverUrl() . substr($_SERVER['PHP_SELF'], 0, -9) . $unit . '/index.php');
                unset($todo_subdirs[$key]);
            }
        }
        if(time() >= $max_exec_time){
            break;
        }
    }
    if(!empty($todo_subdirs)){
        // if update is not finish
        // save list of autoblogs who need update
        file_put_contents($lockfile, serialize($todo_subdirs), LOCK_EX);
        echo "Not finish";
    }else{
        echo "Done";
    }
    exit;
}

$antibot = generate_antibot();
$form = '<form method="POST"><input type="hidden" name="generic" value="1" />'."\n".'
           <input placeholder="Adresse du flux RSS/ATOM" type="text" name="rssurl" id="rssurl"><br>
           <input placeholder="Antibot : écrivez « '. $antibot .' » en chiffre" type="text" name="number"><br>
           <input type="hidden" name="antibot" value="'. $antibot .'" />
           <input type="submit" value="Vérifier">
        </form>';

/**
 * ADD BY BOOKMARK BUTTON
 **/
if(!empty($_GET['via_button']) && $_GET['number'] === '17' && ALLOW_NEW_AUTOBLOGS && ALLOW_NEW_AUTOBLOGS_BY_BUTTON )
{
    $form = '<html><head></head><body>';

    if( empty($_GET['rssurl']) ) {
        $form .= '<p>URL du flux RSS incorrect.<br><a href="#" onclick="window.close()">Fermer la fenêtre.</a></p>';
    }
    else {
        if(isset($_GET['add']) && $_GET['add'] === '1' && !empty($_GET['siteurl']) && !empty($_GET['sitename'])) {
            try {
                $rssurl = DetectRedirect(escape($_GET['rssurl']));

                $siteurl = escape($_GET['siteurl']);
                $sitename = escape($_GET['sitename']);
                $sitetype = updateType($siteurl); // Disabled input doesn't send POST data
                $sitetype = $sitetype['type'];

                createAutoblog( $sitetype, $sitename, $siteurl, $rssurl );

                if( empty($error)) {
                    $form .= '<iframe width="1" height="1" frameborder="0" src="'. urlToFolder( $siteurl, $rssurl ) .'/index.php"></iframe>';
                    $form .= '<p><span style="color:darkgreen">Autoblog <a href="'. urlToFolder( $siteurl, $rssurl ) .'">'. $sitename .'</a> ajouté avec succès.</span><br>';
                }
                else {
                    $form .= '<ul>';
                    foreach ( $error AS $value )
                        $form .= '<li>'. $value .'</li>';
                    $form .= '</ul>';
                }
            }
            catch (Exception $e) {
                $form .= $e->getMessage();
            }
            $form .= '<a href="#" onclick="window.close()">Fermer la fenêtre.</a></p>';
        }
        else {
            try {
                $rssurl = DetectRedirect(escape($_GET['rssurl']));
                $datafeed = file_get_contents($rssurl);
                if( $datafeed !== false ) {
                    $siteurl = get_link_from_datafeed($datafeed);
                    $sitename = get_title_from_datafeed($datafeed);
                    $sitetype = updateType($siteurl);
                    $sitetype = $sitetype['type'];

                    $form .= '<span style="color:blue">Merci de vérifier les informations suivantes, corrigez si nécessaire.</span><br>
                    <form method="GET">
                    <input type="hidden" name="via_button" value="1"><input type="hidden" name="add" value="1"><input type="hidden" name="number" value="17">
                    <input style="width:30em;" type="text" name="sitename" id="sitename" value="'.$sitename.'"><label for="sitename">&larr; titre du site (auto)</label><br>
                    <input style="width:30em;" placeholder="Adresse du site" type="text" name="siteurl" id="siteurl" value="'.$siteurl.'"><label for="siteurl">&larr; page d\'accueil (auto)</label><br>
                    <input style="width:30em;" placeholder="Adresse du flux RSS/ATOM" type="text" name="rssurl" id="rssurl" value="'.$rssurl.'"><label for="rssurl">&larr; adresse du flux</label><br>
                    <input style="width:30em;" type="text" name="sitetype" id="sitetype" value="'.$sitetype.'" disabled><label for="sitetype">&larr; type de site</label><br>
                    <input type="submit" value="Créer"></form>';
                }
                else {
                    $form .= '<p>URL du flux RSS incorrecte.<br><a href="#" onclick="window.close()">Fermer la fenêtre.</a></p>';
                }
            }
            catch (Exception $e) {
                $form .= $e->getMessage() .'<br><a href="#" onclick="window.close()">Fermer la fenêtre.</a></p>';
            }
        }
    }
    $form .= '</body></html>';
    echo $form; die;
}

/**
 * ADD BY SOCIAL / SHAARLI
 **/
if( !empty($_POST['socialinstance']) && ALLOW_NEW_AUTOBLOGS && ALLOW_NEW_AUTOBLOGS_BY_SOCIAL)
{
    $socialinstance = strtolower(escape($_POST['socialinstance']));
    $socialaccount = (!empty($_POST['socialaccount'])) ? strtolower(escape($_POST['socialaccount'])) : false;
    if( $socialaccount === false && $socialinstance !== 'shaarli')
        $error[] = 'Le compte social doit être renseigné.';
    elseif( !empty($_POST['number']) && !empty($_POST['antibot']) && check_antibot($_POST['number'], $_POST['antibot'])) {

        if($socialinstance === 'twitter') {
            if( API_TWITTER !== FALSE ) {               
                $sitetype = 'twitter';
                $siteurl = 'http://twitter.com/'. $socialaccount;
		if ( API_TWITTER === 'LOCAL' ) {
                	$rssurl = serverUrl(true).'twitter2feed.php?u='.$socialaccount;
		}
		else {
                	$rssurl = API_TWITTER.$socialaccount;
				// check
				$twitterbridge = get_headers($rssurl, 1);
				if ($twitterbridge['0'] == 'HTTP/1.1 403 Forbidden') { $error[] = "La twitterbridge a refusé ce nom d'utilisateur: <br>\n<pre>".htmlentities($twitterbridge['X-twitterbridge']).'</pre>'; }
		}
            }
            else
                $error[] = 'Vous devez définir une API Twitter -> RSS dans votre fichier de configuration (see <a href="https://github.com/mitsukarenai/twitterbridge">TwitterBridge</a>).';
        }
        elseif($socialinstance === 'statusnet' && !empty($_POST['statusneturl'])) {
            $sitetype = 'microblog';
            $siteurl= NoProtocolSiteURL(escape($_POST['statusneturl']));
            try {
                $rssurl = DetectRedirect("http://".$siteurl."/api/statuses/user_timeline/$socialaccount.rss");
                $siteurl = DetectRedirect("http://".$siteurl."/$socialaccount");
            }
            catch (Exception $e) {
                echo $error[] = $e->getMessage();
            }
        }
        elseif($socialinstance === 'shaarli' && !empty($_POST['shaarliurl'])) {
            $sitetype = 'shaarli';
            $siteurl = NoProtocolSiteURL(escape($_POST['shaarliurl']));
            try {
                $siteurl = DetectRedirect("http://".$siteurl."/");
            }
            catch (Exception $e) {
                echo $error[] = $e->getMessage();
            }
            $rssurl = $siteurl."?do=rss";
            $socialaccount = get_title_from_feed($rssurl);
        }
        elseif($socialinstance === 'youtube') {
            $sitetype = 'youtube';
            $siteurl = 'https://www.youtube.com/user/'.$socialaccount;
            $rssurl = 'https://gdata.youtube.com/feeds/base/users/'.$socialaccount.'/uploads?alt=atom&orderby=published';
        }
        if( empty($error) ) {
            try {
                    $headers = get_headers($rssurl, 1);
                    if (strpos($headers[0], '200') === FALSE) 
                        throw new Exception('Flux inaccessible (compte inexistant ?)');
                
                createAutoblog($sitetype, ucfirst($socialinstance) .' - '. $socialaccount, $siteurl, $rssurl);
                $success[] = '<iframe width="1" height="1" frameborder="0" src="'. urlToFolder( $siteurl, $rssurl ) .'/index.php"></iframe>
                <b style="color:darkgreen">'.ucfirst($socialinstance) .' - '. $socialaccount.' <a href="'. urlToFolder( $siteurl, $rssurl ) .'">ajouté avec succès</a>.</b>';
            }
            catch (Exception $e) {
                echo $error[] = $e->getMessage();
            }
        }
    }
    else
        $error[] = 'Antibot : chiffres incorrects.';
}

/**
 * ADD BY GENERIC LINK
 **/
if( !empty($_POST['generic']) && ALLOW_NEW_AUTOBLOGS && ALLOW_NEW_AUTOBLOGS_BY_LINKS) {
    if(empty($_POST['rssurl']))
        {$error[] = "Veuillez entrer l'adresse du flux.";}
    if(empty($_POST['number']) || empty($_POST['antibot']) )
        {$error[] = "Vous êtes un bot ?";}
    elseif(! check_antibot($_POST['number'], $_POST['antibot']))
        {$error[] = "Antibot : ce n'est pas le bon nombre.";}

    if(empty($error)) {
        try {
	    $rssurl = parse_url($_POST['rssurl']);
	    $rssurl = $rssurl['scheme'].'://'.$rssurl['host'].$rssurl['path'].'?'.html_entity_decode($rssurl['query']);
            $rssurl = DetectRedirect($rssurl);

            if(!empty($_POST['siteurl'])) {

                $siteurl = escape($_POST['siteurl']);
                $sitename = get_title_from_feed($rssurl);

                createAutoblog('generic', $sitename, $siteurl, $rssurl);

                $success[] = '<iframe width="1" height="1" frameborder="0" src="'. urlToFolder( $siteurl, $rssurl ) .'/index.php"></iframe>
                <b style="color:darkgreen">Autoblog '. $sitename .' crée avec succès.</b> &rarr; <a target="_blank" href="'. urlToFolder( $siteurl, $rssurl ) .'">afficher l\'autoblog</a>';
            }
            else {
                // checking procedure
                $datafeed = file_get_contents($rssurl);
                if( $datafeed === false ) {
                    $error[] = 'URL "'. $rssurl .'" inaccessible.';
                }
                $sitetype = 'generic';
                $siteurl = get_link_from_datafeed($datafeed);
                $sitename = get_title_from_datafeed($datafeed);

                $form = '<span style="color:blue">Merci de vérifier les informations suivantes, corrigez si nécessaire. Tous les champs doivent être renseignés.</span><br>
                <form method="POST"><input type="hidden" name="generic" value="1" />
                <input style="color:black" type="text" id="sitename" value="'.$sitename.'" '.( $datafeed === false?'':'disabled').'><label for="sitename">&larr; titre du site (auto)</label><br>
                <input placeholder="Adresse du site" type="text" name="siteurl" id="siteurl" value="'.$siteurl.'"><label for="siteurl">&larr; page d\'accueil (auto)</label><br>
                <input placeholder="Adresse du flux RSS/ATOM" type="text" name="rssurl" id="rssurl" value="'.$rssurl.'"><label for="rssurl">&larr; adresse du flux</label><br>
                <input placeholder=""Type de site" type="text" name="sitetype" id="sitetype" value="'.$sitetype.'" '.( $datafeed === false?'':'disabled').'><label for="sitetype">&larr; type de site</label><br>
                <input placeholder="Antibot: '. escape($_POST['antibot']) .' en chiffre" type="text" name="number"  value="'. escape($_POST['number']) .'"><label for="number">&larr; antibot</label><br>
                <input type="hidden" name="antibot" value="'. escape($_POST['antibot']) .'" /><input type="submit" value="Créer"></form>';

            }
        }
        catch (Exception $e) {
            echo $error[] = $e->getMessage();
        }
    }
}

/**
 * ADD BY OPML File
 **/
if( !empty($_POST['opml_file']) && ALLOW_NEW_AUTOBLOGS && ALLOW_NEW_AUTOBLOGS_BY_OPML_FILE) {
    if(empty($_POST['number']) || empty($_POST['antibot']) )
        {$error[] = "Vous êtes un bot ?";}
    elseif(! check_antibot($_POST['number'], $_POST['antibot']))
        {$error[] = "Antibot : ce n'est pas le bon nombre.";}

    if( empty( $error)) {
        if (is_uploaded_file($_FILES['file']['tmp_name'])) {
            $opml = null;
            if( ($opml = simplexml_load_file( $_FILES['file']['tmp_name'])) !== false ) {
                create_from_opml($opml);
            }
            else
                $error[] = "Impossible de lire le contenu du fichier OPML.";
            unlink($_FILES['file']['tmp_name']);
        } else {
            $error[] = "Le fichier n'a pas été envoyé.";
        }
    }
}

/**
 * ADD BY OPML Link
 **/
 if( !empty($_POST['opml_link']) && ALLOW_NEW_AUTOBLOGS && ALLOW_NEW_AUTOBLOGS_BY_OPML_LINK) {
    if(empty($_POST['number']) || empty($_POST['antibot']) )
        {$error[] = "Vous êtes un bot ?";}
    elseif(! check_antibot($_POST['number'], $_POST['antibot']))
        {$error[] = "Antibot : ce n'est pas le bon nombre.";}
    if( empty( $_POST['opml_url'] ))
        {$error[] = 'Le lien est incorrect.';}

    if( empty( $error)) {
        $opml_url = escape($_POST['opml_url']);
        if(parse_url($opml_url, PHP_URL_HOST)==FALSE) {
            $error[] = "URL du fichier OPML non valide.";
        } else {
            if ( ($opml = simplexml_load_file( $opml_url )) !== false ) {
                create_from_opml($opml);
            } else {
                $error[] = "Impossible de lire le contenu du fichier OPML ou d'accéder à l'URL donnée.";
            }
        }

    }
}

?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
  <head>
    <title>Projet Autoblog<?php if(strlen(HEAD_TITLE)>0) echo " | " . HEAD_TITLE; ?></title>
    <meta charset="utf-8">
    <meta content="initial-scale=1.0, user-scalable=yes" name="viewport" />
    
    <meta name="keywords"    content="autoblog,effet streisand,censure,replication" />
    <meta name="description" content="Le Projet Autoblog a pour objectif de répliquer les articles d'un blog ou d'un site site web." />
    <link rel="alternate" type="application/rss+xml" title="RSS" href="<?php echo serverUrl(true) . '?rss';?>" />
    <link href="<?php echo RESOURCES_FOLDER; ?>autoblog.css" rel="stylesheet" type="text/css">
    <?php
      if(file_exists(RESOURCES_FOLDER .'user.css')){
        echo '<link href="'. RESOURCES_FOLDER .'user.css" rel="stylesheet" type="text/css">';
      }
    ?>
  </head>
  <body>
    <header>
      <h1><a href="<?php echo serverUrl(true); ?>">Projet Autoblog<?php if(strlen(HEAD_TITLE)>0) echo " | " . HEAD_TITLE; ?></a></h1>
    </header>
    
    <section id="présentation">
      <header>
        <?php
          if (defined('LOGO'))
            echo '<img id="logo" src="'. RESOURCES_FOLDER . LOGO .'" alt="logo of this autoblog instance" />'."\n";
        ?>
        <h2>Présentation</h2>
      </header>
      
      <p>Le Projet Autoblog a pour objectif de répliquer les articles d'un blog ou d'un site site web. Si l'article source est supprimé, et même si le site d'origine disparaît, les articles restent lisibles sur l'autoblog. L'objectif premier de ce projet est de lutter contre la censure et toute sorte de pression…</p>
      
      <p>Voici une liste d'autoblogs hébergés sur <em><?php echo $_SERVER['SERVER_NAME']; ?></em> (<a href="http://sebsauvage.net/streisand.me/fr/">plus d'infos sur le projet</a>).</p>
            
      <p><strong>Autres fermes</strong> &rarr; <a href="//startpage.com/do/search?query=%22Voici+une+liste+d%27autoblogs+h%C3%A9berg%C3%A9s%22">Rechercher</a></p>
    </section>
    <?php if( $update_available ) { ?>
<section id="màj">
      <header>
        <h2>Mise à jour</h2>
      </header>
      
      <p>Une mise à jour du Projet Autoblog est disponible !</p>
      <ul>
        <li>&rarr; <a href="https://github.com/mitsukarenai/Projet-Autoblog/archive/master.zip">télécharger la dernière version</a> ;</li>
        <li>&rarr; <strong>important :</strong> <a href="https://github.com/mitsukarenai/Projet-Autoblog/wiki/Mettre-%C3%A0-jour">consulter la documentation — mise à jour</a>.</li>
      </ul>
    </section>
    <?php } ?>
<?php if(ALLOW_NEW_AUTOBLOGS == TRUE) { ?>
<section id="ajouter">
      <header>
        <h2>Ajouter un autoblog</h2>
      </header>

      <?php
        if( !empty( $error ) || !empty( $success )) {
          echo '<p>Message'. (count($error) ? 's' : '') ." :</p>\n";
          echo "      <ul>\n";
          foreach ( $error AS $value ) {
            echo '        <li class="error">'. $value ."</li>\n";
          }
          foreach ( $success AS $value ) {
            echo '        <li class="success">'. $value ."</li>\n";
          }
          echo "      </ul>\n";
          echo "      \n";
          echo '      ';
        }
        
        $button_list = '<p id="button_list">Ajouter un autoblog via :'."\n";
        if(ALLOW_NEW_AUTOBLOGS_BY_LINKS)
          $button_list .= '        <a href="#add_generic" class="button" id="button_generic" onclick="show_form(\'generic\');return false;">Flux RSS</a>'."\n";
        if(ALLOW_NEW_AUTOBLOGS_BY_SOCIAL) {
          $button_list .= '        <a href="#add_social" class="button" id="button_social" onclick="show_form(\'social\');return false;">Compte réseau social</a>'."\n";
          $button_list .= '        <a href="#add_shaarli" class="button" id="button_shaarli" onclick="show_form(\'shaarli\');return false;">Shaarli</a>'."\n";
        }
        if(ALLOW_NEW_AUTOBLOGS_BY_OPML_FILE)
          $button_list .= '        <a href="#add_opmlfile" class="button" id="button_opmlfile" onclick="show_form(\'opmlfile\');return false;">Fichier OPML</a>'."\n";
        if(ALLOW_NEW_AUTOBLOGS_BY_OPML_LINK)
          $button_list .= '        <a href="#add_opmllink" class="button" id="button_opmllink" onclick="show_form(\'opmllink\');return false;">Lien vers OPML</a>'."\n";
        if(ALLOW_NEW_AUTOBLOGS_BY_BUTTON)
          $button_list .= '        <a href="#add_bookmark" class="button" id="button_bookmark" onclick="show_form(\'bookmark\');return false;">Marque page</a>'."\n";
          $button_list .= "      </p>\n";
          echo $button_list;
        
        if(ALLOW_NEW_AUTOBLOGS_BY_LINKS == TRUE) { ?>
      <section class="form" id="add_generic">
        <header>
          <h3>Ajouter un site web</h3>
        </header>
        
        <p>Si vous souhaitez que <em><?php echo $_SERVER['SERVER_NAME']; ?></em> héberge un autoblog d'un site, remplissez le formulaire suivant :</p>
        
        <?php echo $form; echo "\n"; ?>
      </section>
<?php }
      if(ALLOW_NEW_AUTOBLOGS_BY_SOCIAL == TRUE) { ?>
      <section class="form" id="add_social">
        <header>
          <h3>Ajouter un compte social</h3>
        </header>
        
        <form method="POST">
          <input placeholder="Identifiant du compte" type="text" name="socialaccount" id="socialaccount" /><br />
          <?php
            if( API_TWITTER !== FALSE ) {
              if( API_TWITTER === 'LOCAL' )
                echo '<input type="radio" name="socialinstance" value="twitter" />Twitter (local)<br />';
              else
                echo '<input type="radio" name="socialinstance" value="twitter" />Twitter (via <a href="'.strtok(API_TWITTER,'?').'">bridge</a>)<br />';
            }
            else echo '<s>Twitter</s><br />'; ?>
          <input type="radio" name="socialinstance" value="statusnet" />
          <input placeholder="statusnet.personnel.com" type="text" name="statusneturl" id="statusneturl" /><br />
          <input type="radio" name="socialinstance" value="youtube" />Youtube<br />
          <input placeholder="Antibot : écrivez « <?php echo $antibot; ?> » en chiffres" type="text" name="number" class="smallinput" /><br />
          <input type="hidden" name="antibot" value="<?php echo $antibot; ?>" />
          <input type="submit" value="Créer" />
         </form>
      </section>
      <section class="form" id="add_shaarli">
        <header>
          <h3>Ajouter un Shaarli</h3>
        </header>
        
        <form method="POST">
          <input type="hidden" name="socialinstance" value="shaarli" />
          <input placeholder="shaarli.personnel.com" type="text" name="shaarliurl" id="shaarliurl" /><br />
          <input placeholder="Antibot : écrivez « <?php echo $antibot; ?> » en chiffres" type="text" name="number" class="smallinput" /><br />
          <input type="hidden" name="antibot" value="<?php echo $antibot; ?>" />
          <input type="submit" value="Créer" />
        </form>
      </section>
  <?php }
     if(ALLOW_NEW_AUTOBLOGS_BY_OPML_FILE == TRUE) { ?>
      <section class="form" id="add_opmlfile">
        <header>
          <h3>Ajouter par fichier OPML</h3>
        </header>
        
        <form enctype='multipart/form-data' method='POST'>
          <input type='hidden' name='opml_file' value='1' />
          <input type='file' name='file' /><br />
          <input placeholder="Antibot : écrivez « <?php echo $antibot; ?> » en chiffres" type="text" name="number" class="smallinput" /><br />
          <input type="hidden" name="antibot" value="<?php echo $antibot; ?>" />
          <input type='submit' value='Importer' />
        </form>
      </section>
  <?php }
     if(ALLOW_NEW_AUTOBLOGS_BY_OPML_LINK == TRUE) { ?>
      <section class="form" id="add_opmllink">
        <header>
          <h3>Ajouter par lien OPML</h3>
        </header>
        
        <form method="POST">
          <input type="hidden" name="opml_link" value="1" />
          <input placeholder="Lien vers OPML" type="text" name="opml_url" id="opml_url" class="smallinput" /><br />
          <input placeholder="Antibot : écrivez « <?php echo $antibot; ?> » en chiffres" type="text" name="number"  class="smallinput" /><br />
          <input type="hidden" name="antibot" value="<?php echo $antibot; ?>" />
          <input type="submit" value="Envoyer" />
        </form>
      </section>
  <?php }
     if(ALLOW_NEW_AUTOBLOGS_BY_BUTTON == TRUE) { ?>
      <section class="form" id="add_bookmark">
        <header>
          <h3>Marque page</h3>
        </header>
        
        <p>Pour ajouter facilement un autoblog d'un site web, glissez ce bouton dans votre barre de marque-pages &rarr; <a class="bouton" onclick="alert('Glissez ce bouton dans votre barre de marque-pages (ou clic-droit > marque-page sur ce lien)');return false;" href="javascript:(function(){var%20autoblog_url=&quot;<?php echo serverUrl().$_SERVER["REQUEST_URI"]; ?>&quot;;var%20popup=window.open(&quot;&quot;,&quot;Add%20autoblog&quot;,'height=180,width=670');popup.document.writeln('<html><head></head><body><form%20action=&quot;'+autoblog_url+'&quot;%20method=&quot;GET&quot;>');popup.document.write('Url%20feed%20%20:%20<br/>');var%20feed_links=new%20Array();var%20links=document.getElementsByTagName('link');if(links.length>0){for(var%20i=0;i<links.length;i++){if(links[i].rel==&quot;alternate&quot;){popup.document.writeln('<label%20for=&quot;feed_'+i+'&quot;><input%20id=&quot;feed_'+i+'&quot;%20type=&quot;radio&quot;%20name=&quot;rssurl&quot;%20value=&quot;'+links[i].href+'&quot;/>'+links[i].title+&quot;%20(%20&quot;+links[i].href+&quot;%20)</label><br/>&quot;);}}}popup.document.writeln(&quot;<input%20id='number'%20type='hidden'%20name='number'%20value='17'>&quot;);popup.document.writeln(&quot;<input%20type='hidden'%20name='via_button'%20value='1'>&quot;);popup.document.writeln(&quot;<br/><input%20type='submit'%20value='Vérifier'%20name='Ajouter'%20>&quot;);popup.document.writeln(&quot;</form></body></html>&quot;);})();">Projet Autoblog</a>
      </section>
<?php } ?>
    </section>
<?php   } ?>
    <?php
      $directory = DOC_FOLDER;
      $docs = array();
      if( is_dir($directory) && !file_exists($directory . '.disabled') ) {
        $subdirs = glob($directory . "*");
        foreach($subdirs as $unit) {
          if(!is_dir($unit) || file_exists( $unit . '/index.html' ) || file_exists( $unit . '/index.htm' ) || file_exists( $unit . '/index.php' ) ) {
            $size = '';
            if ( is_file($unit) ) { $size = get_size($unit); }
              $docs[] = array('<a href="'. preg_replace('~ ~', '%20', $unit) . '">'. substr($unit, (strrpos($unit, '/')) + 1 ) .'</a>', $size);
            }
          }
        }
        if(!empty( $docs )) {
          echo '<section id="docs">
      <header>
        <h2>Autres documents</h2>
      </header>
      
      <ul>'."\n";
          foreach( $docs as $value ) {
            $str = $value[0];
            if ( !empty($value[1]) ) {
              $str = sprintf('%s (%s)', $value[0], $value[1]);
            }
            echo '        <li>'. $str . "</li>\n";
          }
          echo '      </ul>
    </section>'."\n";
        }
    ?>
    <section id="autoblogs">
      <header>
        <h2>Autoblogs hébergés <a href="?rss" title="RSS des changements"><img src="<?php echo RESOURCES_FOLDER; ?>rss.png" alt="rss"/></a></h2>
      </header>
      
      <nav class="clear">
        <a href="?sitemap">sitemap</a> |
        <a href="?export"    >export<sup>JSON</sup></a> |
        <a href="?exportopml">export<sup>OPML</sup></a>
      </nav>
      
      <ul>
        <?php
        $subdirs = glob(AUTOBLOGS_FOLDER . "*");
        $autoblogs = array();
        foreach($subdirs as $unit) {
          if(is_dir($unit)) {
            if( !file_exists(ROOT_DIR . '/' . $unit . '/.disabled')) {
              if( file_exists(ROOT_DIR . '/' . $unit . '/vvb.ini')) {
                $ini = parse_ini_file(ROOT_DIR . '/' . $unit . '/vvb.ini');
                if($ini) {
                  $config = new stdClass;
                  $unit=substr($unit, 2);
                  foreach ($ini as $key=>$value) {
                    $key = strtolower($key);
                    $config->$key = $value;
                  }
                  $autoblogs[$unit] = $config;
                  unset($ini);
                }
              }
            }
          }
        }
        
        uasort($autoblogs, "objectCmp");
        $autoblogs_display = '';
        
        if(!empty($autoblogs)){
          foreach ($autoblogs as $key => $autoblog) {
            $opml_link='<a href="'.$key.'/?opml">opml</a>';
            $autoblogs_display .= '<li>
          <header>
            <a title="'.escape($autoblog->site_title).'" href="'.$key.'/">
              <img width="15" height="15" alt="" src="'.RESOURCES_FOLDER.'icon-'.escape($autoblog->site_type).'.svg" />
              <img width="15" height="15" alt="" src="./?check='.$key.'" />
              <h3>'.escape($autoblog->site_title).'</h3>
            </a>
          </header>
          <div class="source">config <sup><a href="'.$key.'/vvb.ini">ini</a> '.$opml_link.'</sup> | '.escape($autoblog->site_type).' source : <a href="'.escape($autoblog->site_url).'">'.escape($autoblog->site_url).'</a></div>
        </li>';
                    }
                }
                echo $autoblogs_display;
                ?>
       </ul>
       <?php echo "<p>".count($autoblogs)." autoblogs hébergés</p>"; ?>
       </section>
       <?php if(ENABLE_YOUTORR == TRUE && $youtorr_db_error == FALSE){ ?>
         <section id="autoblogs">
         <header>
           <?php
             $title='<h2><a href="'.$youtorr_url.'" >Channel youtube hébergé sur youtorr</h2> </a>';
           echo $title
           ?>
         </header>
         <ul>
         <?php
	   $youtorr_channels='';
	   if($youtorr_db_engine=='api'){
	     $youtorr_json = file_get_contents($youtorr_api);
	     $results = json_decode($youtorr_json,true);
	     $youtorr_channels=array();
	     $count=0;
	     foreach($results as $result){
	       foreach($result as $key => $value){
		  $youtorr_channels[$count]=array('channel' => $key, 'url' => $value);
		  $count++;
               }
             }
	   }else{
             $conn = $youtorr_db->prepare("SELECT channel,url FROM channels");
             $conn->execute();
             $youtorr_channels=$conn->fetchAll(PDO::FETCH_ASSOC);
	   }
           $youtorrchannels_display='';
           foreach($youtorr_channels as $channel){
             $youtorrchannels_display.='<li>
             <header>
               <a title="'.$channel['channel'].'" href="'.$youtorr_url.'?page=channel&channel='.$channel['channel'].'">
                 <h3>'.$channel['channel'].'</h3>
               </a>
             </header>
               <div class="source"> <a href="'.$youtorr_url.'/'.$youtorr_zip.$channel['channel'].'.zip" >Toutes les videos</a>| source : <a href="'.$channel['url'].'">'.$channel['url'].'</a></div>
             </li>';
           }
         echo $youtorrchannels_display;
         ?>
         </ul>
         <?php echo "<p>".count($youtorr_channels)." channels hébergés</p>"; ?>
         </section>
         <?php } 
         if(ENABLE_YOUTORR == TRUE && $youtorr_db_error == TRUE){ ?>
         <section id="autoblogs">
         <header>
           <?php
             $title='<h2><a href="'.$youtorr_url.'" >Channel youtube hébergé sur youtorr</h2> </a>';
           echo $title
           ?>
         </header>
	 Erreur avec la BDD youtorr
	 </section>
       <?php } ?> 
    <footer>
      <p>Propulsé par <a href="https://github.com/mitsukarenai/Projet-Autoblog">Projet Autoblog 0.3</a> de <a href="https://www.suumitsu.eu/">Mitsu</a>, <a href="https://www.ecirtam.net/">Oros</a> et <a href="http://hoa.ro">Arthur Hoaro</a> (Domaine Public)</p>
      <p><?php if(defined('FOOTER') && strlen(FOOTER)>0 ){ echo FOOTER; } ?></p>
    </footer>
    
    <iframe width="1" height="1" style="display:none" src="xsaf3.php"></iframe>
    <script type="text/javascript">
      <?php if( !empty($_POST['generic']) && !empty($_POST['siteurl']) || empty($_POST['generic']) )
        echo "document.getElementById('add_generic').style.display = 'none';"; ?>
        if(document.getElementById('add_social') != null) { document.getElementById('add_social').style.display = 'none'; }
        if(document.getElementById('add_shaarli') != null) { document.getElementById('add_shaarli').style.display = 'none'; }
        if(document.getElementById('add_opmlfile') != null) { document.getElementById('add_opmlfile').style.display = 'none'; }
        if(document.getElementById('add_bookmark') != null) { document.getElementById('add_bookmark').style.display = 'none'; }
        if(document.getElementById('add_opmllink') != null) { document.getElementById('add_opmllink').style.display = 'none'; }
        if(document.getElementById('button_list') != null) { document.getElementById('button_list').style.display = 'block'; }
        function show_form(str){
          document.getElementById('add_'+str).style.display = (document.getElementById('add_'+str).style.display != 'block' ? 'block' : 'none' );
          document.getElementById('button_'+str).className = (document.getElementById('button_'+str).className != 'buttonactive' ? 'buttonactive' : 'button' );
        }
    </script>
  </body>
</html>

