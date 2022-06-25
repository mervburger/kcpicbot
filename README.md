# KCPicBot
A simple, PHP-based script for pulling posts from [Danbooru's safe-only section](https://safebooru.donmai.us) and posting them on Twitter.

Used for my bot [@kcpicbot](https://twitter.com/kcpicbot).

Hard-coded for the `kantai_collection` tag, but can be easily modified to use any combination of tags.

Current version runs on PHP 7.3 on openmediavault 5 (Debian Buster.)

Uses [TwitterOAuth by Abraham Williams](https://twitteroauth.com/) for Twitter authentication integration, and [Simple PHP Cache by Christian Metz](https://github.com/cosenary/Simple-PHP-Cache) for cacheing.

Requires [Composer](https://getcomposer.org/). Run `composer install` to get dependencies.

Meant to be ran from a command line via a cron job. Something like this will post every 15 minutes:
```
*/15 * * * * php /path/to/main.php
```

It also outputs diagnostic information, so redirecting its output to a log file is useful, if you care about that.

If you choose to use this, make a copy of `auth.sample.php` as `auth.php`, and set the variables to your Twitter API keys.

## Words of warning
This was written as, and intended only to be, a personal one-off project, and as such, there's some design decisions that reflect that. This repo is just to save it as a backup, for easy recovery if I lose this script. **It is provided AS-IS**.

This is kind of harsh on Danbooru, since it pulls 200 posts at a time, and does not keep a local cache. Fine for a one-off script, but something along those lines should be implemented to be nice.

The script now caches the list of files that it has posted, my file server thanks me. The last page of results is cached, so this should only need to do a maximum of 3 requests for posts at a time from Danbooru. Could be better, I'm sure, but it's a lot nicer.
