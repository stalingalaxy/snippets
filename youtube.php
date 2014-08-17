<?php
/** youtube uploader code starts here **/			
/** youtube uploader from wordpress using youtube api 3.0 and Oauth
 * @author tom
 * @version 1.0
 * @authorEmail: tom@codelooms.com
 * 
 */
 function getYoutubeAuthorization(){
	// Call set_include_path() as needed to point to your client library.
	$path = ABSPATH;
	set_include_path(get_include_path() . PATH_SEPARATOR . $path);
	require_once 'Google/Client.php';
	require_once 'Google/Service/YouTube.php';
	


	$OAUTH2_CLIENT_ID = '887893788942-8rd075qipi44mh512kfcjc3ujgehmq14.apps.googleusercontent.com';
	$OAUTH2_CLIENT_SECRET = 'J4faB8-jnMlph93mWFzGM1y3';
	
	$client = new Google_Client();
	$client->setClientId($OAUTH2_CLIENT_ID);
	$client->setClientSecret($OAUTH2_CLIENT_SECRET);
	$client->setScopes('https://www.googleapis.com/auth/youtube');
	$redirect = filter_var('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'],
	    FILTER_SANITIZE_URL);
	$client->setRedirectUri($redirect);
	
	// Define an object that will be used to make all API requests.
	$youtube = new Google_Service_YouTube($client);
	
	if (isset($_GET['code'])) {
	  if (strval($_SESSION['state']) !== strval($_GET['state'])) {
	    $context = 'The session state did not match.';
	  }
	
	  $client->authenticate($_GET['code']);
	  $_SESSION['token'] = $client->getAccessToken();
	  // echo "<script >document.location.href='$redirect';</script>";
	  wp_redirect( $redirect );
	  //header('Location: ' . $redirect);
	  exit;
	}
	if (isset($_SESSION['token'])) {
	  $client->setAccessToken($_SESSION['token']);
	}
	return $client;	
}
// youtube uploader
add_action('media_buttons_context',  'add_youtube_authorization_btn');

function add_youtube_authorization_btn($context) {
	$client = getYoutubeAuthorization();
	if(!$client->getAccessToken()) {
	  // If the user hasn't authorized the app, initiate the OAuth flow
	  $state = mt_rand();
	  $client->setState($state);
	  $_SESSION['state'] = $state;
	
	  $authUrl = $client->createAuthUrl();
  //path to my icon
  	$img = 'youtube-authorize.png';

  //our popup's title
  	$title = 'Youtube Authorization';

  //append the icon
  	$context .= "<a title='{$title}' href='$authUrl'>
      Authorize Youtube</a>";
	} else {
		$context .= "Youtube Authorized...";
	}


  return $context;
}

add_action( 'publish_post', 'save_to_youtube',10,2 );
function save_to_youtube($post_id, $post)
{	
	// Checks whether is post updated or published at first time.

	if ($post->post_date != $post->post_modified) return;
	// $videoPattern = "@\[video(.*)\]\[\/video\]$@";
	$videoArray = array();
	$content = $post->post_content;
	if( has_shortcode($content, "video")){
		$baseUrl = get_bloginfo( "url" );
		$prefix = "video";
		$pattern = '/\[(\[?)(' . $prefix . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/';
		preg_match_all($pattern, ( $content ), $matches, PREG_SET_ORDER);
		foreach( $matches as $match ){
			$atts = shortcode_parse_atts( $match[3] );
			foreach( wp_get_video_extensions()  as $ext ){
				if( isset($atts[$ext])){
					$url = $atts[$ext];
					break; 
				}
			}

			$video = str_replace($baseUrl . "/", "", $url );
			$videoArray[] = $videoPath = ABSPATH . $video;
		}
	}
	// upload to youtube 
	$videoArray = array_unique( $videoArray );

	$postTags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

	foreach( $videoArray as $videoPath ){
		$desc = strip_shortcodes( $post->post_content );
		$desc = strip_tags( $desc );
		if(!file_exists( $videoPath )){
			$_SESSION['yterror'] = "The video doesnot exists / deleted, please check it";
		} else {
			$youtubeVideoId = uploadToYoutube($videoPath, $post->post_title, $desc, implode(",", $postTags) );
			if( isset($_SESSION['ytmesage'])){
				$_SESSION['ytmesage'] = array();	
			}
			$_SESSION['ytmesage'][] = "The video [Youtube Id: $youtubeVideoId] is uploaded to youtube successfully";
		}
	}
}

// function to upload video 
function uploadToYoutube($videoPath, $title, $desc, $tag){
	$client = getYoutubeAuthorization();
	// Define an object that will be used to make all API requests.
	$youtube = new Google_Service_YouTube($client);
	
	if ($client->getAccessToken()) {
	  try{
	    // Create a snippet with title, description, tags and category ID
	    // Create an asset resource and set its snippet metadata and type.
	    // This example sets the video's title, description, keyword tags, and
	    // video category.
	    $snippet = new Google_Service_YouTube_VideoSnippet();
		
	    $snippet->setTitle($title);
	    $snippet->setDescription($desc);
		if( $tag ){
	    	$snippet->setTags($tag);
		}
	
	    // Numeric video category. See
	    // https://developers.google.com/youtube/v3/docs/videoCategories/list 
	    $snippet->setCategoryId("22");
	
	    // Set the video's status to "public". Valid statuses are "public",
	    // "private" and "unlisted".
	    $status = new Google_Service_YouTube_VideoStatus();
	    $status->privacyStatus = "public";
	
	    // Associate the snippet and status objects with a new video resource.
	    $video = new Google_Service_YouTube_Video();
	    $video->setSnippet($snippet);
	    $video->setStatus($status);
	
	    // Specify the size of each chunk of data, in bytes. Set a higher value for
	    // reliable connection as fewer chunks lead to faster uploads. Set a lower
	    // value for better recovery on less reliable connections.
	    $chunkSizeBytes = 1 * 1024 * 1024;
	
	    // Setting the defer flag to true tells the client to return a request which can be called
	    // with ->execute(); instead of making the API call immediately.
	    $client->setDefer(true);
	
	    // Create a request for the API's videos.insert method to create and upload the video.
	    $insertRequest = $youtube->videos->insert("status,snippet", $video);
	
	    // Create a MediaFileUpload object for resumable uploads.
	    $media = new Google_Http_MediaFileUpload(
	        $client,
	        $insertRequest,
	        'video/*',
	        null,
	        true,
	        $chunkSizeBytes
	    );
	    $media->setFileSize(filesize($videoPath));
	
	
	    // Read the media file and upload it chunk by chunk.
	    $status = false;
	    $handle = fopen($videoPath, "rb");
	    while (!$status && !feof($handle)) {
	      $chunk = fread($handle, $chunkSizeBytes);
	      $status = $media->nextChunk($chunk);
	    }
	
	    fclose($handle);
	
	    // If you want to make other calls after the file upload, set setDefer back to false
	    $client->setDefer(false);
		return $status['id'];
	  } catch (Google_ServiceException $e) {
	    $htmlBody = sprintf('<p>A service error occurred: <code>%s</code></p>',
	        htmlspecialchars($e->getMessage()));
			$_SESSION['yterror'] = $htmlBody;
	  } catch (Google_Exception $e) {
	    $htmlBody = sprintf('An client error occurred: <code>%s</code></p>',
	        htmlspecialchars($e->getMessage()));
			$_SESSION['yterror'] = $htmlBody;
	  }
	
	  $_SESSION['token'] = $client->getAccessToken();
	} else {
		$_SESSION['yterror'] = 'Youtube Authorization missing';
	}
}

function checkForYoutubeAuthorization( ){
	$client = getYoutubeAuthorization();
	if(!$client->getAccessToken()) {
	  // If the user hasn't authorized the app, initiate the OAuth flow
	  $state = mt_rand();
	  $client->setState($state);
	  $_SESSION['state'] = $state;
		
	  $authUrl = $client->createAuthUrl();
	  //path to my icon
	$img = 'youtube-authorize.png';
	
	  //our popup's title
	$title = 'Youtube Authorization';
	
	  //append the icon
	$message = "To upload the Video to youtube, you must <a title='{$title}' href='$authUrl'>authorize it</a>";
	$type  = "error";
	} else {
		$message = "Youtube Authorized... The video will be uploaded successfully to youtube";
		$type = "updated";
	}
?>
 <div class="<?php echo $type;?>">
        <p style="font-size: 18px;"><?php echo $message;?></p>
    </div>
<?php 
	if( isset($_SESSION['yterror'] )){		
?>
	<div class="error">
      <p ><?php echo $_SESSION['yterror'];unset($_SESSION['yterror']);?></p>
    </div>
<?php	}
	/// successmessage 
	if( isset( $_SESSION['ytmesage'] )){
?>
	<div class="updated">
      <p ><?php echo implode("<br/>", $_SESSION['ytmesage']); unset($_SESSION['ytmesage']);?></p>
    </div>
<?php		
	}	
}	
add_action( 'admin_notices', 'checkForYoutubeAuthorization', 100, 2 );	

function session_manager_youtube(){
	error_reporting( E_ALL & ~E_NOTICE );	
	if (!session_id()){
			session_start();
	}	
}
add_action('init', 'session_manager_youtube');
add_action('init', 'getYoutubeAuthorization');
/*** youtube uploader code ends here **/	