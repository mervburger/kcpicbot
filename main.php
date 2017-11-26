<?php
// Twitter OAuth
require "vendor/autoload.php";
use Abraham\TwitterOAuth\TwitterOAuth;
include 'auth.php'; // File with keys and secret keys

// Ensure this runs out of the directory the script is in
chdir(__dir__);

//Format search string, and make it JSON
$search = array (
	'tags' => 'kantai_collection',
	'limit' => '200',
);
$search = json_encode($search);

//Danbooru API Endpoint
$url = 'https://safebooru.donmai.us/posts.json';

//cURL
$result = exec('curl -X GET "' . $url . '" -d \''. $search . '\' -H "Content-Type: application/json"');

//Take result and parse it
$result = json_decode($result, true);

// Some prep
chdir('images/');
$url = substr($url, 0, -11);

// Filter out Danbooru Gold-only, removed posts, or anything that has been posted already
foreach ( $result as $r_key => $r ) {
	if ( !array_key_exists('md5', $r) ) {
		unset($result[$r_key]);
	} elseif ( file_exists($r['md5'].substr($r['file_url'], -4)) ) {
		unset($result[$r_key]);
	}
}

// Check if the previous loop removed every post found. Output possible posts otherwise (debug for timing on cron job)
if ( empty($result) ) {
	die("No new posts!\n");
}
echo "Number of posts available: " . count($result) . "\n";

// Reset the array keys
$result = array_values($result);

// Select first (newest) post, and print it for debug purposes
$post = $result[0];
$filename = $post['md5'].substr($post['file_url'], -4);
print_r($post);
echo "\n";

// Get image file
exec('wget -O ' . $filename . ' ' . $url.$post['file_url']);

// Make Twitter Connection, set timeouts to be appropriate for my internet connection
// The default values would sometimes timeout during the file upload, a minute should be generous enough
try {
	$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_secret);
} catch ( TwitterOAuthException $e ) {
	echo 'OAuth Exception: ', $e->getMessage(), "\n";
	unlink($filename);
	die;
}
$connection->setTimeouts(10, 60);

// Upload file and generate the media_id
$picture = $connection->upload('media/upload', ['media' => getcwd() . '/' . $filename]);

if ( $connection->getLastHttpCode() != 200 ) {
	unlink($filename);
	die("File not uploaded successfully: " . $connection->getLastHttpCode() . "\n");
}

// Generate status text. This prefers crediting the artist over character names
$status = 'http://danbooru.donmai.us/posts/' . $post['id'];
if ( strlen($post['tag_string_character'] . ' by ' . $post['tag_string_artist']) < 200 ) {
	$status .= ' ' . str_replace('_', ' ', $post['tag_string_character']) . ' by ' . str_replace('_', ' ', $post['tag_string_artist']);
} else {
	$status .= ' by ' . str_replace('_', ' ', $post['tag_string_artist']);
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
} else {
	unlink($filename);
	die("Tweet not sent correctly: " . $connection->getLastHttpCode() . "\n");
}
?>
