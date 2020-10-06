<?php
   /*
   Plugin Name: NbConvert
   Description: A plugin to add ipynb files to a blog post or page using nbviewer
   Version: 1.0
   Author: Andrew Challis
   Author URI: http://www.andrewchallis.com
   License: MIT
   */

# https://stackoverflow.com/questions/4444475/transform-relative-path-into-absolute-url-using-php
function rel2abs($rel, $base) {
    /* return if already absolute URL */
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

    /* queries and anchors */
    if ($rel[0]=='#' || $rel[0]=='?') return $base.$rel;

    /* parse base URL and convert to local variables:
       $scheme, $host, $path */
    extract(parse_url($base));

    /* remove non-directory element from path */
    $path = preg_replace('#/[^/]*$#', '', $path);

    /* destroy path if relative url points to root */
    if ($rel[0] == '/') $path = '';

    /* dirty absolute URL */
    $abs = "$host$path/$rel";

    /* replace '//' or '/./' or '/foo/../' with '/' */
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}

    /* absolute URL is ready! */
    return $scheme.'://'.$abs;
}

function nbconvert_get_github_raw_url($url) {
  // converts the github url to the github raw url.
  $firstslash = strpos($url, '/');
  $protocol = substr($url, 0, $firstslash-1);
  $clean_url = substr($url, $firstslash+2);
  $clean_url_noblob = str_replace('/blob', '', $clean_url);
  $github_raw_prefix = substr($clean_url_noblob, strpos($clean_url_noblob, '/'));
  $github_raw_url = $protocol . "://raw.githubusercontent.com" . $github_raw_prefix;
  
  return $github_raw_url;
}

function nbconvert_handler($atts) {
  //run function that actually does the work of the plugin
  $nb_output = nbconvert_function($atts);
  //send back text to replace shortcode in post
  return $nb_output;
}

function nbconvert_get_most_recent_git_change_for_file_from_api($url) {

  $url_list = explode('/', $url);

  $owner = $url_list[3];
  $repo = $url_list[4];
  $branch = $url_list[6];
  $path = implode("/", array_slice($url_list, 7));

  $request_url = 'https://api.github.com/repos/'.$owner.'/'.$repo.'/commits/'.$branch.'?path='. $path.'&page=1';

  $context_params = array(
    'http' => array(
      'method' => 'GET',
      'user_agent' => 'Bogus user agent',
      'timeout' => 1
    )
  );

  
  $res = file_get_contents($request_url, FALSE, stream_context_create($context_params));

  $datetime = json_decode($res, true)['commit']['committer']['date'];

  $max_datetime = strtotime($datetime);
  $max_datetime_f = date('d/m/Y H:i:s', $max_datetime);

  return $max_datetime_f;
}

/*
function get_most_recent_git_change_for_file($url) {
  
  $url_list = explode('/', $url);
  $url_list[5] = 'blame';
  $new_url = implode("/", $url_list);
  //Load the HTML page
  $html = file_get_contents($new_url);
  
  //Create a new DOM document
  $dom = new DOMDocument;
  libxml_use_internal_errors(true);
  $dom->loadHTML($html);
  
  // Get all time-ago tags
  $time_agos = $dom->getElementsByTagName('time-ago');

  $mostRecent= 0;
  foreach($time_agos as $time_ago){
    $datetime = $time_ago->getAttribute('datetime');
    $curDate = strtotime($datetime);
    if ($curDate > $mostRecent) {
       $mostRecent = $curDate;
    }
  }

  $max_date = date('d/m/Y H:i:s', $mostRecent);
  return $max_date;
  
}
*/

function nbconvert_function($atts) {
  //process plugin
  extract(shortcode_atts(array(
        'url' => "",
     ), $atts));

  $clean_url = preg_replace('#^https?://#', '', rtrim($url,'/'));
  $html = file_get_contents("https://nbviewer.jupyter.org/url/" . $clean_url);
  $github_raw_url = nbconvert_get_github_raw_url($url);
  $nb_output = nbconvert_getHTMLByID_fix_img('notebook-container', $html, $github_raw_url);

  $last_update_date_time = nbconvert_get_most_recent_git_change_for_file_from_api($url);

  $converted_nb = '<div class="notebook">
    <div class="nbconvert-labels">
      <label class="github-link">
        <a href="'.$url.'" target="_blank">Check it out on github</a>
        <label class="github-last-update"> Last updated: '.$last_update_date_time.'</label>
      </label>
      </div>
    <div class="nbconvert">'.$nb_output.'
    </div>
  </div>';

  //send back text to calling function
  return $converted_nb;
}

function nbconvert_innerHTML(DOMNode $elm) {
  $innerHTML = '';
  $children  = $elm->childNodes;

  foreach($children as $child) {
    $innerHTML .= $elm->ownerDocument->saveHTML($child);
  }

  return $innerHTML;
}

function nbconvert_getHTMLByID_fix_img($id, $html, $baseurl) {
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    $node = $dom->getElementById($id);
    if ($node) {
        $imgnodes = $node->getElementsByTagName('img');
        foreach($imgnodes as $imgnode) {
            $imgurl = $imgnode->getAttribute('src');
            if($imgurl) {
                $absimgurl = rel2abs($imgurl, $baseurl);
                $imgnode->setAttribute('src', $absimgurl);
            }
        }
        $inner_output = nbconvert_innerHTML($node);
        return $inner_output;
    }
    return FALSE;
}

function nbconvert_enqueue_style() {
	wp_enqueue_style( 'NbConvert', plugins_url( '/css/nbconvert.css', __FILE__ ));
}
add_action( 'wp_enqueue_scripts', 'nbconvert_enqueue_style' );
add_shortcode("nbconvert", "nbconvert_handler");
