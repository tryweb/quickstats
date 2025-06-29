<?php
/**
 *
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author    Myron Turner <turnermm02@shaw.ca>
 */

 if(!defined('DOKU_INC')) die();
/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */

class admin_plugin_quickstats extends DokuWiki_Admin_Plugin {

    private $output = '';
    private $helper;
    private $cache;
    private $deletions;
	private $to_confirm;
    private $cc_arrays;
    private $countries;
    private $user_agents;
    private $meta_path;
    private $page_totals;
    private $uniqIPTotal;
    private $uniqIPCurrent;
    private $page_accessesTotal=0;
    private $page_accessesCurrent=0;
    private $script_max_time = 0;
     function __construct() {

       $this->helper = $this->loadHelper('quickstats', true);
       $this->cache = $this->helper->getCache();
       $this->cc_arrays = $this->helper->get_cc_arrays();
       $this->meta_path = $this->helper->metaFilePath(true) ;
       $this->page_totals = unserialize(io_readFile($this->meta_path .  'page_totals.ser'));
       if(!$this->page_totals) $this->page_totals = array();
       if(!empty($this->page_totals)) {
           foreach($this->page_totals as $ttl) {
              $this->page_accessesTotal+=$ttl;
              $this->page_accessesCurrent=$ttl;
           }
           $this->misc_data_setup();
           $this->uniq_ip();
       }
        //$this->script_max_time = ini_get('max_execution_time');
		 $this->script_max_time = $this->getConf('max_exec_time') ? $this->getConf('max_exec_time') : 60;
     }

     /*
     * Create a list of countries accessed during last 6 months, for countries Select
     */
     function misc_data_setup() {

         $this->countries = array();
         $country_codes = array();
         $this->user_agents = array();

         $data_dirs = array_reverse(array_keys($this->page_totals));
         if(count($data_dirs) > 6) {
            $data_dirs = array_slice($data_dirs,0,6);
         }

         $ns_prefix = "quickstats:";
         foreach($data_dirs as $dir) {
             $ns =  $ns_prefix .  $dir . ':';
             $misc_data_file = metaFN($ns . 'misc_data' , '.ser');
             $misc_data = unserialize(io_readFile($misc_data_file,false));
             if(!empty($misc_data)) {
                 if(!empty($misc_data['country'])) {
                     $country_codes = array_merge ($country_codes, array_keys($misc_data['country']));
                }
                 if(!empty($misc_data['browser'])) {
                     $this->user_agents = array_merge ($this->user_agents, array_keys($misc_data['browser']));
                   //  $this->user_agents = array_merge ($this->user_agents, array_keys($misc_data['version']));
                }
            }
         }
         foreach($country_codes as $cc) {
             if($cc) {
                $this->countries[$cc]=$this->cc_arrays->get_country_name($cc) ;
             }
         }
         asort($this->countries);
         $this->user_agents = array_unique($this->user_agents);
         natcasesort($this->user_agents);


     }

     function uniq_ip() {
            $dirs = array_keys($this->page_totals);
            $current_dir = array_pop($dirs);
            $ns_prefix = "quickstats:";
            $uniq_data_file = metaFN($ns_prefix . 'uniq_ip' , '.ser');

            if(file_exists($uniq_data_file) && !$this->getConf('rebuild_uip')) {
                $uniq_data = unserialize(io_readFile($uniq_data_file,false));
            }
            else if(count($dirs) > 0) {
                $uniq_data = array();
                foreach($dirs as $dir) {
                    $ns =  $ns_prefix .  $dir . ':';
                    $ip_file = metaFN($ns . 'ip' , '.ser');
                    $ip_data = unserialize(io_readFile($ip_file,false));
                    if(empty($ip_data)) {
                       $ip_data = array();
                     }
                     else {
                         unset($ip_data['uniq']);
                    }
                    $ip_data = array_keys($ip_data);
                    $uniq_data = array_merge ($uniq_data , $ip_data);
                }
                unset($uniq_data['uniq']);
                unset($uniq_data['last']);
                $uniq_data = array_unique($uniq_data);
                $uniq_data['uniq'] = count($uniq_data);
                $uniq_data['last'] = $dir;
                io_saveFile($uniq_data_file,serialize($uniq_data));
            }
            else {
                $uniq_data = array();
            }

            $ns =  $ns_prefix .  $current_dir . ':';
            $ip_file = metaFN($ns . 'ip' , '.ser');
            $ip_data = unserialize(io_readFile($ip_file,false));
            $this->uniqIPCurrent=$ip_data['uniq'];

            $uniq_data = array_unique(array_merge ($uniq_data , array_keys($ip_data)));
            $uniq_data['uniq'] = count($uniq_data);
            $this->uniqIPTotal = $uniq_data['uniq'];
            if($current_dir != $uniq_data['last'] ) {
               $uniq_data['last'] = $current_dir;
               io_saveFile($uniq_data_file,serialize($uniq_data));
            }



     }

    /**
     * handle user request
     */
    function handle() {
      if (!isset($_REQUEST['cmd'])) return;   // first time - nothing to do

      $this->output ="";

      $this->deletions = array();
      if (!checkSecurityToken()) return;
      if (!is_array($_REQUEST['cmd'])) return;

      switch (key($_REQUEST['cmd'])) {
        case 'delete' :
           if(isset($_REQUEST['del']) && is_array($_REQUEST['del']) && !empty($_REQUEST['del'])) {
		    $this->deletions = $_REQUEST['del'];
			$this->to_confirm = implode(',',array_keys($this->deletions));
            }
            else {
               $this->deletions = array();
               $this->to_confirm = array();
            }
			 break;
        case 'confirm' :
		   $this->cache=$this->helper->pruneCache($_REQUEST['confirm'],$_REQUEST['del']);
		   break;

      }



    }

    /**
     * output appropriate html
     */
    function html() {
      global $INFO;
      echo '<div id="qs_general_intro">' . "\n";
      echo $this->locale_xhtml('general_intro') . "\n";
      echo '</div>' . "\n";
      echo '<button class="button" onclick="toggle_panel(\'qs_cache_panel\');">' . $this->getLang("btn_prune") . '</button>' . "\n";
      echo '&nbsp;&nbsp;<button class="button" onclick="toggle_panel(\'quick__stats\');">' . $this->getLang("btn_queries") . '</button>' . "\n";
      echo '&nbsp;&nbsp;<button class="button" id="qs_query_info_button"  onclick="qs_open_info(\'qs_query_intro\');">' . $this->getLang("btn_qinfo") . '</button>' . "\n";
      echo '&nbsp;&nbsp;<button class="button" id="qs_query_info_button"  onclick="qs_download_GeoLite(\'' . $this->getConf('geoip_local')  . '\');" title = "download Maxmind Database">' . $this->getLang('btn_download') . '</button>' . "\n";
      if($INFO['client'] == 'tower' && preg_match("/turnermm0(2|3)/", $INFO['userinfo']['mail']))  {
         echo '&nbsp;&nbsp;DB TEST <input type="checkbox"  id="gc2_test">' . "\n";
         echo $INFO['client'] . "\n";
      }
      /* Cache Pruning Panel */
      if(isset($this->deletions) || isset($this->to_confirm)) {
         $qs_display = ' style="display:block; "';
      }
      else  $qs_display = "";

      echo '<div ' . $qs_display . ' id="qs_cache_panel">' . "\n";

      echo $this->locale_xhtml('intro') . "\n";
      global $ID;
      echo '<form action="'.wl($ID).'" method="post">' . "\n";

      // output hidden values to ensure dokuwiki will return back to this plugin
      echo '  <input type="hidden" name="do"   value="admin" />' . "\n";
      echo '  <input type="hidden" name="page" value="'.$this->getPluginName().'" />' . "\n";
	  echo '  <input type="hidden" name="confirm" value="'.$this->to_confirm .'" />' . "\n";
      formSecurityToken();

      echo '<table cellspacing = "4">' . "\n";
      foreach($this->cache as $key=>$id) {
           $this->get_item($key,$id);
      }
      echo '</table>' . "\n";

      echo '  <input type="submit" name="cmd[delete]"  class="button" value="'.$this->getLang('btn_delete').'" />' . "\n";
      echo '  <input type="submit" name="cmd[restore]"  class="button" value="'.$this->getLang('btn_restore').'" />' . "\n";
      echo '  <input type="submit" name="cmd[confirm]"  class="button" value="'.$this->getLang('btn_confirm').'" />' . "\n";

      echo '</form></div>' . "\n";

         /* Stats Panel */
      $today = getdate();
      echo '<div id="quick__stats" class="quick__stats">' . "\n";
      echo '<div class="qs_query_intro" id="qs_query_intro">' . $this->locale_xhtml('query') . "\n";
      echo '<button class="button" onclick="qs_close_panel(\'qs_query_intro\');">' . $this->getLang('btn_close_info') . '</button>' . "\n";
      echo '</div>' . "\n";

      echo '<div id="qs_admin_form_div"><p>&nbsp;</p><p><form id="qs_stats_form" action="javascript:void 0;">' . "\n";
      echo '<input type="hidden" name="meta_path" value="'.$this->meta_path.'" />' . "\n";
	  echo '<input type="hidden" id="qs_script_max_time" name="qs_script_max_time" value="'.$this->script_max_time.'" />' . "\n";

      echo '<table  border="0"  STYLE="border: 1px solid black" cellspacing="0">' . "\n";

      //header row
      echo '<tr><th class="thead">&nbsp;' . $this->getLang('label_qs_pages') .' &nbsp;</th><th class="thead" colspan="1">' . $this->getLang('label_date')  .'</th>' . "\n";

      echo '<td></td><th class="thead">' . $this->getLang('user_agent') .'</th><td></td><th class="thead">' . $this->getLang('label_search') . '</th>' . "\n";
      echo '<th class="thead">' . $this->getLang('country') .'</th></tr>' . "\n";

      /* Row 1  */
      //row 1/col1 files popups select
      echo '<tr><td rowspan="5" valign="top" class="padded"><select name="popups" id="popups" size="6" onchange="onChangeQS(this);">' . "\n";
      $this->get_Options('popups');
      echo '</select></td>' . "\n";

      //row 1 col2 months select
      echo '<td rowspan="5" valign="top" class="padded" nowrap>&nbsp;<select name="month" multiple id="month" size="6">' . "\n";
      $this->get_Options('months',$today['mon']) ;


      echo '</select></td><td rowspan="6" class="divider"></td><th class="padded" rowspan="6"nowrap valign="top">' . "\n";
     //row 1 col3  browser/useragent
     echo '<select size="6" name="user_agent" id="user_agent">' . "\n";
     $this->get_Options('ua') ;
     echo '</select>' . "\n";
     echo '<br /><a href="javascript:qs_agent_search();" style="text-decoration:underline; font-weight:normal;line-height:200%;">' . $this->getLang('search_link') .'</a><input type ="text" id="other_agent"></td>' . "\n";
     echo '</th><td rowspan="6" class="divider"></td>' . "\n";
      //row 1 col4 IP
      echo '<td class="padded" nowrap>&nbsp;' . $this->getLang('label_ip') . ':&nbsp;<input type="text" name = "ip" id="ip" size="16" value=""' .NL .'</td>' . "\n";

      //row 1 col5 Countries
      echo '<td rowspan="5" align="top" class="padded" nowrap>&nbsp;<select name="country_names" id="country_names" size="6">' . "\n";
      $this->get_Options('country') ;
      echo '</select></td>' . "\n";
      echo '</tr>' . "\n";

       /* ROW 2 */
       // col 1 -- below row 1 col 4
      echo '<tr><td class="padded" nowrap>&nbsp;' . $this->getLang('label_page') . ':&nbsp;<input type="text" name = "page" id="page" size="36" value=""</td></tr>' . "\n";
       /* ROW 3 */
      // col 1 -- below row 2 col 1
      echo '<tr><td class="padded  place_holder">&nbsp;' . $this->getLang('label_brief'). ': <input type="checkbox" id="qs_p_brief" name="qs_p_brief"></td></tr>' . "\n";
      /* ROWS 4-5: under row 3 col1/row 1 col 4 */
      echo '<tr><td class="padded  place_holder">&nbsp;</td></tr>' . "\n";

      echo '<tr><td class="padded" valign="bottom" nowrap><b>Priority:</b><br />' . "\n";
      echo $this->getLang('label_page') .'<input type="radio" checked value="page" name="qs_priority" id="qs_priority_page">' . "\n";
      echo '&nbsp;IP <input type="radio" value="ip" name="qs_priority" id="qs_priority_ip">' . "\n";
      echo '&nbsp;' . $this->getLang('country') .'<input type="radio" value="country" name="qs_priority" id="qs_priority_country">' . "\n";
      echo '&nbsp;' . $this->getLang('user_agent')  . ':<input type="radio" value="agent" name="qs_priority" id="qs_priority_agent"></td></tr>' . "\n";
      //echo 'country, user agent</td></tr>' . "\n";

     /*ROW 6 */
      echo '<tr><td class="padded nowrap">&nbsp;</td>' . "\n";
      echo '<td class="padded">&nbsp;' . $this->getLang('year')  . '&nbsp;<input type="text"  onchange="qs_check_year(this);"  name="year" id="year" size="4" value="' . $today['year'] . '">' .NL .'</td>' . "\n";

      echo '<td class="padded" valign="bottom" >&nbsp;' . $this->getLang('label_no_secondary') . ':&nbsp;<input type="checkbox" checked id="qs_ignore"></td>' . "\n";
      echo '<td class="padded" style="padding-top:2px;"><a href="javascript:qs_country_search();" style="text-decoration:underline">' . $this->getLang('search_link') .'</a> <input type="text" value ="" id="cc_extra" name="cc_extra" size="24"></td>' . "\n";
      echo '</table>' . "\n";

      echo '<p><input type="submit" onclick="getExtendedData(this.form,\''. DOKU_INC . '\');"  class="button" value="'.$this->getLang('btn_submit_query').'" />' . "\n";
      echo '&nbsp;<input  type="reset" class="button" value="' . $this->getLang('btn_reset') . '">' . "\n";
      echo '&nbsp;&nbsp;&nbsp;&nbsp;<span class="status">[ <b>' . $this->getLang('label_uniq_ip')  . '</b>&nbsp;&nbsp;' . $this->getLang('label_total') . ': ' .  $this->uniqIPTotal . '&nbsp;&nbsp;' . $this->getLang('label_current_month') . ': ' . $this->uniqIPCurrent .' ]' . "\n";
      echo '&nbsp;&nbsp;&nbsp;[ <b>' . $this->getLang('label_page_access') . '</b>&nbsp;&nbsp;' . $this->getLang('label_total') . ': ' . $this->page_accessesTotal. '&nbsp;&nbsp;' . $this->getLang('label_current_month') . ': ' . $this->page_accessesCurrent.  ' ]</span>' . "\n";
      echo '</p></form></p></div>' . "\n";

      echo '<p>&nbsp;</p><div id="extended_data"></div>' . "\n";
      echo '</div>' . "\n";
      echo '<p>&nbsp;</p><div id="download_results"></div>' . "\n";
      //$this->debug();

    }

	function debug() {
	 //   return;
	    echo '<p><pre>' . "\n";
        echo htmlspecialchars($this->output) . "\n";

	   if($this->deletions && count($this->deletions)) {
	       $this->deletions_str = print_r($this->deletions,true);
     	   echo $this->deletions_str . "\n";
		}

        echo '</pre></p>' . "\n";

	}

    function get_item($key,$id) {
        $checked = "";
        $bg_color = "";
        if(isset($this->deletions) && array_key_exists($key,$this->deletions)) {
              $checked='checked';
              $bg_color = "style = 'background-color: #dddddd;'";
        }

       $key1 = $key . '_1';
        echo "<tr><td $bg_color id='$key1'>&nbsp;<input type='checkbox' name='del[$key]' value='$id' onclick='uncheck(\"$key\");' $checked>&nbsp;</td><td $bg_color id='$key'>&nbsp;$id&nbsp;</td></tr>" . "\n";
    }

    function get_Options($which,$selected_month=1) {
        if($which == 'months') {
            $months = array('Jan'=>1, 'Feb'=>2, 'Mar'=>3, 'Apr'=>4, 'May'=>5, 'Jun'=>6, 'Jul'=>7, 'Aug'=>8, 'Sep'=>9, 'Oct'=>10, 'Nov'=>11, 'Dec'=>12);
            echo "<option value='0'> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; " . NL . "\n";
            foreach ($months as $month=>$value) {
                $selected = "";
                if($value == $selected_month) {
                    $selected = 'selected';
                }
                echo "<option value='$value' $selected>  $month " . NL . "\n";
            }
        }
        else if($which == 'popups') {
            echo "<option value='0' selected> &nbsp;". $this->getLang('click_to_view') . "&nbsp;" . NL . "\n";
            foreach($this->cache as $id) {
                 echo "<option value='$id'> $id" . NL . "\n";
            }
       }
      else if($which == 'country') {
        echo "<option value='0' selected> &nbsp; <b>" . $this->getLang('sel_country') ."</b> &nbsp;" . NL . "\n";
        foreach($this->countries as $cc => $country) {
             echo "<option value='$cc'> $country" . NL . "\n";
        }
      }
     else if($which == 'ua') {
        echo "<option value='0' selected> &nbsp; <b>" . $this->getLang('sel_user_agent') ."</b> &nbsp;" . NL . "\n";
        foreach($this->user_agents as $ua) {
             echo "<option value='$ua'> $ua" . NL . "\n";
        }
     }
    }

}