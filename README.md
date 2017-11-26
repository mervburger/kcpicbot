# KCPicBot
A simple, PHP-based script for pulling posts from [Danbooru's safe-only section](https://safebooru.donmai.us) and posting them on Twitter.

Used for my bot [@kcpicbot](https://twitter.com/kcpicbot).

Hard-coded for the `kantai_collection` tag, but can be easily modified to use any combination of tags.

Tested only against PHP 7.0.22.

Uses [TwitterOAuth by Abraham Williams](https://twitteroauth.com/).

Requires [Composer](https://getcomposer.org/). Run `composer install` to get dependencies.

Meant to be ran from a command line via a cron job. Something like this will post every 15 minutes:
```
*/15 * * * * php /path/to/main.php
```

If you choose to use this, make a copy of `auth.sample.php` as `auth.php`, and set the variables to your Twitter API keys.

## Words of warning
This was written as, and intended only to be, a personal one-off project, and as such, there's some design decisions that reflect that. This repo is just to save it as a backup, for easy recovery if I lose this script. **It is provided AS-IS**.

This is kind of harsh on Danbooru, since it pulls 200 posts at a time, and does not keep a local cache. Fine for a one-off script, but something along those lines should be implemented to be nice.

It's also harsh on your local storage. Since there's no local cache, how is it to know how to avoid reposts? It saves the image and keeps it, and uses the images as a sort of cache, checking if the file exists and skips the post if the file does exist. Again, bad, because it just takes up space. I would also assume it's slow? But fine for my purposes, as this is just running on a spare file server in my house.
