<?php
/*
	?stats - to get stats
	?stats_reset - to get stats and reset them
	?function=...&store=...&param=[url|sku|keyword]&proxy=[true|proxy:port]
*/
header('Content-Type: text/html; charset=utf-8');
set_time_limit(200);
ini_set("default_socket_timeout", 200);
ini_set('max_execution_time', 200); 
ini_set("include_path", "../../libs".PATH_SEPARATOR."libs/".PATH_SEPARATOR."../libs");  

$dir=__DIR__;
/*
$phantomLock=fopen($dir."/PhantomService/data/startPhantom.lock", 'w');
if($phantomLock && flock($phantomLock, LOCK_EX|LOCK_NB))
{
	exec("php $dir/PhantomService/startPhantom.php > /dev/null 2>/dev/null &");
	//don't unlock, let it unlock when scraper is done
}*/

$ip=isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR']:$_SERVER['REMOTE_ADDR'];
//do monitor
//here we should test if it's not cli
//if(isset($_GET['monitor']))

//check for runaway processes
//moved to isup.sh
/*
$procCheck=fopen("/tmp/procCheck.lock", 'w');
if(flock($procCheck, LOCK_EX|LOCK_NB))
{
	foreach(glob("/tmp/*.pid") as $file)
	{
		$pid=file_get_contents($file);
		if(file_exists("/proc/$pid")) 
		{
			if((time() - filectime($file)) > 200) exec("kill -9 $pid");
		}
		else unlink($file);
	}
	flock($procCheck, LOCK_UN);
}
fclose($procCheck);
*/




if(php_sapi_name() != 'cli')
{	
	if(empty($_GET) && empty($_POST))
	{
		print json_encode(array("error"=>"Not enough parameters"));
	}
	else if($_GET['function'] != 'Test' && (checkIpAccess($ip) || $_GET['function'] == 'Getter'))
	{
		unset($_GET['monitor']);
		$name="scraper_".getmypid()."_".time()."_".md5(implode("", $_GET).implode("",$_SERVER));
		$fh=fopen("/tmp/$name", "w");
		if(flock($fh, LOCK_EX))
		{
			$data=array("GET"=>$_GET, "SERVER"=>$_SERVER, "POST"=>$_POST);
			fwrite($fh, serialize($data));
			flock($fh, LOCK_UN);
			fclose($fh);
			exec("php ".__DIR__."/get.php file $name >/tmp/".$name.".json &");
			//wait for it to start up
			sleep(5);
			$pid=file_get_contents("/tmp/".$name.".pid");
			if($pid)
			{
				monitorScraper($pid, $name, 200);
			}
		}
	}
	else
	{
		print json_encode(array("error"=>"$ip Not allowed here, visit us at <a href='http://skuio.com'>Sku IO</a><br>\n"));;
	}
	exit();
}


//doing memcheck after the web script, so we won't get bugged down by bad ips and haproxy monitors
//memory is in Kb
$memCheck=fopen("/tmp/memCheck.lock", 'w');
if(flock($memCheck, LOCK_EX))
{
	$mem=file_get_contents("/proc/meminfo");
	$mem=explode(PHP_EOL, $mem);
	foreach($mem as $memLine)
	{
		if(stripos($memLine, "MemFree") !== FALSE)
		{
			$memLine=preg_replace("/\s+/", " ", $memLine);
			$memLine=explode(" ", $memLine);
			if(isset($memLine[1]))
			{
				$freeMem=intval($memLine[1]);
				//less than 50mb
				if($freeMem < 100)
				{
					print json_encode(array("error"=>"Not enough memory ".$freeMem));
					flock($memCheck, LOCK_UN);
					fclose($memCheck);
					exit();
				}
			}
			break;
		}	
	}
	flock($memCheck, LOCK_UN);
}
fclose($memCheck);




if(isset($argv[1]) && isset($argv[2]) && $argv[1] == "file")
{
	$name=$argv[2];
	$data=file_get_contents("/tmp/$name");
	file_put_contents("/tmp/".$name.".pid", getmypid());
	$data=unserialize($data);
	if(is_array($data))
	{
		$_GET=$data['GET'];
		$_SERVER=$data['SERVER'];
		$_POST=$data['POST'];
		$ip=isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR']:$_SERVER['REMOTE_ADDR'];
		
	}
	
}






require_once "Configurator/Configurator.php";
require_once "StoreParsing/StoreParsing.php";
require_once "StoreParsing/Getter/Getter.php";

require_once __DIR__."/extra_func.php";
require_once __DIR__."/DBC/amazon.php";

loadConfig(__DIR__."/configuration.ini");

//use cron for it
if(time() % 20 == 0) exec("/bin/bash ".__DIR__."/isup.sh"); 

$startTime=time();
setConfig('StartTime', $startTime);
setConfig('DEBUG', false);
setConfig('ALLOWCACHE', true);
//we allow cache, so that if scraper requires multiple calls to process on page, it would work, 
//but set it to expire in 2 minutes
setConfig('keepCacheFor', 2);
setConfig('noProxy', true);

$memCache = false;
/*memcache
$memCache = new Memcached(); 
$memCache->addServer("localhost", 11211);
$memCache->set("version", date ("F d Y H:i:s.", filemtime(__FILE__)));
*/


/**** TESTING PURPOSES ***/
if(isset($argv[1]) && $argv[1] == "test")
{
	setConfig('DEBUG', true);
	$_SERVER['REMOTE_ADDR']="127.0.0.1";
	$_POST['json']=json_encode(array(	//getPriceStock, getVariations, getOffers, getProduct, getItems, getSearch, getStockQuantity
										"function" => "getItems", 
										"store" => "amazon", 
										//sku, url or keyword (for search)
										"param" => "http://www.amazon.com/s/ref=sr_st_popularity-rank?lo=hpc&keywords=-asdafsdf&qid=1423266662&rh=n%3A3760901%2Ck%3A-asdafsdf&sort=popularity-rank", 
										//proxy can also be forced, just pass "ip:port as proxy parameter"
										"proxy" => "true"));
}
if(isset($_GET['debug']))
{
	print "<pre>";
	setConfig('DEBUG', true);
}
if(!isset($_POST['json']))
{

	$_POST['json']=json_encode($_GET);
}
//for casper
if(!isset($_POST["js"])) $_POST["js"]=false;


//do without ip check for now, since do direct call from scraper
if(isset($_GET['function']) && $_GET['function'] == 'Getter')
{
	print processGetter($_GET);
	exit();
}

if(getConfig('DEBUG')) print date("H:i:s").": checking IP access\n";
if(checkIpAccess($ip))
{
	if(getConfig('DEBUG')) print date("H:i:s").": checking request\n";
	
	if(checkRequest($_POST['json']))
	{		
		addStat("requested");
		
		if(getConfig('DEBUG')) print date("H:i:s").": starting to process request\n";
		$ret=processRequest($_POST['json'], $_POST['js']);
		if(!$ret) $ret=array("error"=>"error");

		if(getConfig('DEBUG')) print_r($ret);
		$ret=fixJson($ret);
		$output=json_encode($ret);
		//temp fix for bad unicode characters
		//$output=str_replace("null:", '"null":', $output);
		$output=str_replace("null", '""', $output);
		$output=str_replace('""""', '""', $output);
		
		print $output;
		
		if(getConfig('DEBUG')) print "\n";
	}
	else if(isset($_GET['stats']))
	{
		$stats=getStats(array("requested", "parsed"));
		if(isset($_GET['format']))
		{
			print "<pre>";
			print_r($stats);
			print "<pre>";
		}
		else print json_encode($stats);
	}
	else if(isset($_GET['stats_reset']))
	{
		$stats=getStats(array("requested", "parsed"), true);
		if(isset($_GET['format']))
		{
			print "<pre>";
			print_r($stats);
			print "<pre>";
		}
		else print json_encode($stats);
	}
	else if(isset($_GET['stats_stores']))
	{
		$stats=getStatsStores();
		if(isset($_GET['format']))
		{
			print "<pre>";
			print_r($stats);
			print "<pre>";
		}
		else print json_encode($stats);
	}
	//test only a subset of stores
	else if(isset($_GET['stats_stores_part']))
	{
		$stats=getStatsStores(true);
		if(isset($_GET['format']))
		{
			print "<pre>";
			print_r($stats);
			print "<pre>";
		}
		else print json_encode($stats);
	}
	else
	{
		$ret=array("error"=> "Malformed request\n<br>", $_POST['json']);
		print json_encode($ret);
	}
	
}
else
{
	print json_encode(array("error"=>"$ip Not allowed here, visit us at <a href='http://skuio.com'>Sku IO</a><br>\n"));;
}



$totalTime=time()-$startTime;
$pid=getmypid();
//exec("echo $pid $totalTime >> /tmp/timelog.txt");


function fixJson($ret)
{
	foreach($ret as $key=>$val)
	{			
		//check for key
		if((!is_string($key) && !is_numeric($key)) || !preg_match_all("/[a-zA-Z0-9]+/", $key, $matches)) unset($ret[$key]);
		//check for sub array
		else if(is_array($val))
		{
			$ret[$key]=fixJson($val);
		}
		else if(!is_bool($val))
		{
				$val=goodString($val);
				if(!preg_match_all("/[a-zA-Z0-9]+/", $val, $matches)) $ret[$key]="";
				else
				{	
					$val=str_replace("null", "", $val);
					$ret[$key]=$val;	
				}
		} 
	}
	return $ret;
}

function getServerIp()
{
	return isset($_SERVER['SERVER_ADDR']) ?	$_SERVER['SERVER_ADDR']."_":"cli_";
}

function addStat($var)
{
/*	memCache
	global $memCache;
	$var=getServerIp().$var;
	$value=$memCache->get($var);
	if(!$value) $value=0;
	$value++;
	$memCache->set($var, $value);*/
}

//gets my actual remote ip and stats per connection to stores
function getStatsStores($onlyPart=false)
{
	global $activeStores;
	$ret=array();
	$ret['ip']=getMyRealIps();
	$ret['bad']=array();
	$ret['good']=array();
	$testStores=$activeStores;
	shuffle($testStores);
	if($onlyPart) $testStores=array_slice($testStores, 0, 30);
	foreach($testStores as $config)
	{
		if($config['offline'] == false)
		{
			$url="http://".$config['domain'];
			$name=$config['name'];
			$status=curlGetStatus($url);
			if(isset($status['code']) && $status['code'] == 200) $ret['good'][$name]=$status;
			else $ret['bad'][$name]=$status;
		}
	}
	return $ret;
}

function getStats($ar, $reset=false)
{
	global $memCache, $activeStores;
	$ret=array();
	/*memCache
	foreach($ar as $var)
	{
		$mVar=getServerIp().$var;
		$val=$memCache->get($mVar);
		if(is_numeric($val) && $reset)
		{
			$memCache->set($mVar, 0);
			$memCache->set(getServerIp()."lastReset", date("d-m-Y H:i:s"));
		}
		if(!$val) $val=0;
		$ret[$var]=$val;
	}
	$ret["lastReset"]=$memCache->get(getServerIp()."lastReset");
	if(!$ret["lastReset"]) $ret["lastReset"]="false";
	$ret['cron']=Process::fastCheck("cron");
	$ret['activeStoresSize']=strlen(serialize($activeStores));
	$ret["version"]=$memCache->get("version");
	$statFile=__DIR__."/.git/FETCH_HEAD";
	if(file_exists($statFile)) $ret["gitTime"]=date("d-m-Y H:i:s", filemtime($statFile));
	$ret["ts"]=date("d-m-Y H:i:s");*/
	return $ret;
}

function checkRequest($json)
{
	$req=json_decode($json,true);
	if(isset($req['function']) && isset($req['store']) && isset($req['param']))
	{
		return true;
	}
	return false;
}

function processRequest($json, $js)
{
	global $activeStores;
		
	$ret=false;
	
	$req=json_decode($json, true);
	
	$store=strtoupper($req['store']);
	
	if(isset($req['proxy']))
	{
		if($req['proxy']=="true") setConfig("noProxy", false);
		else if(stripos($req['proxy'], ":") !== FALSE) setConfig('ProxyServer', $req['proxy']);
	} 
	
	if(isset($activeStores[$store]))
	{
		$param=rawurldecode($req['param']);
		preg_match_all("/https{0,1}:/", $param, $matches);
		//if it's casper (param = casper)
		if($param == "casper" &&  $js)
		{
			$ret=runCasper($js);
		}
		//if it's a  call to store parsing
		else
		{
			//need to set configs depending on the call
			if($req['function'] == 'getVendorSku')
			{
				setConfig('OfflineMode', true);
				setConfig('useProxy', false);
				setConfig('useTor', false);
				setConfig('CurlTimeout',1);
				$store='UNKNOWN';
			}
			//if it's url
			if(isset($matches[0][0]))
			{
				$param=str_replace(" ", "+", $param);
				if($store == 'UNKNOWN') $store=getStoreByUrl($param);
				$parser=getParser($store, $param);
			}
			//if it's a sku
			else
			{			
				//if it's a search, then create empty parser
				if($req['function'] == "getSearch")
				{
					$parser=getParser($store, '');
				}
				else
				{
					$parser=getParserById($store, $param);
				}
			}

			switch($req['function'])
			{
				case "getStockQuantity":
					$ret=getStockQuantity($parser);
					$ret=checkBlocked($parser, $ret);
					break;
				case "getPriceStock":
					$ret=getPriceStock($parser);
					$ret=checkBlocked($parser, $ret);
					break;
				case "checkCaptcha":
					$ret=checkCaptcha($parser);
					break;
				case "getVariations":
					$ret=getVariations($parser);
					break;
				case "getOffers":
					$ret=getOffers($parser, 25);
					$ret=checkBlocked($parser, $ret);
					break;
				case "getProduct":
					$ret=getProduct($parser);
					$ret=checkBlocked($parser, $ret);
					break;
				case "getProductSkipOffers":
					$ret=getProduct($parser, 0);
					$ret=checkBlocked($parser, $ret);
					break;
				case "getItems":
					$ret=getItems($parser);
					break;
				case "getSearch":
					$ret=getSearch($parser, $param);
					break;
				case "getVendorSku":
					$ret=getVendorSku($parser);
					break;
				
			}
			if($parser->get("docError") !== FALSE && $req['function'] != 'getVendorSku') $ret["error"]="Failed to get some document";
			if($parser->get("internalError") !== FALSE && $req['function'] != 'getVendorSku') $ret["error"]="Got Internal Error: ".$parser->get("internalError");
			
		}
	}
	if($ret && !empty($ret))
	{
		$ret['function']=$req['function'];
		$ret['param']=$req['param'];
		addStat("parsed");
	}
	return $ret;

}

function runCasper($js)
{
	$ret=array("Casper"=>$js);
	return $ret;
}

function getVendorSku($parser)
{
	$ret['sku']=$parser->get("id");
	$ret['store']=$parser->getConfig("store");
	return $ret;
}

//get price, shipping and stock of an item
function getPriceStock($parser)
{
	if(getConfig('DEBUG')) print date("H:i:s").": Function getPriceStock\n";
	
	$ret=array();
	if(!$parser->get("testId"))
	{
	 	$ret["error"]="Invalid sku";
		return $ret;
	}
	$ret['price']=$parser->get("price");
	$ret['shipping']=$parser->get("shippingCost");
	$ret['stock']=$parser->get("stockStatus");
	$ret['retail']=$parser->get("retail");
	$ret['sku']=$parser->get("id");
	$ret['store']=$parser->getConfig("store");
	
	if($ret['price'] == 0) $ret['stock'] = 0;
	
	return $ret;	
}

//get stock quantity
function getStockQuantity($parser)
{
	if(getConfig('DEBUG')) print date("H:i:s").": Function getStockQty\n";
	
	$ret=array();
	if(!$parser->get("testId"))
	{
	 	$ret["error"]="Invalid sku";
		return $ret;
	}
	//here we get a parameter variationQuantity and if it's set, 
	//then get variation with that index and get it's quantity
	//set variation to get quantity for with 
	/*if(($index=getParam("variationQuantity")))
	{
		$parser->setConfig("varQuantity", $index);
		$var=$parser->get("variations");
		if(isset($var[$index])) $ret["stockQuantity"]=$var[$index]->getQuantity();
		else $ret["stockQuantity"]=0;
	}else...*/
	$ret['stockQuantity']=$parser->get("stockQuantity");
	$ret['sku']=$parser->get("id");
		
	return $ret;	
}

function getVariations($parser)
{
	if(getConfig('DEBUG')) print date("H:i:s").": Function getVariations\n";
	
	$ret=array();
	if(!$parser->get("testId"))
	{
	 	$ret["error"]="Invalid sku";
		return $ret;
	}
	$ret['sku']=$parser->get("id");
	$ret['store']=$parser->getConfig("store");
	
	$price=$parser->get("price");
	$shipping=$parser->get("shippingCost");
	$retail=$parser->get("retail");
	$stock=$parser->get("stockStatus");
	
	$ret['variations']=array();
	foreach($parser->get("variations") as $var)
	{
		$myVar['price']=$var->getPriceList()->getLowestPrice();
		$myVar['shipping']=$var->getPriceList()->getLowestShipping();
		$myVar['retail']=$var->getPriceList()->getRetail();
		$myVar['stock']=$var->getStock();
		$myVar['sku']=$var->getSku();
		$myVar['shippingDays']=$var->getShippingDays();
		
		$pl=$var->getPriceList();
		foreach($pl->getList() as $i=>$psh)
		{
			$offer=array();
			$offer['attributes']=$pl->getAttr($i);
			$offer['price']=$psh->getPrice();
			$offer['shipping']=$psh->getShipping();
			$offer['retail']=$psh->getRetail();
			$offer['total']=$psh->getTotal();
			$myVar['offers'][]=$offer;
		}
		
		//if we have sku set, then it might have it's own prices
		//but otherwise
		if(!$myVar['sku'])
		{
			if(!$myVar['retail']) $myVar['retail']=$retail;
			if($myVar['stock'] === FALSE) $myVar['stock']=$stock;
			if($myVar['shipping'] === FALSE) $myVar['shipping']=$shipping;
			if(!$myVar['price']) $myVar['price']=$price;
		}
		
		$myVar['attributes']=$var->getAttributes();
		
		$ret['variations'][]=$myVar;
	}
	return $ret;
}

function getOffers($parser, $maxOfferPages = -1)
{
	if(getConfig('DEBUG')) print date("H:i:s").": Function getOffers\n";
	
	$ret=array();
	if(!$parser->get("testId"))
	{
	 	$ret["error"]="Invalid sku";
		return $ret;
	}
	$ret['sku']=$parser->get("id");
	$ret['store']=$parser->getConfig("store");

	$ret['offers']=array();
	if($maxOfferPages == 0) setConfig("skipOffers", true);
	$parser->setConfig("maxOfferPages", $maxOfferPages);
	if($parser->get("priceList"))
	{
		$pl=$parser->get("priceList");
		foreach($pl->getList() as $i=>$psh)
		{
			$offer=array();
			$offer['attributes']=$pl->getAttr($i);
			$offer['price']=$psh->getPrice();
			$offer['shipping']=$psh->getShipping();
			$offer['retail']=$psh->getRetail();
			$offer['total']=$psh->getTotal();
			$ret['offers'][]=$offer;
		}	
	}
	
	return $ret;
}

//return all default fields
//all extra fields: put into "extras"
function getProduct($parser, $maxOfferPages = -1)
{
	if(getConfig('DEBUG')) print date("H:i:s").": Function getProduct\n";
	
	if(!$parser->get("testId"))
	{
	 	$ret["error"]="Invalid sku";
		return $ret;
	}
	$ret=getVariations($parser);
	$ret=array_merge($ret, getOffers($parser, $maxOfferPages));
	
	$defaultFields=array(	"price"		=>	"price",
							"shipping"	=>	"shippingCost",
							"stock"		=>	"stockStatus",
							"retail"	=>	"retail",
							"title"		=>	"title",
							"description"=>	"description",
							"brand"		=>	"brand",
							"mpn"		=>	"mpn",
							"upc"		=>	"upc",
							"url"		=>	"url",
							"images"	=>	"images",
							"category"	=>	"category",
							"blocked"	=>	"blocked");
							
	$skipFields=array(	"sku", "priceList", 
						"variations", 
						"childCategoryLinks", "categoryLinks", "id", "searchResults",
						 "localSku", "googleCategory",
						"localImages", "stockCount", "stockQuantity", "reviews", "bundle");
	
	foreach($defaultFields as $name=>$field)
	{
		$ret[$name]=$parser->get($field);
	}
	
	$productFields=$parser->getProductFields();
	$internalFields=$parser->getInternalFields();

	//extra fields
	$ret["extras"]=array();	
	$productFields=array_diff($productFields, $skipFields, $internalFields, $defaultFields);
	foreach($productFields as $field)
	{
		$val=$parser->get($field);
		//just in case field got to be internal field
		if(!in_array($field, $parser->getInternalFields())) $ret["extras"][$field]=$val;
	}
	foreach($ret as $key=>$str)
	{
		if(is_string($str) && stripos($str, '"') !== FALSE) $ret[$key]=($str);
	}
	//need to update description
	if(isset($ret['description']))
	{
		$ret["description"] = preg_replace('/\R/u', '', $ret["description"]);
	}
	
	if(getConfig('DEBUG')) print date("H:i:s").": Done getProduct\n";
	
	return $ret;
}

//get price, shipping and stock of an item
function checkCaptcha($parser)
{
	if(getConfig('DEBUG')) print date("H:i:s").": Function checkCaptcha\n";
	
	$ret=array();
	setConfig('useTor', false);
	$parser->setConfig("captcha", true);
	$ret["price"]=$parser->get("price");
	$ret["blocked"]=$parser->get("blocked");
	
	if(getConfig('DEBUG')) print date("H:i:s").": Done checkCaptcha\n";
	
	return $ret;	
}

function checkBlocked($parser, $ret)
{
	global $memCache;
	
	if(getConfig('DEBUG')) print date("H:i:s").": Function checkBlocked\n";
	
	if(!$parser->get("testId"))
	{
	 	$ret["error"]="Invalid sku";
		return $ret;
	}
	//if we received a blocked signal, but we already got some offers
	//then don't send back error
	if($parser->get("priceList")->hasPrice() && isset($ret["blocked"]) && $ret["blocked"]) $ret["blocked"]=false;
	//if we don't have any prices and got blocked signal
	//and have not tried cracking captcha in the last 20 seconds	
	/*memcache  else if(isset($ret["blocked"]) && $ret["blocked"] && !$memCache->get("amazon_captcha_wait"))*/
	else if(isset($ret["blocked"]) && $ret["blocked"])
	{
		//try just keep constantly decaptching, since we use unlimited decaptching service...
		//$memCache->set("amazon_captcha_wait", true, 2);
		/*if(!getConfig('ProxyServer'))
		{
			
			if(startDecaptcha())
			{
				//server is not blocked
				$ret["blocked"]=false;
				//but we still did not get prices
				$ret["error"]="Server is decaptched";
			}
			
		}*/
		
	}
	
	return $ret;
}

function getItems($parser)
{
	$ret=array();
	$ret['store']=$parser->getConfig("store");
	$parser->setConfig("maxPages", 1);
	$ret['categoryLinks']=array();
	foreach($parser->get("categoryLinks") as $name=>$link)
	{
		$name=goodString($name);
		if(preg_match_all("/[a-zA-Z0-9]+/", $name, $matches))  $ret['categoryLinks'][$name]=$link;
	}
	$ret['childCategoryLinks']=array();
	foreach($parser->get("childCategoryLinks") as $name=>$link)
	{
		$name=goodString($name);
		if(preg_match_all("/[a-zA-Z0-9]+/", $name, $matches)) $ret['childCategoryLinks'][$name]=$link;
	}
	$ret['links']=array();
	$links=$parser->getLinksFromCat();
	$ret['nextPageLink']=$parser->get("nextPageCatalog");
	$ret['selectedCategoryName']=$parser->get("selectedCategoryName");
	
	$ret['totalLinks']=count($links);
	$ret['itemCount']=$parser->get("categoryItemCount");
	foreach($links as $link)
	{
		$myLink=array();
		$myLink["title"]=goodString(str_replace('"', "'", $link->getTitle()));
		$myLink["title"] = goodString($myLink["title"]);
		if(!is_string($myLink["title"])) $myLink["title"]="";
		$myLink["image"]=$parser->wrapUrl($link->getImage());
		$myLink["price"]=$link->getPrice();
		$myLink["url"]=$parser->wrapUrl($link->getLink());
		$myLink["attr"]=$link->getAttr();
		$ret['links'][]=$myLink;
	}
	
	return $ret;
}

function getSearch($parser, $keyword)
{
	$parser->set("keyword", $keyword);
	$ret=array();
	$ret['store']=$parser->getConfig("store");
	$parser->setConfig("maxPages", 1);
	$links=$parser->get("searchResults");
	
	$ret['nextPageLink']=$parser->get("nextPageCatalog");
	$ret['selectedCategoryName']=$parser->get("selectedCategoryName");
	
	$ret['categoryLinks']=array();
	foreach($parser->get("categoryLinks") as $name=>$link)
	{
		$name=goodString($name);
		if(preg_match_all("/[a-zA-Z0-9]+/", $name, $matches)) $ret['categoryLinks'][$name]=$link;
	}
	$ret['childCategoryLinks']=array();
	foreach($parser->get("childCategoryLinks") as $name=>$link)
	{
		$name=goodString($name);
		if(preg_match_all("/[a-zA-Z0-9]+/", $name, $matches)) $ret['childCategoryLinks'][$name]=$link;
	}
	
	$ret['links']=array();
	$ret['totalLinks']=count($links);
	foreach($links as $link)
	{
		$myLink=array();
		$myLink["title"] = goodString($link->getTitle());
		$myLink["title"]=str_replace('"', "'", $myLink["title"]);
		if(!is_string($myLink["title"])) $myLink["title"]="";
		$myLink["image"]=$parser->wrapUrl($link->getImage());
		$myLink["price"]=$link->getPrice();
		$myLink["url"]=$parser->wrapUrl($link->getLink());
		$myLink["attr"]=$link->getAttr();
		$ret['links'][]=$myLink;
	}
	
	return $ret;
}

function processGetter($data)
{
	$log=@file_get_contents("/tmp/getter.txt");
	file_put_contents("/tmp/getter.txt", $log."\n\n".print_r($data, true));
	$newData=array();
	foreach($data as $key=>$val)
	{
		$newData[strtolower($key)]=urldecode($val);
		if($key == 'body')
		{
			$newData['body']=json_decode(urldecode($val), true);
		}
	}
	if(getConfig('DEBUG')) print_r($newData);
	$get=new Getter($newData);
	$resp=$get->getResponse();
	return $resp; 
}

function goodString($string)
{
	$string=str_replace('"', "'", $string);
	$string = preg_replace( '/[[:cntrl:]]/', '',$string);
	$string = iconv("UTF-8","UTF-8//IGNORE",$string);
	//$string=htmlentities($string);
	return $string;
}

/*
	Test here to make sure that client is allowed
	Test by IP
*/
function checkIpAccess($ip)
{
	if(!empty($ip) && file_exists(__DIR__."/allowed_ips/".md5($ip)))
	{
		return true;
	}
	
	return false;
	
}

function monitorScraper($pid, $name, $timeOut = 150)
{
	ignore_user_abort(1); 
	header('Transfer-Encoding:chunked');
	flush();
	ob_flush();
	$startTime=time();
	
	while(connection_status() == 0 && file_exists("/proc/$pid") && (time() - $startTime) < $timeOut)
	{
		//do this: sending data to dead TCP connection will fail
	    echo " "; 
	    flush();
	    ob_flush();
	    usleep(50 * 1000);

	}
	//if our scraper has finished the job
	if(!file_exists("/proc/$pid"))
	{
		$json=file_get_contents("/tmp/".$name.".json");
		print $json;
		file_put_contents('/tmp/'.$name.".done", date("H:i:s").' SCRIPT COMPLETED.');	    
		
	}
	else if((time() - $startTime) >= $timeOut)
	{
		file_put_contents('/tmp/'.$name.".timeout", date("H:i:s").' SCRIPT TIMED OUT.');	    
	}
	else
	{
		file_put_contents('/tmp/'.$name.".abort", date("H:i:s").' CONNECTION ABORTED.'."\n"."Seconds elapsed: ".(time()-$startTime)."\n");   
	}
	//kill our scraper script if it still there
	exec("kill -9 $pid");
	echo " \r\n\r\n"; //stream termination packet (double \r\n according to proto)
    flush();
    ob_flush();
	return;
}

?>
