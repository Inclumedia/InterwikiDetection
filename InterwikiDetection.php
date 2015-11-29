<?php
/**
 * InterwikiDetection extension by Nathan Larson
 * URL: http://nathania.org/wiki/Miscellany:InterwikiDetection
 *
 * This program is free software. You can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version. You can also redistribute it and/or
 * modify it under the terms of the Creative Commons Attribution 3.0 license.
 *
 * This extension looks up all the wikilinks on a page that would otherwise be red and compares them
 * to a table of page titles to determine whether they exist on a remote wiki. If so, the wikilink
 * turns blue and links to the page on the remote wiki.
 */


/* Alert the user that this is not a valid entry point to MediaWiki if they try to access the
special pages file directly.*/

if ( !defined( 'MEDIAWIKI' ) ) {
	echo <<<EOT
		To install the RPED extension, put the following line in LocalSettings.php:
		require( "extensions/RPED/RPED.php" );
EOT;
	exit( 1 );
}

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'Interwiki detection',
	'namemsg' => 'interwikidetection',
	'author' => 'Nathan Larson',
	'url' => 'http://nathania.org/wiki/Miscellany:InterwikiDetection',
	'descriptionmsg' => 'interwikidetection-desc',
	'version' => '1.0.1',
);

$wgAutoloadClasses['InterwikiDetectionHooks'] = __DIR__ . '/InterwikiDetection.hooks.php';
$wgAutoloadClasses['PollAction'] = __DIR__ . '/InterwikiDetection.hooks.php';
$wgExtensionMessagesFiles['InterwikiDetection'] = __DIR__ . '/InterwikiDetection.i18n.php';

$wgHooks['LoadExtensionSchemaUpdates'][] =
	'InterwikiDetectionHooks::InterwikiDetectionCreateTable';
$wgHooks['LinksUpdate'][] = 'InterwikiDetectionHooks::onLinksUpdate';
$wgHooks['LinksUpdateComplete'][] = 'InterwikiDetectionHooks::onLinksUpdateComplete';
$wgHooks['LinkEnd'][] = 'InterwikiDetectionHooks::interwikiDetectionLinkEnd';

$wgInterwikiDetectionExistingInterwikis = array();
$wgInterwikiDetectionExistingLinks = array();
$wgInterwikiDetectionWikipediaPrefixes = array(
	'w',
	'wikipedia'
);
$wgHooks['SkinTemplateTabs'][] = 'InterwikiDetectionHooks::InterwikiDetectionSkinTemplateTabs';
$wgHooks['SkinTemplateNavigation'][] =
	'InterwikiDetectionHooks::InterwikiDetectionSkinTemplateNavigation';
$wgActions['poll'] = 'PollAction';
$wgInterwikiDetectionNamespaces = array(
	-2 => 'Media:',
	-1 => 'Special:',
	0 => '',
	1 => 'Talk:',
	2 => 'User:',
	3 => 'User talk:',
	4 => 'Project:',
	5 => 'Project talk:',
	6 => 'File:',
	7 => 'File talk:',
	8 => 'MediaWiki:',
	9 => 'MediaWiki talk:',
	10 => 'Template:',
	11 => 'Template talk:',
	12 => 'Help:',
	13 => 'Help talk:',
	14 => 'Category:',
	15 => 'Category talk:',
	828 => 'Module:',
	829 => 'Module talk:',
	2300 => 'Gadget:',
	2301 => 'Gadget talk:',
	2302 => 'Gadget definition:'
);
$wgInterwikiDetectionWikipediaUrl = 'https://en.wikipedia.org/wiki/$1';
$wgGroupPermissions['sysop']['poll'] = true;
// Don't poll more than once every x seconds
$wgInterwikiDetectionMinimumSecondsBetweenPolls = -1;
// This is to prevent infinite loops from occurring
$wgInterwikiExistenceRecursionInProgress = false;
// This is the interwiki prefix to use for making blue links.
$wgInterwikiDetectionPrefix = 'wikipedia';
$wgInterwikiDetectionLocalLinksGoInterwiki = true;
#$wgInterwikiDetectionApiQueryBegin = 'https://en.wikipedia.org/w/api.php?action=query&titles=';
$wgInterwikiDetectionApiQueryBegin = 'https://en.wikipedia.org/w/api.php?action=query&format=json';
#$wgInterwikiDetectionApiQuerySeparator = '%7C';
$wgInterwikiDetectionApiQuerySeparator = '|';
#$wgInterwikiDetectionApiQueryEnd = '&format=jsonfm';
$wgInterwikiDetectionApiQueryEnd = '&format=json';
$wgInterwikiDetectionOrphanInterval = 1000000; // A day?
// The user-agent to use in requests to the wikis from which the interwiki maps are obtained
$wgInterwikiDetectionUserAgent = "User-Agent: $wgSitename's InterwikiExistence. Contact info: URL: "
        . $wgServer . $wgScriptPath . " Email: $wgEmergencyContact";
// TODO: Create a special page, Special:InterwikiPoll
// TODO: Create a tab, Nullify; base this on Extension:Chat or Extension:Purge or something