<?php
/**
 * Creates Simple statistics files based on incoming IP
 *
 * @author  Myron Turner <turnermm02@shaw.ca>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
if(!defined('QUICK_STATS')) define ('QUICK_STATS',DOKU_PLUGIN . 'quickstats/');

// DokuWiki_Action_Plugin should be automatically loaded by DokuWiki
// require_once DOKU_PLUGIN.'action.php';

// Check if isValidIPv6 will be automatically loaded by DokuWiki, if not, this line needs to be retained
require_once QUICK_STATS . 'scripts/php-inet6_1.0.2/valid_v6.php';

require_once QUICK_STATS .'GEOIP/vendor/autoload.php';

// Move the use statement here, after all require_once statements
use GeoIp2\Database\Reader;

/* for backward compatiblity */
if(!function_exists('utf8_strtolower')) {  
require_once(DOKU_INC.'inc/common.php');  
require_once(DOKU_INC.'inc/utf8.php');  
}

/*
error_reporting(E_ALL);
ini_set('display_errors','1');
*/

class action_plugin_quickstats extends DokuWiki_Action_Plugin {
    private $page_file;
    private $ip_file;
    private $misc_data_file;
    private $pages;
    private $ips;
    private $misc_data;
    private $page_totals_file;
    private $is_edit_user=false;
    private $year_month;
    private $totals;
    private $SEP = '/';    
    private $show_date;
    private $ua_file;
    private $helper;
    private $ipaddr;
    private $qs_file;
    private $dw_tokens; // query string names to omit from stats
    private $page_users_file;
    private $ipv6 = false;
    private $id;
    private $geocity2 = true;
    private $db_check;
    
    function __construct() {
          global $ID,$INPUT;
          $this->id = $INPUT->str('id');
          $ip = $_SERVER['REMOTE_ADDR'];          
          //$ip = "2001:982:acd6:1:4899:d135:226b:2e79";        
          //$ip = "2602:304:cec0:9b00:e96b:9c78:eb14:9fb";        
        // $ip = "76.24.190.253";
           if($this->is_excluded($ip, true)){          
            exit("403: Not Available");
           }          
          
         $ipv6 = isValidIPv6($ip);
         if($ipv6) {
             $this->ipaddr = $ipv6;
             $this->ipv6 = $ipv6;
         }
         else $this->ipaddr = $ip;
         $today = getdate();
            
         $ns_prefix = "quickstats:";
         $ns =  $ns_prefix . $today['mon'] . '_'  . $today['year'] . ':';  
         $this->page_file = metaFN($ns . 'pages' , '.ser');  
         $this->ua_file = metaFN($ns . 'ua' , '.ser');  
         $this->ip_file = metaFN($ns . 'ip' , '.ser');  
         $this->misc_data_file = metaFN($ns . 'misc_data' , '.ser');  
         $this->qs_file = metaFN($ns . 'qs_data' , '.ser');  
         $this->page_users_file = metaFN($ns . 'page_users' , '.ser');  
         $this->page_totals_file = metaFN($ns_prefix . 'page_totals' , '.ser');  
         $this ->db_check = metaFN($ns_prefix . 'db_warning' , '.txt');  
         if(!file_exists($this ->db_check)) {
               io_saveFile($this ->db_check,0);  
         }
         $this->year_month = $today['mon'] . '_'  .$today['year'];
         
         if( preg_match('/WINNT/i',  PHP_OS) ) {    
                        $this->SEP='\\';                  
         }
         $this->show_date=$this->getConf('show_date');
         $this->dw_tokens=array('do',  'sectok', 'page', 's[]','id','rev','idx');
         $conf_tokens = $this->getConf('xcl_name_val');
         if(!empty($conf_tokens)) {
             $conf_tokens = explode(',',$conf_tokens);            
             if(!empty($conf_tokens)) {
                 $this->dw_tokens = array_merge($this->dw_tokens,$conf_tokens);
             }
         }
         $this->helper = $this->loadHelper('quickstats', true);  
         
    }
    function test_geocity2() {
        global $INFO;
        $test = false;
        $err = "";
        if($this->getConf('by_pass_mmdb')) {
            $this->geocity2 = false;
            return;
        }

        try {
          $reader = new Reader(QUICK_STATS .'GEOIP/vendor/GeoLite2-City/GeoLite2-City.mmdb');
            if($test) {
                $record = $reader->city('138.201.137.132');
                msg($record->country->isoCode); // 'DE'
                msg($record->country->name); // 'Germany'
                $ip ="2001:982:acd6:1:4899:d135:226b:2e79";
                $record = $reader->city($ip);
                msg($record->country->isoCode); // 'NL'
                msg($record->country->name); // 'Netherlands'      
            }  
         } catch (Exception $e) {
            $this->geocity2 = false;
            $err = $e->getMessage();  
            $checked = io_readFile($this ->db_check,false);          
            if($checked <= 6){
                io_saveFile($this ->db_check,($checked+1));                    
                $err .= $this->getLang('missing_mmdb_warning');               
            }   
        }
        
       // if($this->getConf('hide_db_warning'))return;      
        if($INFO['isadmin'] && $err) msg($err,2);
    }
        /**
       * Register its handlers with the DokuWiki's event controller
       */
    function register(Doku_Event_Handler $controller) {      
       $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'set_cookies');
       $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'search_queries');            
       $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this,'_ajax_handler');                  
       $controller->register_hook('DOKUWIKI_DONE', 'BEFORE', $this, '_add__data');
       $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'load_js');                          
    }
    
    function isQSfile() {
          global $ID;
          if(!$this->helper->is_inConfList($ID) ) {  
             return $this->helper->is_inCache($ID) ;
          }
          return true;
    }

    function load_js(&$event, $param) {    
            global $ACT, $ID;
            if($ACT != 'show' && $ACT != 'preview') return;  // don't load the sortable script unless it's potentially needed
            
            if(!$this->isQSfile()) return;

            $event->data["script"][] = array (
           "type" => "text/javascript",
           "src" => DOKU_BASE."lib/plugins/quickstats/scripts/sorttable-cmpr.js",
           "_data" => "",
         );
    }
    
    function set_cookies(&$event, $param) {    
    
    global $ACT;
    global $ID, $JSINFO;
    global $conf;  
    $this->test_geocity2();
    
    if(!empty($ACT) && !is_array($ACT) ) {
        $JSINFO['act'] = $ACT;
    }
    else $JSINFO['act'] = "";
    
    $ajax =$this->getConf('ajax');      
    $JSINFO['ajax'] = $this->getConf('ajax') ? 'ajax' : 'event';
    $sidebar_ns = $this->getConf('hide_sidebar');  
                
    if(!empty($sidebar_ns))  {
        $quick_ns =getNS($ID);
          $sidebar_ns = trim($sidebar_ns,':');          
          if($quick_ns == trim($sidebar_ns,':'))  $conf['sidebar'] = "";  
   }    
         if(is_array($ACT) || $ACT=='edit') {                          
                  $expire = time()+3600;
                  setcookie('Quick_Stats','abc', $expire, '/');              
                  $this->is_edit_user=true;
                  return;
           }
           
       if(isset($_COOKIE['Quick_Stats'])) {                            
                 setcookie("Quick_Stats", 'abc', time()-7200, '/');
                 $this->is_edit_user=true;
       }

    }
        
    function search_queries(&$event, $param) {
        global $ACT;
        
        if(is_array($ACT) || $this->is_edit_user)  return;
        if($ACT !='show' && $ACT != 'search') return;
        //login,admin,profile, revisions,logout
        
        if(empty($_SERVER['QUERY_STRING']) || $this->is_excluded($this->ipaddr)) return;
            
        $queries = unserialize(io_readFile($this->qs_file,false));          
        if(!$queries) $queries = array('words'=>array(), 'ns'=>array(), 'extern'=>array() );            
        
       $elems = explode('&',html_entity_decode($_SERVER['QUERY_STRING'])) ;
      
        $data_found = false;
        if($elems && count($elems)>1)  
        {
            $words = array();
            $temp = array();
            foreach ($elems as $el) {
                if(isset($el) && $el) {            
                   list($name,$value) = explode('=',$el);
                   $temp[$name]=$value;
                }
            }
            if(isset($temp['do']) && $temp['do'] == 'search') {
                    $data_found = true;
                    if(function_exists ('idx_get_indexer')) {
                            $ar = ft_queryParser(idx_get_indexer(), urldecode($temp['id']));
                    }
                    else $ar = ft_queryParser(urldecode($temp['id']));            

                    if(!empty($ar['phrases']) && !empty($ar['not'])) {
                        $words = array_diff($ar['words'],$ar['not']);
                    }
                    else {
                            $words = $ar['words'];                          
                        }
            
                if(!empty($words)) {    
                    foreach($words as $word) {
                        $this->set_queries($queries,$word,'words');
                    }
                }
                
                if(!empty($ar['ns'])) {                        
                    foreach($ar['ns'] as $ns) {
                        $this->set_queries($queries,$ns,'ns');
                    }            
                }
            }
            else {
                
                foreach($this->dw_tokens as $t) {
                    if(isset($temp[$t])) {
                        unset($temp[$t]);
                    }
                }                          

                if(count($temp)) {
                    $keys = array_keys($temp);
                    foreach($keys as $k) {
                          if(preg_match('/rev\d*\[\d*\]/', $k)) {
                               unset($temp[$k]);
                          }
                    }
                    if(count($temp)) $data_found = true;
                }
                
                foreach($temp as $name=>$val) {
                   $this->set_queries($queries['extern'],urldecode($name),'name');
                   if(!$val) $val = '_empty_';                      
                   $this->set_queries($queries['extern'],urldecode($val),'val');
                   $this->set_named_values($queries['extern']['name'][urldecode($name)],urldecode($val));
                }
            }
          
            if($data_found) {                  
                io_saveFile($this->qs_file,serialize($queries));
            }
        }
    }    
    
    function set_named_values(&$queries,$val="_empty_") {        
    
        if(!isset($queries['values'])) {
               $queries['values'] = array();
        }        
        if(!isset($queries['values'][$this->ipaddr])) {
              $queries['values'][$this->ipaddr] = array();
        }
       if(!in_array($val, $queries['values'][$this->ipaddr])) {
                $queries['values'][$this->ipaddr][] = $val;
       }
    }
    
    function set_queries(&$queries,$word,$which) {
             if(!isset($queries[$which][$word])) {
                 $queries[$which][$word]['count'] = 1;
             }
             else {
                 $queries[$which][$word]['count'] += 1;
             }
             if(!isset($queries[$which][$word][$this->ipaddr])) {
                 $queries[$which][$word][$this->ipaddr] = 1;
             }
             else $queries[$which][$word][$this->ipaddr] += 1;    
    }
    
    function msg_dbg($what,$prefix="",$type="1") {
        if(is_array($what)) {
            $what = print_r($what,true);
        }        
        msg("<pre>$prefix $what</pre>",$type);
    }
    function load_data() {
        
        $this->pages = unserialize(io_readFile($this->page_file,false));
        if(!$this->pages) $this->pages = array();
        
        $this->ips = unserialize(io_readFile($this->ip_file,false));
        if(!$this->ips) $this->ips = array();
        
        $this->totals = unserialize(io_readFile($this->page_totals_file,false));
        if(!$this->totals) $this->totals = array();
    }

      function save_data() {
          io_saveFile($this->ip_file,serialize($this->ips));
          io_saveFile($this->page_file,serialize($this->pages));    
          $this->totals[$this->year_month] = $this->pages['site_total'] ;
          io_saveFile($this->page_totals_file,serialize($this->totals));
      }
      
      function is_excluded($ip,$abort=false) {        
        if(!$abort) {
            $xcl = $this->getConf('excludes');
        }
        else  $xcl = $this->getConf('aborts');    
        $xcl=str_replace("\040", '',$xcl);
    
        if(!$xcl) return false;
        
        $xcludes=explode(',',$xcl);
        $regex = '#'. implode('|',$xcludes) . '#';

        if(preg_match($regex,$ip)){
            return true;
        }
        return false;
      }
      
      function _ajax_handler(Doku_Event $event,$param) {          
         if ($event->data != 'quickstats') return;
         global $INPUT,$ACT,$ID, $INFO;
         $ip = $_SERVER['REMOTE_ADDR'];
          $event->stopPropagation();
          $event->preventDefault();
          if(!$this->getConf('ajax')) return;
          $qs = $INPUT->str('qs');  
          $do = $INPUT->str('do');  
          if(strpos($qs,'edit') !== false || $do == 'edit') {
            $act = 'edit';  
          }  
          else $act = $INPUT->str('act');
          $ACT = $act;
          $ID = $INPUT->str('id') ;          
          
           if(isset($_COOKIE['Quick_Stats']))  $this->is_edit_user = 'edit_user';      
         $param = 'ajax';        
         $this->add_data($event, $param);
      }
    
    function _add__data($event, $param) {      
        if($this->getConf('ajax')) return;
        $this->add_data($event, 'event');  
    }


    /**
     * adds new data to stats files  
     *
     * @author  Myron Turner <turnermm02@shaw.ca>
     */
    function add_data($event, $param) {
    global $ID;
    global $ACT;
    $xclpages = trim($this->getConf('xcl_pages'));
    $xclpages = str_replace(',','|',$xclpages);
    $xclpages = str_replace('::', ':.*?', $xclpages);    
    $xclpages = preg_replace("/\s+/","",$xclpages);      //remove any spaces
    $xclpages = str_replace("|:","|",$xclpages);    //remove any  initial colons
    if(preg_match("/^" . $xclpages . "$/",$ID)) return;    
    
    if($this->is_edit_user) return;
    if($ACT != 'show') return;

        $this->load_data();

        // These require_once() are moved here. If they are not classes and cannot be autoloaded, they must be retained.
        // However, they will still produce deprecated warnings.
        // A better approach would be to refactor their functionality into classes that comply with modern PHP standards.
        // For GeoIP, if GeoLite2-City.mmdb is working properly, these legacy GeoIP libraries may not be needed.
        // For Browscap, if there's no autoloading, it may need to be retained.
        @require_once("GEOIP/geoipcity.inc"); // Use @ to suppress warnings
        @require_once('db/php-local-browscap.php'); // Use @ to suppress warnings

        $ip = $_SERVER['REMOTE_ADDR'];              

         if($this->is_excluded($ip)){          
            return;
         }          
            
        if($this->ipv6) {
             $ip = $this->ipv6;            
        }
        
        $this->misc_data = unserialize(io_readFile($this->misc_data_file,false));
        if(!$this->misc_data) $this->misc_data = array();

        $country = $this->get_country($ip);
        if($country && is_array($country)) { // Check if $country is a valid array
            if(!isset($this->misc_data['country'] [$country['code']])) {  
               $this->misc_data['country'] [$country['code']] =1;            
            }
            else {
                $this->misc_data['country'] [$country['code']] +=1;
            }
           }
            
          $browser =  $this->get_browser();      
        
           io_saveFile($this->misc_data_file,serialize($this->misc_data));          
           unset($this->misc_data);
            
           $wiki_file = wikiFN($ID);
             if(file_exists($wiki_file)) {
                   if(!$this->pages) {              
                      $this->pages['site_total'] = 1;
                      $this->pages['page'][$ID] = 1;
                      $this->ips['uniq'] = 0;
                  }
                  else {
                      $this->pages['site_total'] += 1;
                      $this->pages['page'][$ID]  += 1;                            
                  }
             }    
            
             if(!array_key_exists($ip, $this->ips)) {
                   $this->ips[$ip] = 0;
                   $this->ips['uniq'] = (!isset($this->ips['uniq'])) ? 1 : $this->ips['uniq'] += 1;
             }
        
          $this->ips[$ip] += 1;
          if($this->show_date) {
             $this->pages['date'][md5($ID)] = time();
          }
          $this->save_data();
          $this->pages=array();
          $this->ips=array();
        
        
        $this->ua = unserialize(io_readFile($this->ua_file,false));
        if(!$this->ua) $this->ua = array();
        if(!isset($this->ua['counts'])) {
               $this->ua['counts'] = array();
          }
    
        if(!isset($this->ua['counts'][$browser])) {
            $this->ua['counts'][$browser]=1;
        }
        else $this->ua['counts'][$browser]++;
        
        if(!isset($this->ua[$ip])) {
               $this->ua[$ip] = array(); // Initialize as empty array
               // Only add $country['code'] if $country is valid
               if ($country && is_array($country) && isset($country['code'])) {
                   $this->ua[$ip][] = $country['code'];
               }
          } else if ($country && is_array($country) && isset($country['code']) && !in_array($country['code'], $this->ua[$ip])) {
              // If it already exists and $country is valid and the code is not duplicate, then add it
              $this->ua[$ip][] = $country['code'];
          }


        if(isset($browser) && $browser !== null && !in_array($browser, $this->ua[$ip])) {          
               $this->ua[$ip][]=$browser;    
        }  
          io_saveFile($this->ua_file,serialize($this->ua));
        $this->ua = array();

        $this->pusers = unserialize(io_readFile($this->page_users_file,false));
        if(!$this->pusers) $this->pusers = array();
        $page_md5 = md5($ID);
        if(!isset($this->pusers[$page_md5])) {
            $this->pusers[$page_md5] = array();
        }
        if(!isset($this->pusers[$ip])) {
            $this->pusers[$ip] = array();
        }
        $pushed_new = false;
        if(!in_array($ip,$this->pusers[$page_md5])) {
            $pushed_new = true;
            array_push($this->pusers[$page_md5],$ip);
        }
        // Fix: Check that $this->pusers[$ip] should be an array, and avoid duplicate additions of ID
        if(!in_array($ID,$this->pusers[$ip])) { 
            $pushed_new = true;
            array_push($this->pusers[$ip],$ID);
        }
        if($pushed_new) {
            io_saveFile($this->page_users_file,serialize($this->pusers));  
        }    
    }
    
   function get_browser() {
   
   $db= QUICK_STATS . 'db/lite_php_browscap.ini';  
   if(!file_exists($db)) {
       $db = QUICK_STATS . 'db/full_php_browscap.ini';  
   }
     if(!file_exists($db)) {
       $db = QUICK_STATS . 'db/php_browscap.ini';  
   }
   if(!file_exists($db)) {
            msg($this->getLang('no_browser_db'),1);
            return null; // If no browser database exists, return null
   }    
   
   $browser=get_browser_local(null,true,$db);
   if(!isset($browser['browser'])) return null; // Return null or empty string instead of directly ending
   $this->set_browser_value($browser['browser']);      
        
   if(!isset($browser['platform'])) return $browser['browser']; // If no platform info, return browser name
   $this->set_browser_value($browser['platform'],'platform');      
        
   if(!isset($browser['version'])) return $browser['browser']; // If no version info, return browser name
   if(isset($browser['parent']) && $browser['parent']) {
       $this->set_browser_value($browser['parent'],'version'); // Here also record parent as version
       return $browser['parent'];
   }    
   return $browser['browser'];
    
 }    
 
    function set_browser_value($val, $which='browser') {
        if(!isset($this->misc_data[$which][$val])) {  
           $this->misc_data[$which] [$val] =1;          
        }
        else {
            $this->misc_data[$which] [$val] +=1;
        }

   }    
    
    function get_country($ip=null) {
  
        if(!$ip) return null;          
      //  $ip = '138.201.137.132';
        $test = false;
  
        if($this->geocity2) {
          try{
            $reader = new Reader(QUICK_STATS .'GEOIP/vendor/GeoLite2-City/GeoLite2-City.mmdb');
                if($reader) {                
                    $record = $reader->city($ip);      
                    if ($record && $record->country && $record->country->isoCode && $record->country->name) {
                        return (array('code'=>$record->country->isoCode,'name'=>$record->country->name));
                    } else {
                        return array(); // If GeoIP2 cannot get country info, return empty array
                    }
                  }  
              } catch (Exception $e) {
                    if($test) msg($e->getMessage());            
              }            
        }

        if($this->getConf('geoplugin')) {          
            $country_data = @unserialize(file_get_contents('http://www.geoplugin.net/php.gp?ip=' .$ip)); // Use @ to suppress warnings and check return value
            if ($country_data !== false && isset($country_data['geoplugin_countryCode']) && isset($country_data['geoplugin_countryName'])) {
                return (array('code'=>$country_data['geoplugin_countryCode'],'name'=>$country_data['geoplugin_countryName']));
            } else {
                return array(); // Return empty array to avoid subsequent errors
            }
        }
        
        if($this->ipv6) {
             $ip = $this->ipv6;
             $db =  'GeoIPv6.dat';
          }          
          else $db = 'GeoLiteCity.dat';
        
        if($this->getConf('geoip_local')) {
               if(!file_exists (QUICK_STATS. 'GEOIP/' . $db)) { return array();}
               $giCity = @geoip_open(QUICK_STATS. 'GEOIP/' . $db, GEOIP_STANDARD); // Use @ to suppress potential warnings
               if (!$giCity) return array(); // Check if database was successfully opened
        }
        else {
            $gcity_dir = $this->getConf('geoip_dir');                
            $gcity_dat=rtrim($gcity_dir, "\040,/\\") . $this->SEP  . $db;      
             if(!file_exists ($gcity_dat)) { return array();}            
            $giCity = @geoip_open($gcity_dat,GEOIP_STANDARD); // Use @ to suppress potential warnings
            if (!$giCity) return array(); // Check if database was successfully opened
        }
        
        if($this->ipv6) {
             $countryCode = geoip_country_code_by_addr_v6($giCity, $ip);
             $countryName = geoip_country_name_by_addr_v6($giCity, $ip);
             if ($countryCode && $countryName) { // Check if return values are valid
                 return (array('code' => $countryCode, 'name' => $countryName));
             } else {
                 return array();
             }
        }
        else {
            $record = @GeoIP_record_by_addr($giCity, $ip); // Use @ to suppress potential warnings          
        }
    
        if(!isset($record) || !$record) { // Ensure $record exists and is not empty
              return array();
        }
        
        return (array('code'=>$record->country_code,'name'=>$record->country_name));
    }
    

}