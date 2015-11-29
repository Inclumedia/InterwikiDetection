<?php
class InterwikiDetectionHooks {
    public static function InterwikiDetectionCreateTable( $updater = null ) {
	    global $wgExtNewTables;
	    $wgExtNewTables[] = array(
		'iw_detect',
		    __DIR__ . '/iwdtable.sql'
	    );
	    return true;
    }

    // This function gets the interwiki links and pagelinks that will be compared to those that
    // exist after the link updates are made.
    public static function onLinksUpdate( &$linksUpdate ) {
	global $wgInterwikiDetectionExistingInterwikis, $wgInterwikiDetectionExistingLinks,
	    $wgUser;
	// Make bots and maintenance scripts not trigger any of this, because they'll be
	// making mass edits.
	if ( in_array( 'bots', $wgUser->getGroups() ) ) {
	    return;
	}

	$dbr = wfGetDB( DB_SLAVE );
	$res = $dbr->select( 'iwlinks', array( 'iwl_prefix', 'iwl_title' ),
	    array( 'iwl_from' => $linksUpdate->mId ) );
	$arr = array();
	foreach ( $res as $row ) {
	    if ( !isset( $arr[$row->iwl_prefix] ) ) {
		    $arr[$row->iwl_prefix] = array();
	    }
	    $arr[$row->iwl_prefix][ucfirst( $row->iwl_title )] = 1;
	}
	// Save the pagelinks as an array of namespace to database keys, i.e. [namespace][DBKey]
	$wgInterwikiDetectionExistingInterwikis = $arr;
	$res = $dbr->select( 'pagelinks', array( 'pl_namespace', 'pl_title' ),
		array( 'pl_from' => $linksUpdate->mId ) );
	$arr = array();
	foreach ( $res as $row ) {
		if ( !isset( $arr[$row->pl_namespace] ) ) {
			$arr[$row->pl_namespace] = array();
		}
		$arr[$row->pl_namespace][ucfirst( $row->pl_title )] = 1;
	}
	$wgInterwikiDetectionExistingLinks = $arr;
    }

    public static function onLinksUpdateComplete( &$linksUpdate ) {
	global $wgInterwikiDetectionExistingInterwikis, $wgInterwikiDetectionExistingLinks,
	    $wgUser;
	// Make bots and maintenance scripts not trigger any of this, because they'll be
	// making mass edits.
	if ( in_array( 'bots', $wgUser->getGroups() ) ) {
	    return;
	}
	#echo $currentTime;
	#die();
	$existingInterwikis = $wgInterwikiDetectionExistingInterwikis;
	$existingLinks = $wgInterwikiDetectionExistingLinks;
	$updatedInterwikis = $linksUpdate->mInterwikis;
	$updatedLinks = $linksUpdate->mLinks;
	#echo "\n\nexistingInterwikis";
	#var_dump( $existingInterwikis );
	#echo "\n\nupdatedInterwikis";
	#var_dump( $updatedInterwikis );
	InterwikiDetectionHooks::doPoll( $existingInterwikis, $existingLinks, $updatedInterwikis,
	    $updatedLinks );
	return true;
    }

    public static function doPoll( $existingInterwikis, $existingLinks, $updatedInterwikis,
	$updatedLinks ) {
	global $wgInterwikiDetectionWikipediaPrefixes, $wgInterwikiDetectionNamespaces, $wgUser,
	    $wgInterwikiDetectionApiQueryBegin, $wgInterwikiDetectionApiQuerySeparator,
	    $wgInterwikiDetectionApiQueryEnd, $wgInterwikiDetectionOrphanInterval,
	    $wgInterwikiDetectionUserAgent, $wgInterwikiDetectionMinimumSecondsBetweenPolls;
	$dbr = wfGetDB( DB_SLAVE );
	$dbw = wfGetDB( DB_MASTER );
	$currentTime = wfTimestampNow();
	#echo "\n\nexistingLinks";
	#var_dump( $existingLinks );
	#echo "\n\nupdatedLinks";
	#var_dump( $updatedLinks );
	// Get the deleted interwikis
	$deletedInterwikis = array();
	foreach ( $existingInterwikis as $prefix => $dbkeys ) {
	    if ( isset( $updatedInterwikis[$prefix] ) ) {
		    $deletedInterwikis[$prefix] = array_diff_key( $existingInterwikis[$prefix],
			$updatedInterwikis[$prefix] );
	    } else {
		    $deletedInterwikis[$prefix] = $existingInterwikis[$prefix];
	    }
	}
	// Get the inserted interwikis
	$insertedInterwikis = array();
	foreach ( $updatedInterwikis as $prefix => $dbkeys ) {
	    if ( isset( $existingInterwikis[$prefix] ) ) {
		    $insertedInterwikis[$prefix] = array_diff_key(
			$updatedInterwikis[$prefix], $existingInterwikis[$prefix] );
	    } else {
		    $insertedInterwikis[$prefix] = $updatedInterwikis[$prefix];
	    }
	}
	// Get the deleted pagelinks
	$deletedLinks = array();
	foreach ( $existingLinks as $ns => $dbkeys ) {
	    if ( isset( $updatedLinks[$ns] ) ) {
		    $deletedLinks[$ns] = array_diff_key( $existingLinks[$ns],
			$updatedLinks[$ns] );
	    } else {
		    $deletedLinks[$ns] = $existingLinks[$ns];
	    }
	}
	// Get the inserted pagelinks
	$insertedLinks = array();
	foreach ( $updatedLinks as $ns => $dbkeys ) {
	    if ( isset( $existingLinks[$ns] ) ) {
		    $insertedLinks[$ns] = array_diff_key( $updatedLinks[$ns],
			$existingLinks[$ns] );
	    } else {
		    $insertedLinks[$ns] = $updatedLinks[$ns];
	    }
	}
	// Get rid of non-Wikipedia interwiki deletions
	foreach ( $deletedInterwikis as $prefix => $element ) {
	    if ( !in_array( $prefix, $wgInterwikiDetectionWikipediaPrefixes ) ) {
		unset( $deletedInterwikis[$prefix] );
	    }
	}
	// Get rid of non-Wikipedia interwiki insertions
	foreach ( $insertedInterwikis as $prefix => $element ) {
	    if ( !in_array( $prefix, $wgInterwikiDetectionWikipediaPrefixes ) ) {
		unset( $insertedInterwikis[$prefix] );
	    }
	}
	// Merge all interwiki deletions from the remaining prefixes (wikipedia:, w:, etc.)
	$deletedWikipediaInterwikis = array();
	foreach( $deletedInterwikis as $prefix ) {
	    foreach ( $prefix as $key => $element ) {
		$key = ucfirst ( $key );
		$key = str_replace( ' ', '_', $key );
		if ( !in_array( $key, $deletedWikipediaInterwikis ) ) {
		    $deletedWikipediaInterwikis[$key] = $element;
		}
	    }
	}
	// Merge all interwiki insertions from the remaining prefixes (wikipedia:, w:, etc.)
	$insertedWikipediaInterwikis = array();
	foreach( $insertedInterwikis as $prefix ) {
	    foreach ( $prefix as $key => $element ) {
		$key = ucfirst ( $key );
		$key = str_replace( ' ', '_', $key );
		if ( !in_array( $key, $insertedWikipediaInterwikis ) ) {
		    $insertedWikipediaInterwikis[$key] = $element;
		}
	    }
	}
	#echo "\n\ninsertedInterwikis";
	#var_dump( $insertedInterwikis );
	#echo "\n\ninsertedWikipediaInterwikis";
	#var_dump( $insertedWikipediaInterwikis );
	#echo "\n\ndeletedInterwikis";
	#var_dump( $deletedInterwikis );
	#echo "\n\ninsertedLinks";
	#var_dump( $insertedLinks );
	#echo "\n\ndeletedLinks";
	#var_dump( $deletedLinks );

	#die();
	// Merge in the deleted mainspace pagelinks
	$wikipediaMergedDeletions = $deletedWikipediaInterwikis;
	if ( isset( $deletedLinks[0] ) ) {
	    $wikipediaMergedDeletions = array_merge( $wikipediaMergedDeletions, $deletedLinks[0] );
	}
	// Merge in the inserted mainspace pagelinks
	$wikipediaMergedInsertions = $insertedWikipediaInterwikis;
	if ( isset( $insertedLinks[0] ) ) {
	    $wikipediaMergedInsertions = array_merge( $wikipediaMergedInsertions, $insertedLinks[0] );
	}
	$wikipediaMergedDeletions = array_keys( $wikipediaMergedDeletions );
	$wikipediaMergedInsertions = array_keys( $wikipediaMergedInsertions );
	#echo "\nwikipediaMergedDeletions";
	#var_dump( $wikipediaMergedDeletions );
	#echo "\nwikipediaMergedInsertions";
	#var_dump( $wikipediaMergedInsertions );
	#die();

	// See if the deleted ones exist anywhere in either table (pagelinks or iwlinks)
	// If they don't, mark them to be purged
	foreach ( $wikipediaMergedDeletions as $deletion ) {
	    $title = Title::newFromText( $deletion );
	    // Is it in the pagelinks table?
	    $test = $dbr->selectRow(
		'pagelinks',
		array( 'pl_namespace' ),
		array( 'pl_namespace' => $title->getNamespace(), 'pl_title' => $title->getDBKey )
	    );
	    if ( $test ) {
		continue;
	    }
	    // Is it in the iwlinks table? Check prefixes w:, wikipedia:, etc.
	    foreach ( $wgInterwikiDetectionWikipediaPrefixes as $prefix ) {
		$test = $dbr->selectRow( 'iwlinks', array( 'iwl_prefix' ),
		    array( 'iwl_prefix' => $prefix, 'iwl_title' => $deletion ) );
		if ( $test ) {
		    continue 2;
		}
	    }
	    // It wasn't found in pagelinks or iwlinks, so mark it to be purged
	    $dbw->update(
		'iw_detection',
		array( 'iwd_orphaned' => $currentTime ),
		array( 'iwd_title' => $deletion )
	    );
	}
	// The inserted ones, see if they already exist in the iw_detection table
	// If they do exist, and iwd_orphaned isn't 99999999999999, make it 99999999999999
	// If they don't exist, add them and mark them for polling (set iwd_exists to
	// 00000000000000).
	foreach ( $wikipediaMergedInsertions as $insertion ) {
	    $res = $dbr->selectRow(
		'iw_detection',
		array( 'iwd_id', 'iwd_orphaned' ),
		array( 'iwd_title' => $insertion )
	    );
	    if ( $res ) {
		if ( $res->iwd_orphaned !== 99999999999999 ) {
		    $dbw->update (
			'iw_detection',
			array ( 'iwd_orphaned' => 99999999999999 ),
			array( 'iwd_id' => $res->iwd_id )
		    );
		}
	    } else {
		$dbw->insert(
		    'iw_detection',
		    array( 'iwd_title' => $insertion )
		);
	    }
	}
	// Purge everything with iwd_orphaned more than z seconds old
	$res = $dbr->select(
	    'iw_detection',
	    array( 'iwd_id', 'iwd_title', 'iwd_orphaned' ),
	    array(),
	    __METHOD__,
	    array( 'ORDER BY' => 'iwd_orphaned ASC', 'LIMIT' => 500 )
	);
	foreach ( $res as $row ) {
	    if ( $currentTime - $row->iwd_orphaned > $wgInterwikiDetectionOrphanInterval ) {
		$dbw->delete(
		    'iw_detection',
		    array( 'iwd_id' => $row->iwd_id )
		);
	    }
	}
	// Make a list of the 500 rows with the oldest iwd_polled.
	$res = $dbr->select(
	    'iw_detection',
	    array( 'iwd_id', 'iwd_title', 'iwd_polled' ),
	    array(),
	    __METHOD__,
	    array( 'ORDER BY' => 'iwd_polled ASC', 'LIMIT' => 500 )
	);
	// Prepare the API query
	$iwdTitle = array();
	$apiQuery = $wgInterwikiDetectionApiQueryBegin;
	$firstRow = true;
	$secondsRequirementMet = false;
	$data = array( 'titles' => '' );
	foreach ( $res as $row ) {
	    if ( !$firstRow ) {
	#	$apiQuery .= $wgInterwikiDetectionApiQuerySeparator;
		$data['titles'] .= $wgInterwikiDetectionApiQuerySeparator;
	    }
	    $firstRow = false;
	    $iwdTitle[$row->iwd_id] = $row->iwd_title;
	    #$apiQuery .= urlencode ( $row->iwd_title );
	    $data['titles'] .= $row->iwd_title;
	    if ( $currentTime - $row->iwd_polled
		>= $wgInterwikiDetectionMinimumSecondsBetweenPolls ) {
		$secondsRequirementMet = true;
	    }
	}
	// If there were no results with older than x seconds, don't bother to poll
	if ( !$secondsRequirementMet ) {
	    return;
	}
	#$apiQuery = str_replace( ' ', '_', $apiQuery );
	#$apiQuery .= $wgInterwikiDetectionApiQueryEnd;
	#echo $apiQuery;
	#die();
	// Poll the API
	$apiPull = array();
	$iwdExists = array();
	/*$opts = array(
	    'http'=>array(
		'method' => "POST",
		'header' => $wgInterwikiDetectionUserAgent
	    )
	);
	$streamContext = stream_context_create( $opts );*/
	#$contents = file_get_contents ( $apiQuery, false, $streamContext );
	$contents = InterwikiDetectionHooks::http_post ( $apiQuery, $data, $wgInterwikiDetectionUserAgent );
	#$contents = $contents['content'];
	$apiPull = $contents;
	#echo $contents;
	#die();
	$apiPull = json_decode ( $contents, true );
	#var_dump( $apiPull );
	#die();
	if ( isset( $apiPull['query']['normalized'] ) ) {
	    $apiNormalized = $apiPull['query']['normalized'];
	}
	$apiPull = $apiPull['query']['pages'];

	foreach ( $apiPull as $apiPullElement ) {
	    // Denormalize
	    if ( isset( $apiNormalized ) ) {
		foreach ( $apiNormalized as $element ) {
		    #echo $element['to'];
			#echo $apiPullElement['title'];
		    if ( $element['to'] == $apiPullElement['title'] )  {
			$apiPullElement['title'] = $element['from'];
		    }
		}
	    }
	    #$apiPullElement['title'] = str_replace( ' ', '_', $apiPullElement['title'] );
	    $thisId = array_search( $apiPullElement['title'], $iwdTitle, true );
	    $iwdExists[$thisId] = 1;
	    if ( isset( $apiPullElement['missing'] ) ) {
		$iwdExists[$thisId] = 0;
	    }
	}
	#die();
	// Update the database with the polled data
	foreach ( $iwdTitle as $id => $title ) {
	    $dbw->update(
		'iw_detection',
		array(
		      'iwd_exists' => $iwdExists[$id],
		      'iwd_polled' => $currentTime
		),
		array( 'iwd_id' => $id )
	    );
	}
    }

    public static function interwikiDetectionLinkEnd( $dummy, Title $target, array $options,
	    &$html, array &$attribs, &$ret ) {
	// This doesn't work. Need to rewrite it from scratch or something.
	#return true;
	global $wgInterwikiDetectionPrefix,
		$wgInterwikiDetectionRecursionInProgress,
		$wgInterwikiDetectionLocalLinksGoInterwiki,
		$wgInterwikiDetectionWikipediaUrl, $wgInterwikiDetectionWikipediaPrefixes;
	/*if ( $wgInterwikiDetectionRecursionInProgress ) {
	    $ret = Html::rawElement ( 'a', array (
		    'href' => str_replace( '$1', $target->getDBKey(), $wgInterwikiDetectionWikipediaUrl ),
        		'class' => 'new' ),
			$target->getFullText() );
	    $wgInterwikiDetectionRecursionInProgress = false;
			return false;
	}*/
	$dbr = wfGetDB( DB_MASTER );
	$title = $target->getFullText();
	$isInterwiki = false;
	$encodedTitle = str_replace( ' ', '_', $title );
	#$encodedTitle = $dbr->strencode ( $encodedTitle );
	#die();
	/*if ( !$wgInterwikiDetectionRecursionInProgress && in_array( $target->getInterwiki (),
		$wgInterwikiDetectionWikipediaPrefixes ) ) {
		$encodedTitle = substr ( $title, strlen (
			$target->getInterwiki() ) + 1, strlen ( $title )
			- $target->getInterwiki() );
		$encodedTitle = str_replace( ' ', '_', $title );
		$isInterwiki = true;
	}*/
	$wgInterwikiDetectionRecursionInProgress = false;
	#echo $encodedTitle;
	#$searchFor = $target->getFullText();
	$searchFor = $target->getFullText();

	if ( $target->getInterwiki() ) {
	    $isInterwiki = true;
	    $searchFor = $target->getDBKey();
	}
	$displayTitle = $target->getFullText();
	$searchFor = str_replace( ' ', '_', $searchFor );
	$result = $dbr->selectRow ( 'iw_detection', array ( 'iwd_title' ), array (
	    'iwd_title' => $searchFor, 'iwd_exists' => '1' ) );
	if ( !$result && $target->getInterwiki() ) {
	    if ( substr( $searchFor, 0, 10 ) === 'Wikipedia:' ) {
	    #echo 'Project:' . substr( $searchFor, 10, strlen( $searchFor - 10 ) );
	    #die();
	    $result = $dbr->selectRow ( 'iw_detection', array ( 'iwd_title' ), array (
	    'iwd_title' => 'Project:' . substr( $searchFor, 10, strlen( $searchFor - 10 ) )
	    , 'iwd_exists' => '1' ) );
	    } elseif ( substr( $searchFor, 0, 8 ) === 'Project:' ) {
		$result = $dbr->selectRow ( 'iw_detection', array ( 'iwd_title' ), array (
		'iwd_title' => 'Wikipedia:' . substr( $searchFor, 8, strlen( $searchFor - 8 ) )
		, 'iwd_exists' => '1' ) );
	    }
	}
	if ( $result ) {
		if ( ( $isInterwiki || $wgInterwikiDetectionLocalLinksGoInterwiki ) ) {

			$wgInterwikiDetectionRecursionInProgress = true;
			if ( !$html ) {
				$html = $title;
			}
			/*$ret = Linker::link ( Title::newFromText( $wgInterwikiDetectionPrefix . ':'
				#. $target->getDBKey() ), $html );
				. $encodedTitle ), $html );
				#. 'foo' ), $html );*/

			$ret = Html::rawElement ( 'a', array (
		    'href' => str_replace( '$1', $searchFor, $wgInterwikiDetectionWikipediaUrl ),
        		'class' => 'known' ),
			$html );
			return false;
			}
	}
	if ( $target->getInterwiki() ) {
	    $ret = Html::rawElement ( 'a', array (
		'href' => str_replace( '$1', $searchFor, $wgInterwikiDetectionWikipediaUrl ),
		'class' => 'new' ),
		$title );
	    /*foreach ( $wgInterwikiDetectionWikipediaPrefixes ) {
		if ( substr( $encodedTitle )
	    }*//*
	    $interwiki = Interwiki::fetch ( $wgInterwikiDetectionPrefix );
		$ret = Html::rawElement ( 'a', array ( 'href' => $interwiki->getURL (
			#$encodedTitle ), 'class' => 'new' ),
			$target->getDBKey() ), 'class' => 'new' ),
			$title );*/
	    return false;
	}

	return true;
    }

    public static function InterwikiDetectionSkinTemplateTabs( $skin, &$content_actions ) {
	global $wgRequest;
	$action = $wgRequest->getText( 'action' );
	$content_actions['poll'] = array(
	    'class' => ( $action ==
		'poll') ? 'selected' : false,
	        'text' => "Poll",
	    // Vector support
	    'href' => $skin->getTitle()->getLocalUrl( 'action=poll' )
	);
	 return true;
    }

    // Vector support
    public static function InterwikiDetectionSkinTemplateNavigation( $skin, &$links ) {
	   return self::InterwikiDetectionSkinTemplateTabs($skin, $links['views']);
    }

    /**
    make an http POST request and return the response content and headers
    @param string $url    url of the requested script
    @param array $data    hash array of request variables
    @return returns a hash array with response content and headers in the following form:
	array ('content'=>'<html></html>'
	    , 'headers'=>array ('HTTP/1.1 200 OK', 'Connection: close', ...)
	    )
    */
    public static function http_post ( $url, $data, $header )
    {
#	$data_url = http_build_query ($data);
#	$data_len = strlen ($data_url);

#	return array ('content'=>file_get_contents ($url, false, stream_context_create (array ('http'=>array ('method'=>'POST'
#		, 'header'=>$header
#		, 'content'=>$data_url
#		))))
#	    , 'headers'=>$http_response_header
#	    );
#   }
	$query = http_build_query($data);
	$options = array(
	    'http' => array(
		'header' => "Content-Type: application/x-www-form-urlencoded\r\n".
			    "Content-Length: ".strlen($query)."\r\n".
			    $header,
		'method'  => "POST",
		'content' => $query,
	    ),
	);
	$context = stream_context_create($options);
	$result = file_get_contents($url, false, $context, -1, 40000);
	return $result;
    }
}

class PollAction extends FormAction {

	private $redirectParams;

	public function getName() {
		return 'poll';
	}

	public function requiresUnblock() {
		return false;
	}

	public function getDescription() {
		return '';
	}

	/**
	 * Just get an empty form with a single submit button
	 * @return array
	 */
	protected function getFormFields() {
		return array();
	}

	public function onSubmit( $data ) {
	    global $wgInterwikiDetectionExistingInterwikis,
		$wgInterwikiDetectionExistingLinks;
	    $title = $this->page->getTitle();
	    $id = $title->getArticleID();
	    #echo $id;
	    #die();
	    $dbr = wfGetDB( DB_SLAVE );
	    $res = $dbr->select( 'iwlinks', array( 'iwl_prefix', 'iwl_title' ),
		array( 'iwl_from' => $id ) );
	    $arr = array();
	    foreach ( $res as $row ) {
		if ( !isset( $arr[$row->iwl_prefix] ) ) {
			$arr[$row->iwl_prefix] = array();
		}
		$arr[$row->iwl_prefix][ucfirst( $row->iwl_title )] = 1;
	    }
	    // Save the pagelinks as an array of namespace to database keys, i.e. [namespace][DBKey]
	    $wgInterwikiDetectionExistingInterwikis = $arr;
	    $res = $dbr->select( 'pagelinks', array( 'pl_namespace', 'pl_title' ),
		    array( 'pl_from' => $id ) );
	    $arr = array();
	    foreach ( $res as $row ) {
		    if ( !isset( $arr[$row->pl_namespace] ) ) {
			    $arr[$row->pl_namespace] = array();
		    }
		    $arr[$row->pl_namespace][ucfirst( $row->pl_title )] = 1;
	    }
	    $wgInterwikiDetectionExistingLinks = $arr;
	    $existingInterwikis = $wgInterwikiDetectionExistingInterwikis;
	    $existingLinks = $wgInterwikiDetectionExistingLinks;
	    #echo "\n\nexistingInterwikis";
	    #var_dump( $existingInterwikis );
	    #echo "\n\nupdatedInterwikis";
	    #var_dump( $updatedInterwikis );
	    InterwikiDetectionHooks::doPoll( array(), array(), $existingInterwikis, $existingLinks );
	    return $this->page->doPurge();
	}

	public function show() {
		$this->setHeaders();

		// This will throw exceptions if there's a problem
		$this->checkCanExecute( $this->getUser() );

		if ( $this->getUser()->isAllowed( 'poll' ) ) {
			$this->redirectParams = wfArrayToCgi( array_diff_key(
				$this->getRequest()->getQueryValues(),
				array( 'title' => null, 'action' => null )
			) );
			if ( $this->onSubmit( array() ) ) {
				$this->onSuccess();
			}
		} else {
			$this->redirectParams = $this->getRequest()->getVal( 'redirectparams', '' );
			$form = $this->getForm();
			if ( $form->show() ) {
				$this->onSuccess();
			}
		}
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'confirm_poll_button' );
	}

	protected function preText() {
		return $this->msg( 'confirm-poll-top' )->parse();
	}

	protected function postText() {
		return $this->msg( 'confirm-poll-bottom' )->parse();
	}

	public function onSuccess() {
		$this->getOutput()->redirect( $this->getTitle()->getFullURL( $this->redirectParams ) );
	}
}