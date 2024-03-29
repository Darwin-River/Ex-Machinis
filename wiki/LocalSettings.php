<?php
# This file was automatically generated by the MediaWiki 1.35.0
# installer. If you make manual changes, please keep track in case you
# need to recreate them later.
#
# See includes/DefaultSettings.php for all configurable settings
# and their default values, but don't forget to make changes in _this_
# file, not there.
#
# Further documentation for configuration settings may be found at:
# https://www.mediawiki.org/wiki/Manual:Configuration_settings

# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}


## Uncomment this to disable output compression
# $wgDisableOutputCompression = true;

$wgSitename = 'Ex Machinis';
$wgMetaNamespace = "My_wiki";

# Define constants for my additional namespaces.
define("NS_CODE", 3200); # This MUST be even.
define("NS_CODE_TALK", 3201); # This MUST be the following odd integer.

# Add namespaces.
$wgExtraNamespaces[NS_CODE] = "Code";
$wgExtraNamespaces[NS_CODE_TALK] = "Code_talk";

# Allow for lowercase words throughout the wiki.
$wgCapitalLinks = false;

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = '';

## The protocol and server name to use in fully-qualified URLs
$wgServer = "https://exmachinis.wiki";

## The URL path to static resources (images, scripts, etc.)
$wgResourceBasePath = $wgScriptPath;

## The URL paths to the logo.  Make sure you change this from the default,
## or else you'll overwrite your logo when you upgrade!
## $wgLogos = [ '1x' => "$wgResourceBasePath/resources/assets/wiki.png" ];
$wgLogos = ['1x' => "https://exmachinis.wiki/rocket_logo.png"];
$wgFavicon = "$wgScriptPath/favicon.ico";

## UPO means: this is also a user preference option

$wgEnableEmail = true;
$wgEnableUserEmail = true; # UPO

$wgEmergencyContact = 'david.rozak@darwinriver.com';
$wgPasswordSender = 'david.rozak@darwinriver.com';

$wgEnotifUserTalk = false; # UPO
$wgEnotifWatchlist = false; # UPO
$wgEmailAuthentication = true;

## Database settings
$wgDBtype = "mysql";
$wgDBserver = "localhost";
$wgDBname = "i7432415_mw1";
$wgDBuser = "i7432415_mw1";
$wgDBpassword = "S.F4gwVVEBR2U6a9MOW89";

# MySQL specific settings
$wgDBprefix = "mw_";

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=utf8";

## Shared memory settings
$wgMainCacheType = CACHE_NONE;
$wgMemCachedServers = [];

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
$wgEnableUploads = true;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";

# InstantCommons allows wiki to use images from https://commons.wikimedia.org
$wgUseInstantCommons = false;

# Periodically send a pingback to https://www.mediawiki.org/ with basic data
# about this MediaWiki instance. The Wikimedia Foundation shares this data
# with MediaWiki developers to help guide future development efforts.
$wgPingback = false;

## If you use ImageMagick (or any other shell command) on a
## Linux server, this will need to be set to the name of an
## available UTF-8 locale
$wgShellLocale = "en_US.utf8";

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publicly accessible from the web.
#$wgCacheDirectory = "$IP/cache";

# Site language code, should be one of the list in ./languages/data/Names.php
$wgLanguageCode = 'en';

$wgSecretKey = "c48ddacf0c769af6e75ea5dc75150763c65cf7a76b8132a7b6c86a4e4a29f83c";

# Changing this will log out all existing sessions.
$wgAuthenticationTokenVersion = "1";

# Site upgrade key. Must be set to a string (default provided) to turn on the
# web installer while LocalSettings.php is in place
$wgUpgradeKey = "3b1b91c5020188ca";

## For attaching licensing metadata to pages, and displaying an
## appropriate copyright notice / icon. GNU Free Documentation
## License and Creative Commons licenses are supported so far.
$wgRightsPage = ""; # Set to the title of a wiki page that describes your license/copyright
$wgRightsUrl = "";
$wgRightsText = "";
$wgRightsIcon = "";

# Path to the GNU diff3 utility. Used for conflict resolution.
$wgDiff3 = "/usr/bin/diff3";

## Default skin: you can change the default skin. Use the internal symbolic
## names, ie 'vector', 'monobook':
$wgDefaultSkin = "vector";

# Enabled skins.
# The following skins were automatically enabled:
wfLoadSkin( 'MonoBook' );
wfLoadSkin( 'Timeless' );
wfLoadSkin( 'Vector' );


# End of automatically generated settings.
# Add more configuration options below.

# Prevent new user registrations except by sysops
$wgGroupPermissions['*']['createaccount'] = false;



# Enable subpages in the main namespace 
$wgNamespacesWithSubpages[NS_MAIN] = true;

# Only allow registerd users to edit the pages:
$wgGroupPermissions['*']['edit'] = false;

# Enable use of DISPLAYTITLE
$wgAllowDisplayTitle = true; // defaults to true
$wgRestrictDisplayTitle = false; // defaults to true

# Open external links in a new tab.
$wgExternalLinkTarget = '_blank';

# Configure CategoryTree extension
$wgUseAjax = true;
wfLoadExtension( 'CategoryTree' );
$wgCategoryTreeDynamicTag = true;
$wgCategoryTreeMaxDepth = [10 => 3, 20 => 3, 0 => 3, 100 => 3];

# Enable ParserFunction
#require_once( "$IP/extensions/ParserFunctions/ParserFunctions.php");

# Added to allow loggin with non https account:
$wgCookieSecure = false;

# Google Analytics Tag
$wgHooks['BeforePageDisplay'][] = function( OutputPage &$out, Skin &$skin ) {
     $code = <<<HTML
<!-- Global site tag (gtag.js) - Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-6H76GBYW5K"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-6H76GBYW5K');
</script>
HTML;

     $out->addHeadItem( 'gtag-insert', $code );
     return true;
};
