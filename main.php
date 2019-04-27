<?php
echo "Running at: " . date('m-d-Y H:i:s') . "\n";
// Twitter OAuth
require "vendor/autoload.php";
require_once 'vendor/cosenary/simple-php-cache/cache.class.php';
use Abraham\TwitterOAuth\TwitterOAuth;

// Ensure this runs out of the directory the script is in
chdir(__dir__);

//Format search string, and make it JSON
$search = array (
	'tags' => 'kantai_collection -webm',
	'limit' => '200',
);

// Get our cache
$cache = new Cache('posts');
$files = $cache->retrieve('posts'); // md5s of danbooru posts that have been posted before
$page = $cache->retrieve('page'); // the last page we have pulled from (if there are no new posts)

// Get the first page of posts and see if there are any new ones
$result = getPosts($search);
$result = filterExisting($result, $files);

if ( $result == false ) {
	$i = (!empty($page)) ? $page : 1;
	// No new posts available, go through the history
	while ( $result == false ) {
		echo "No posts available! Trying page " . $i . "\n";
		$search['page'] = $i;
		$result = getPosts($search);
		$result = filterExisting($result, $files);
		if ( $result == false ) {
			$i++;
		}
	}
	$cache->store('page', $i);
}

echo "Number of posts available" . (isset($i) ? " (On page " . $i . ")" : '') . ": " . count($result) . "\n";

// Select first (newest) post, and print it for debug purposes
$post = $result[0];
$filename = 'images/' . $post['md5'].substr($post['large_file_url'], -4);
print_r($post);
echo "\n";

// Get image file
if ( substr($post['large_file_url'], 0, 4) === 'http' ) {
	$url = $post['large_file_url'];
} else {
	$url = 'https://safebooru.donmai.us'.$post['large_file_url'];
}
exec('wget -O ' . $filename . ' ' . $url);

// Post tweet, if successful, add the post to the cache so we don't repost it.
if ( postTweet($post, $filename) == true ) {
	$new[] = $post['md5'];
	$files = array_merge($files, $new);
	$cache->store('posts', $files);
}

function getPosts($search) {
	//Danbooru API Endpoint
	$apiurl = 'https://safebooru.donmai.us/posts.json';

	//JSON encode the search array
	$search = json_encode($search);

	//cURL
	$result = exec('curl -X GET "' . $apiurl . '" -d \''. $search . '\' -H "Content-Type: application/json"');

	//Take result and parse it
	$result = json_decode($result, true);

	return $result;
}

function filterExisting($result, $cache) {
	if ( !is_array($result) ) {
		echo "Woah, that's a bad error (provided thing to filter not an array, maybe Danbooru is down?)\n";
		die;
	}

	// Filter out Danbooru Gold-only, removed posts, or anything that has been posted already
	// Filter out pixiv ugoira posts, as they are just zip files, and cannot be posted to twitter
	foreach ( $result as $r_key => $r ) {
		if ( !array_key_exists('md5', $r) ) {
			unset($result[$r_key]);
		} elseif ( in_array($r['md5'], $cache) ) {
			unset($result[$r_key]);
		} elseif ( isset($r['pixiv_ugoira_frame_data']) ) {
			unset($result[$r_key]);
		} elseif ( isset($r['file_ext']) && ($r['file_ext'] == 'mp4') ) {
			unset($result[$r_key]);
		} elseif ( isset($r['is_deleted']) && ($r['is_deleted'] == 1) ) {
			unset($result[$r_key]);
		}
	}

	// Check if the previous loop removed every post found. Output possible posts otherwise (debug for timing on cron job)
	if ( empty($result) ) {
		return false;
	}

	// Reset the array keys
	$result = array_values($result);

	return $result;
}

// Make a Twitter API connection and make a Tweet, with the provided post and media file
function postTweet($post, $filename) {
	include 'auth.php'; // File with keys and secret keys

	// Make Twitter Connection, set timeouts to be appropriate for my internet connection
	// The default values would sometimes timeout during the file upload, I hope these are generous enough for my shit connection
	try {
		$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_secret);
	} catch ( TwitterOAuthException $e ) {
		echo 'OAuth Exception: ', $e->getMessage(), "\n";
		unlink($filename);
		die;
	}
	$connection->setTimeouts(30, 120);

	// Upload file and generate the media_id
	echo "Uploading to Twitter...\n";
	$timer = time();
	$picture = $connection->upload('media/upload', ['media' => getcwd() . '/' . $filename, 'media_type' => mime_content_type(getcwd() . '/' . $filename)], true);
	echo "Finished upload. (" . time() - $timer . " seconds)\n";

	// We never need the actual image file after this, just unlink it now.
	unlink($filename);

	// We need to wait while twitter potentially need to processes our upload
	// From: https://github.com/abraham/twitteroauth/issues/554
	// This is a lazy solution until proper STATUS checking is in TwitterOAuth
	//echo "Waiting for twitter processing...\n";
	//sleep(30);

	/*if ( $connection->getLastHttpCode() != 201) {
		unlink($filename);
		echo "File not uploaded successfully: " . $connection->getLastHttpCode() . "\n";
		die;
	}*/
	//print_r($picture);
	//print_r($connection->getLastHttpCode()); echo "\n";
	
	// Status checking only needs to be done on videos and gifs if Twitter says it needs to be done
	// with the processing_info property after a FINALIZE command
	if ( $connection->getLastHttpCode() != 201 ) {
		if ( proptery_exists($picture->processing_info) ) {
			$limit = 0;
			do {
				echo "Waiting for twitter processing... \n";
				$upStatus = $connection->mediaStatus($picture->media_id_string);
				sleep(5);
				$limit++;
				if ( $limit > 10 ) {
					echo "Limit exceeded! Tweet will likely fail! Debug: \n";
					print_r($picture);
					print_r($upStatus);
				}
			} while ( $upStatus->processing_info->state !== 'succeeded' && $limit <= 10 );
		} else {
			echo "File upload unsuccessful! \n";
			print_r($picture);
			echo "Status Code: ";
			print_r($connection->getLastHttpCode());
			echo "\n";
		}
	}

	// Generate status text. This prefers crediting the artist over character names
	$status = 'http://danbooru.donmai.us/posts/' . $post['id'];
	if ( $post['tag_count_artist'] != 0 ) {
		if ( ($post['tag_count_character'] != 0) && (strlen($post['tag_string_character'] . ' by ' . $post['tag_string_artist']) < 200) ) {
			$status .= ' ' . str_replace('_', ' ', $post['tag_string_character']) . ' by ' . str_replace('_', ' ', $post['tag_string_artist']);
		} else {
			$status .= ' by ' . str_replace('_', ' ', $post['tag_string_artist']);
		}
	} else if ( ($post['tag_count_character'] != 0) && (strlen($post['tag_string_character']) < 200) ) {
			$status .= ' ' . str_replace('_', ' ', $post['tag_string_character']);
	}

	// Tweet parameters, as per Twitter API
	$tweet = [
		'status' => $status,
		'media_ids' => $picture->media_id_string,
	];

	// Make tweet
	$result = $connection->post('statuses/update', $tweet);

	if ( $connection->getLastHttpCode() == 200 ) {
		echo "Completed successfully!\n";
		//unlink($filename);
		return true;
	} else {
		//unlink($filename);
		echo "Tweet not sent correctly: " . $connection->getLastHttpCode() . "\n";
		die;
	}
}
?>
