<?php
echo "Running at: " . date('m-d-Y H:i:s') . "\n";
// Twitter OAuth
require "vendor/autoload.php";
require_once 'vendor/cosenary/simple-php-cache/cache.class.php';
use Abraham\TwitterOAuth\TwitterOAuth;

// Constants definitions
define('MAX_ATTEMPTS', 5);
define('POST_RETRY_TIME', 21600); // Time between when a bad post can be attempted again, in seconds

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

//Get cache of bad posts
$cache->setCache('bad_posts');
$badPosts = $cache->retrieve('posts'); // should be in the format of [md5 of danbooru post] => time of last attempt
$cache->setCache('posts');

// Get the first page of posts and see if there are any new ones
$result = getPosts($search);
$result = filterExisting($result);

if ( $result == false ) {
	/* Danbooru has an anonymous user page limit of 1000
	 * Gold users can go up to 2000, platinum up to 5000 (a general TODO is
	 * support for Danbooru API keys and the differences between these
	 * accounts.)
	 * This solution assumes posts were likely missed over time, so just reset
	 * the page counter and try again from the beginning.
	 */ 
	if ( $page > 1000 ) {
		echo "Pages exceeded 1000! Danbooru does not allow anonymous user access after that page!\n";
		echo "Resetting page number and hoping...\n";
		$cache->erase('page');
		$page = 1;
	}
	$i = (!empty($page)) ? $page : 1;
	// No new posts available, go through the history
	while ( $result == false && $i <= 1000 ) {
		echo "No posts available! Trying page " . $i . "\n";
		$search['page'] = $i;
		$result = getPosts($search);
		$result = filterExisting($result, $files);
		if ( $result == false ) {
			$i++;
		}
	}
	$cache->store('page', $i);
	if ( $i > 1000 ) {
		echo "Pages exceeded 1000! Danbooru does not allow anonymous user access after that page!\n";
		echo "Next execution will reset page counter and try again.\n";
		die;
	}
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

// Attempt to post tweet.
$posted = false;
$postAttempts = 0;
do {
	echo "Attempting to post tweet... \n";
	$posted = postTweet($post, $filename);
	$postAttempts++;
} while ( $postAttempts < MAX_ATTEMPTS && $posted == false );

// Done with the image now.
unlink($filename);

// If post was successful, add to posts cache. If unsuccessful, add to bad posts
if ( $posted ) {
	$new[] = $post['md5'];
	$files = array_merge($files, $new);
	$cache->setCache('posts');
	$cache->store('posts', $files);
	// If this is a successful post of a previously bad post, remove it
	if ( isset($badPosts[$post['md5']]) ) {
		unset($badPosts[$post['md5']]);
		$cache->setCache('bad_posts');
		$cache->store('posts', $badPosts);
	}
} else {
	$badPosts[$post['md5']] = time();
	$cache->setCache('bad_posts');
	$cache->store('posts', $badPosts);
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

function filterExisting($result) {
	global $files;
	global $badPosts;

	if ( !is_array($result) ) {
		echo "Woah, that's a bad error (provided thing to filter not an array, maybe Danbooru is down?)\n";
		die;
	}

	/* Filter out Danbooru Gold-only, removed posts, or anything that has been
	 * posted already.
	 * Danbooru posts you do not have access to (removed due to artist claim,
	 * gold-only, etc,) do not have an MD5
	 * Filter out pixiv ugoira posts, as they are just zip files, and cannot be
	 * posted to twitter - these posts have the 'pixiv_ugoira_frame_data' key
	 * Filter out bad posts that have been attempted recently, so they don't
	 * prevent posts from being made for hours on end
	 */
	foreach ( $result as $r_key => $r ) {
		if ( !array_key_exists('md5', $r) ) {
			unset($result[$r_key]);
		} elseif ( in_array($r['md5'], $files) ) {
			unset($result[$r_key]);
		} elseif ( isset($r['pixiv_ugoira_frame_data']) ) {
			unset($result[$r_key]);
		} elseif ( isset($r['is_deleted']) && ($r['is_deleted'] == 1) ) {
			unset($result[$r_key]);
		} elseif ( isset($badPosts[$r['md5']]) && (time() - $badPosts[$r['md5']]) < POST_RETRY_TIME ) {
			unset($result[$r_key]);
		}
	}

	// Check if the previous loop removed every post found.
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

	/* Make Twitter Connection, set timeouts to be appropriate for my internet
	 * connection. The default values would sometimes timeout during the file
	 * upload, I hope these are generous enough for bad connections.
	 */
	try {
		$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_secret);
	} catch ( TwitterOAuthException $e ) {
		echo 'OAuth Exception: ', $e->getMessage(), "\n";
		return false;
	}
	$connection->setTimeouts(30, 120);

	// Upload file and generate the media_id
	echo "Uploading to Twitter...\n";
	$timer = time();

	// Set upload parameters
	$picture_parameters = array(
		'media' => getcwd() . '/' . $filename,
		'media_type' => mime_content_type(getcwd() . '/' . $filename),
	);

	// Determine if a gif is animated
	if ( $picture_parameters['media_type'] == 'image/gif' ) {
		$meta_tags = explode(' ', $post['tag_string_meta']);
		$is_animated_gif = false;
		foreach ( $meta_tags as $tag ) {
			if ( $tag == 'animated' || $tag == 'animated_gif') {
				$is_animated_gif = true;
			}
		}
	}
	
	// Set media_category to allow larger uploads for videos and GIFs
	if ( $picture_parameters['media_type'] == 'image/gif' && $is_animated_gif ) {
		$picture_parameters['media_category'] = 'TweetGif';
	} elseif ( $picture_parameters['media_type'] == 'video/mp4' ) {
		$picture_parameters['media_category'] = 'TweetVideo';
	}
	
	$attempts = 0;
	do {
		try {
			$picture = $connection->upload('media/upload', $picture_parameters, true);
		} catch ( TwitterOAuthException $e ) {
			echo 'OAuth Exception: ', $e->getMessage(), "\n";
			$attempts++;
			sleep(10);
			continue;
		}

		break;
	} while ( $attempts < MAX_ATTEMPTS );

	print_r($picture);

	/* We need to wait while twitter potentially needs to processes our upload
	 * Status checking only needs to be done on videos and gifs if Twitter says
	 * it needs to be done with the processing_info property after a FINALIZE
	 * command
	 */
	if ( $connection->getLastHttpCode() != 201 ) {
		if ( property_exists($picture, 'processing_info') ) {
			$limit = 0;
			do {
				echo "Waiting for twitter processing... \n";
				$upStatus = $connection->mediaStatus($picture->media_id_string);
				sleep(10);
				$limit++;
				if ( $limit == MAX_ATTEMPTS ) {
					echo "Processing limit met! Tweet will likely fail! Debug: \n";
					print_r($picture);
					print_r($upStatus);
				} else {
					print_r($upStatus);
				}
			} while ( $upStatus->processing_info->state !== 'succeeded' && $limit <= MAX_ATTEMPTS );
		} else {
			echo "File upload unsuccessful! \n";
			print_r($picture);
			echo "Status Code: ";
			print_r($connection->getLastHttpCode());
			echo "\n";
			return false;
		}
	}
	echo "Finished upload. (" . (time() - $timer) . " seconds)\n";

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
	$attempts = 0;
	do {
		try {
			$result = $connection->post('statuses/update', $tweet);
		} catch ( TwitterOAuthException $e ) {
			echo 'OAuth Exception: ', $e->getMessage(), "\n";
			$attempts++;
			sleep(5);
		}

		break;
	} while ( $attempts < MAX_ATTEMPTS );

	if ( $connection->getLastHttpCode() == 200 ) {
		echo "Completed successfully!\n";
		return true;
	} else {
		echo "Tweet not sent correctly: " . $connection->getLastHttpCode() . "\n";
		return false;
	}
}
?>
