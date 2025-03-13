=== Simple Auto-Poster for Bluesky ===
Contributors: lunaraurora
Tags: bluesky, social, auto, share, networks
Donate link: https://www.paypal.com/donate/?hosted_button_id=MM2JAKMWX5QVE
Requires at least: 6.0.0
Tested up to: 6.7.1
Requires PHP: 7.0.0
Stable tag: 1.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Simple Auto Poster for Bluesky is a set and forget plugin that automatically shares on bluesky whenever a post is published from WordPress.

== Description ==
Simple Auto Poster for Bluesky is a set and forget plugin that automatically shares on ATProto networks such as bluesky whenever a post is published from WordPress. It only requires a bluesky account and its APP password.

== PLEASE NOTE: At the moment the plugin only works if the posts are published with featured images (new versions and features coming soon) ==

Features:

1. Minimal settings
2. High compatibility
3. Enhanced security

== Installation ==
1. Upload "simple-auto-poster-for-bluesky.zip" to the "/wp-content/plugins/" directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Browse Settings / Bluesky Auto-poster.
4. Enter your bluesky Credentials (your bluesky username and the APP password set on https://bsky.app/settings/app-passwords)
5. Save settings & Forget

** NOTE ABOUT BLUESKY CREDENTIALS **
Let's say your bluesky account is https://bsky.app/profile/mywebsitename.org - in that case, the username would be: mywebsitename.org.
You can find your APP password from https://bsky.app/settings/app-passwords - The password is structurate in this format: xxxx-xxxx-xxxx-xxxx

== Third-Party Service Usage ==
This plugin relies on the bluesky social network API as a third-party service to post content automatically. The plugin interacts with the bluesky API in the following circumstances:

When authenticating the user's bluesky account
When uploading images to bluesky
When creating posts on bluesky

= Service Information =

Service Name: Bluesky
Service Website: https://bsky.app
API Documentation: https://docs.bsky.app/

= Terms of Service and Privacy Policy =

Bluesky Terms of Service: https://bsky.social/about/support/tos
Bluesky Privacy Policy: https://bsky.social/about/support/privacy-policy

= Rate Limiting =
Please read how rate limits work to avoid having your account restricted: https://docs.bsky.app/docs/advanced-guides/rate-limits

** Please review these documents to understand how your data is handled when using this plugin with the bluesky (ATProto) service. **

== Frequently Asked Questions ==
= Is the plugin automated or do I need to do anything special to see my posts published on bluesky? =
Simple Auto-Poster for Bluesky is fully automated, just publish posts from a Wordpress website and find them automatically published to the designated blueSky account.

= Can the plugin also publish images to bluesky? =
Yes, if the post has a featured image set.

= Does the plugin leave logs anywhere? =
Yes, you will be able to read a log of the operations performed from here: /wp-content/uploads/bluesky_poster_log.txt

== Screenshots ==
1. Plugin settings path
2. Plugin settings page

== Changelog ==
= 1.3 =
* Add 'thumb' only if an image blob is available

= 1.2 =
* Fixed multiple hooks triggering handle_post and possible conflicts
* Fixed published_time to get the real date of published posts

= 1.1 =
* Initial release.