<?php
setlocale(LC_ALL, 'en_US.utf8');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8'); 
                                        
error_reporting(0);
ini_set('display_errors','on');
ini_set('error_log','errors.log');

// PHP's builtin JSON parser does not correctly parse JSON output from MozREPL, it throws an error
// because MozREPL's output is not 100% proper JSON. So I am writing a quick JSON parser here:

function json_parse_string($str) {
  $q = mb_substr($str,0,1);//[0];
  $s = "";
  $last  = '';
  $finished = false;
  $processed_i = 1;

  while ($finished == false) {
  	$qi = mb_strpos($str, $q, $processed_i);
	$si = mb_strpos($str, '\\', $processed_i);

	if ($si && $si < $qi) {
	   $s .= mb_substr($str, $processed_i, $si-1-$processed_i).mb_substr($str,$si+1, 1);
	   $processed_i = $si+2; 
	} else if ($qi) {
	   $s .= mb_substr($str, $processed_i, $qi-$processed_i);
	   $rest = mb_substr($str, $qi+1);
	   $finished = true; 
	} else {
	   $s .= mb_substr($str, $processed_i);
	   $rest = '';
	   $finished = true; 
	}
  }
  return array($s, $rest); 
}

function json_parse_number($str) {
  $s = "";
  $float = false;

  for($i = 0; $i < mb_strlen($str);$i++) {
    $c = mb_substr($str,$i,1);
    if (in_array($c, array('0','1','2','3','4','5','6','7','8','9','.'))) {
      $s .= $c;
      if ($c == '.') 
	$float = true; 
    } else { 
      break; 
    }
  }
  
  $str = substr($str, mb_strlen($s));

  if (!$s) 
    return array(false, $str);

  if ($float) 
    return array(floatval($s),$str);

  return array(intval($s),$str); }
		   

function json_parse_item($str) {
  $str = trim($str);
  $strf = substr($str, 0,1);

  if (in_array($strf,array("'","\""))) 
    return json_parse_string($str);

  if (in_array($strf,array('0','1','2','3','4','5','6','7','8','9'))) 
    return json_parse_number($str);
  
  if (in_array($strf,array("["))) 
    return json_parse_array($str);

  if (in_array($strf,array("{"))) 
    return json_parse_object($str); 
}

function json_parse($str) {
  $r = json_parse_item($str);
  return $r[0]; 
}
	 
function json_parse_array($str) {
  $str = mb_substr($str, 1);
  $a = array();
  $parsing =true;

  while ($parsing) {
    $str = trim($str);
    $c = substr($str,0,1);

    if ($c == ',') 
      $str = mb_substr($str,1);

    else if ($c == ']') 
      return array($a, mb_substr($str,1));

    else { 
      $r = json_parse_item($str);
            $a[] = $r[0];
      $str = $r[1];
    }
  }
}

class Mrepl {
  public $socket;
  public $name;
  public $debug = true;
  public $initcmds = array();

  // initialize every page loaded by scraper with javascript commands for escaping UTF8
  // content the prepare for sending over telnet, and stringify
  private $binitcmds = array('var escape_utf8 = function (aa) {
   var bb = "";
   for(i=0; i<aa.length; i++) {
     if(aa.charCodeAt(i)>127) {
       bb += "&#" + aa.charCodeAt(i) + ";"; }
   else {
      bb += aa.charAt(i); }}
   return bb; }',
'var stringify = function (obj) {
var t = typeof (obj);
if (t != "object" || obj === null) {
// simple data type
if (t == "string") obj = \'"\'+obj+\'"\';
return String(obj);
}
else {
// recurse array or object
var n, v, json = [], arr = (obj && obj.constructor == Array);
for (n in obj) {
v = obj[n]; t = typeof(v);
if (t == "string") v = \'"\'+v+\'"\';
else if (t == "object" && v !== null) v = JSON.stringify(v);
json.push((arr ? "" : \'"\' + n + \'":\') + String(v));
}
return (arr ? "[" : "{") + String(json) + (arr ? "]" : "}");
}
};');

  function __construct($address='localhost', $port=4242) {
    $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($this->socket, $address, $port);
    $this->read();
    $this->init();
    return $this; 
  }

  public function init() {
    $this->send('var g = this.getBrowser();')->recv()->send('var w = g.contentWindow')->recv()->send('var d = w.document')->recv();

    foreach ($this->binitcmds as $cmd) 
      $this->run($cmd);

    foreach ($this->initcmds as $cmd) 
      $this->run($cmd);

    return $this; 
  }

  public function go_to($url, $exp=false) {
    if ($exp === true) 
      $exp = $url;

    $this->send('this.getBrowser().contentWindow.location.href="'.addslashes($url).'"')
      ->recv();
    $this->init(); 

    if ($exp) 
      $this->until('this.getBrowser().contentWindow.location.href',$exp);
    
    $this->until('this.getBrowser().contentWindow.document.readyState', 'complete');
    $this->init(); 

    return $this;
  }

  public function until_ready() {
    return $this->until('this.getBrowser().contentWindow.document.readyState', 'complete');
  }

  public function until($cmd, $exp) {
    while ($r = $this->evaluate($cmd)) {
      if ($this->debug) 
	echo $r.' == '.$exp."?;\n";

      if ($r == $exp) 
	return $this; 
    }
  }

  public function run($cmd, $exp=false) {
    if ($this->debug) 
      echo "\n $ ".$cmd."\n";

    if ($exp) 
      return $this->until($cmd, $exp);

    return $this->send($cmd)
      ->recv(); 
  }

  public function recv() {
    $r = $this->read(); 
    
    if ($this->debug)   
      echo "\t--> ".$r;

    return $this; 
  }

  public function result() {
    return json_decode($this->read()); 
  }

  public function read() {
    $r = "";
    $match = array();
  
    while($chunk = socket_read($this->socket,65536,PHP_BINARY_READ))
      if ($chunk === false) return false;
      else if ($chunk == "") break;
      else if (preg_match('|^(.*)\s*(repl\d*)>\s*$|s',$chunk,$match)) {
        $r .= $match[1]; 
	$this->name = $match[2]; 
	break; 
      }
      else $r .= $chunk;
    
    return $r; 
  }

  public function evaluate($cmd) {
    if ($this->debug)   
      echo "\n\n#".$cmd."\n\t>>>";

    $c = stripslashes(mb_substr(mb_convert_encoding($this->send('JSON.stringify(JSON.stringify('.$cmd.'))')
						    ->read(),
						    'UTF-8', 
						    'ISO-8859-1'),
				2,-3));

    $r=json_parse($c);

    if ($this->debug)     
      var_dump( $r); 

    return $r; 
  }

  public function evaluate_img($cmd) {
    if ($this->debug)     
      echo "\n\n#img ".$cmd."\n\t>>>";

    $content = $this->send('JSON.stringify(JSON.stringify('.$cmd.'))')
      ->read();

    $r=mb_substr($content,4,-4);
    return $r; 
  }

  public function send($cmd) {
    socket_write($this->socket, $cmd.";\n");
    return $this; 
  }
}

//array values matching regex
function arv_matching($regex, $ar) {
  $r = array();

  foreach($ar as $v) 
    if (preg_match($regex, $v)) 
      $r[] = $v;
  
  return $r; 
}

//array values prefixed
function arv_prefixed($prefix, $ar) {

  foreach($ar as $i => $v)
    $ar[$i] = $prefix.$v; 

  return $ar; 
}
