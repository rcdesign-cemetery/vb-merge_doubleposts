<?php
/*======================================================================*\
|| #################################################################### ||
|| # Merge Double Posts 2.5                                           # ||
|| # ---------------------------------------------------------------- # ||
|| # Copyright © 2009 Dmitry Titov, Vitaly Puzrin.                    # ||
|| # All Rights Reserved.                                             # ||
|| # This file may not be redistributed in whole or significant part. # ||
|| #################################################################### ||
\*======================================================================*/


if (!isset($GLOBALS['vbulletin']->db))
{
  exit;
}


/**
 * 
 *  Check if module should be ON
 * 
 */

function CheckMrgDPEnabled()
{
  global $vbulletin, $foruminfo, $mrgdp_enable, $mrgdp_timespan;

  if (!$vbulletin->userinfo)
    return false;

  // Module is enabled by default
  $mrgdp_enable   = true;

  // set timespan
  $mrgdp_timespan = $vbulletin->options['mrgdp_timespan'];

  // check ignore groups
  $mrgdp_ignore_groups =
    unserialize($vbulletin->options['mrgdp_ignore_groups']);

  if (is_member_of($vbulletin->userinfo, $mrgdp_ignore_groups))
  {
    $mrgdp_enable = false;
  }

  // check ignore forums
  if ($mrgdp_enable AND is_array($foruminfo))
  {
    $mrgdp_ignore_forums = trim($vbulletin->options['mrgdp_ignore_forums']);

    if (!empty($mrgdp_ignore_forums))
    {
      $mrgdp_this_forum = explode(',', $foruminfo['parentlist']);
      $mrgdp_forums     = explode(',', $mrgdp_ignore_forums);

      foreach ($mrgdp_forums AS &$mrgdp_forumid)
      {
        if (in_array(intval($mrgdp_forumid), $mrgdp_this_forum))
        {
          $mrgdp_enable = false;
          break;
        }
      }

      unset($mrgdp_forums, $mrgdp_this_forum);
    }

    unset($mrgdp_ignore_forums);
  }

  return $mrgdp_enable;
}


/**
 * 
 *  Get previous post info in current thread and check if the author user
 *  is the same as current one. Returns 'true' if it is the same.
 * 
 */

function CheckMrgDPLastPost(&$savedpostinfo)
{
  global $vbulletin, $threadinfo, $post, $mrgdp_timespan;

  $mrgdp_probdoublepost = false;

  // get thread's last post data for future use
  $dpostsaved = $vbulletin->db->query_first("
    SELECT
      P.*,
      E.`dateline` AS 'lasteditdateline',
      E.`reason`   AS 'edit_reason'
    FROM
                `" . TABLE_PREFIX . "post`        AS P
      LEFT JOIN `" . TABLE_PREFIX . "deletionlog` AS L
        ON( L.`primaryid` = P.`postid` AND `type` = 'post' )
      LEFT JOIN `" . TABLE_PREFIX . "editlog`     AS E
        ON( E.`postid`    = P.`postid` )
    WHERE
          P.`threadid`   = " . intval($threadinfo['threadid'])        . "
      AND P.`dateline`   > " . intval(TIMENOW - $mrgdp_timespan * 60) . "
      AND P.`visible`    = 1
      AND P.`postid`    != " . intval($post['postid'])                . "
      AND L.`primaryid` IS NULL
    ORDER BY
      P.`dateline` DESC
    LIMIT
      1
  ");

  if (is_array($savedpostinfo))
  {
    $savedpostinfo = $dpostsaved;
  }

  if ($dpostsaved['userid'] == $vbulletin->userinfo['userid'])
  {
    $mrgdp_probdoublepost = true;
  }

  return $mrgdp_probdoublepost;
}


/**
 * 
 *  Returns formatted time value as a text.
 * 
 *  Input parameters:
 *    - timestamp (int)
 * 
 *  Ouput:
 *    - text
 * 
 */

function MrgDPFormatTime($timeinterval)
{
  /*
   * This time only two formats are implemented:
   *   a) hours and minutes (N hour[s] N minutes[s])
   *   b) days and hours (N day[s] N hour[s])
   */

  // return empty string if interval less than a minute
  if (intval($timeinterval) < 60) return '';

  $strtime = '';

  // 1 day = 86400 seconds = 24 hrs * 60 min * 60 sec
  $days    = floor( $timeinterval                                  / 86400);
  $hours   = floor(($timeinterval - $days * 86400)                 /  3600);
  $minutes = floor(($timeinterval - $days * 86400 - $hours * 3600) /    60);

  // declare localization function
  if (!function_exists('MrgDPFormatTimeUnit'))
  {
    ($hook = vBulletinHook::fetch_hook('mrgdp_formattimeunit_localize')) ? eval($hook) : false;

    if (!function_exists('MrgDPFormatTimeUnit'))
    {
      function MrgDPFormatTimeUnit($unit, $value)
      {
        global $vbphrase;

        $retstr =
          $value > 0
            ? $value . ' ' . $vbphrase[$unit.($value > 1 ? 's' : '' )]
            : '';

        return trim($retstr);
      }
    }
  }

  // select suitable format
  if (!$days) // (a)
  {
    $strtime =
      MrgDPFormatTimeUnit('hour'  , $hours  ) . ' ' .
      MrgDPFormatTimeUnit('minute', $minutes);
  }
  else        // (b)
  {
    $strtime =
      MrgDPFormatTimeUnit('day'   , $days   ) . ' ' .
      MrgDPFormatTimeUnit('hour'  , $hours  );
  }

  return trim($strtime);
}


/**
 * 
 *  Prepare text to replace vars such as {1}, {2} etc. with its values
 * 
 */

function MrgDPPrepareText($messagetext = '', $dobracevars = true)
{
  if (!empty($messagetext) AND $dobracevars)
  {
    $messagetext = str_replace('%', '%%', $messagetext);
    $messagetext = preg_replace('#\{([0-9]+)\}#sU', '%\\1$s', $messagetext);
  }

  return $messagetext;
}


/**
 * 
 *  Get messages splitter.
 * 
 *  Input parameters:
 *    - do replace "\n" to "<br />" (boolean)
 * 
 *  Ouput:
 *    - text
 * 
 */

function MrgDPFetchSplitter($donewline = false)
{
  global $vbulletin;

  $splitter = trim($vbulletin->options['mrgdp_spacer']);

  if (empty($splitter))
    return '';

  // add line return to the end
  $splitter .= "\n";

  if (    $donewline
      AND $vbulletin->input->clean_gpc('p', 'wysiwyg', TYPE_BOOL ))
    $splitter = str_replace("\n", '<br />', $splitter);

  $splitter = MrgDPPrepareText($splitter);

  return $splitter;
}


/**
 * 
 *  Check for messages join requirement and update input parameter
 *  $vbulletin->GPC['message'] appending it to previous message text.
 * 
 *  Hook: group_start_precheck
 * 
 *  Input parameters:
 *    - new message info (array)
 * 
 *  Ouput:
 *    - nothing
 * 
 */

function mrgdp_group_start_precheck ( &$message )
{
  global $vbulletin, $vbphrase;
  global $mrgdp_enable, $mrgdp_timespan;

  // set timespan
  $mrgdp_timespan = $vbulletin->options['mrgdp_timespan'];

  // check for messages join requirement
  $mrgdp_lastmessage = $vbulletin->db->query_first("
      SELECT
        P.*
      FROM
        `" . TABLE_PREFIX . "groupmessage` AS P
      WHERE
            P.`discussionid`        = " . intval($vbulletin->GPC['discussionid']) . "
        AND P.`mrgdp_editdateline`  > " . intval(TIMENOW - $mrgdp_timespan * 60)  . "
        AND P.`state`              != 'deleted'
      ORDER BY
        P.`mrgdp_editdateline` DESC,
        P.`dateline`           DESC
      LIMIT
        1
    ");

  if (is_array($mrgdp_lastmessage)
      AND $mrgdp_lastmessage['postuserid'] == $vbulletin->userinfo['userid'])
  {
    // create splitter with optional formatted 'added after' time
    $mrgdp_splitter = '';

    if (!$mrgdp_lastmessage['mrgdp_editdateline'])
      $mrgdp_lastmessage['mrgdp_editdateline'] = $mrgdp_lastmessage['dateline'];

    $vbulletin->input->clean_array_gpc('p', array(
        'message' => TYPE_STR,
        'wysiwyg' => TYPE_BOOL,
      ));

    if ($vbulletin->GPC['wysiwyg'])
    {
      $mrgdp_newline = '<br />';
    }
    else
    {
      $mrgdp_newline = "\n";
    }

    if ($vbulletin->options['mrgdp_social_delimeter_enable']
        AND $mrgdp_lastmessage['mrgdp_editdateline'] < (TIMENOW - ($vbulletin->options['noeditedbytime'] * 60)))
    {
      $dmpod_diff_time = TIMENOW - $mrgdp_lastmessage['mrgdp_editdateline'];

      // do not add splitter if time difference is less than a minute
      if ($dmpod_diff_time >= 60)
        $mrgdp_splitter = $mrgdp_newline . construct_phrase(
            MrgDPFetchSplitter(true),
            MrgDPFormatTime($dmpod_diff_time)
          );
    }

    $vbulletin->GPC['gmid'] = $mrgdp_lastmessage['gmid'];

    if ($vbulletin->GPC['wysiwyg']
        AND !empty($mrgdp_lastmessage['pagetext']))
    {
      require_once(DIR . '/includes/class_bbcode.php');
      $bbcode_parser =& new vB_BbCodeParser($vbulletin, fetch_tag_list());

      $mrgdp_lastmessage['pagetext'] = $bbcode_parser->parse(
          $mrgdp_lastmessage['pagetext'],
          'socialmessage',
          $message['disablesmilies'] ? 0 : 1
        );
    }

    $vbulletin->GPC['message'] =
      $mrgdp_lastmessage['pagetext']
      . $mrgdp_newline . $mrgdp_splitter
      . $mrgdp_newline . $vbulletin->GPC['message'];
  }
}


/**
 * 
 *  Check for attachments count limit when messages will be joined.
 * 
 *  Hook: newattachment_start
 * 
 *  Input parameters:
 *    - forum info (array)
 * 
 *  Ouput:
 *    - nothing
 * 
 */

function mrgdp_newattachment_start ( &$foruminfo )
{
  /*
   * Initialize important variables
   */

  global $vbulletin, $vbphrase;
  global $mrgdp_enable, $mrgdp_timespan;

  // Module is enabled by default
  $mrgdp_enable   = true;

  // Set timespan
  $mrgdp_timespan = 0;

  $mrgdp_enable   = CheckMrgDPEnabled();

  /*
   * Check merge conditions
   */

  if (    $mrgdp_enable
      AND $threadinfo['lastpost']    > TIMENOW - $mrgdp_timespan * 60
      AND $threadinfo['lastposter'] == $vbulletin->userinfo['username'])
  {
    $dpostsaved        = array();
    $dpcurrentattaches = 0;

    $vbulletin->GPC['mrgdp_probdoublepost'] = CheckMrgDPLastPost($dpostsaved);

    if ($dpostsaved['postid'])
    {
      // get attachments count from last post
      $dpcurrentattaches = $db->query_first("
        SELECT
          COUNT(`postid`) AS 'count'
        FROM
          `" . TABLE_PREFIX . "attachment`
        WHERE
          `postid` = " . intval($dpostsaved['postid'])."
      ");

      if (is_array($dpcurrentattaches) AND $dpcurrentattaches['count'] > 0)
      {
        // decrease allowed to upload attachments number
        $dpattachlimit =
          $vbulletin->options['attachlimit']
          - $dpcurrentattaches['count'];

        $dpattachlimit = $dpattachlimit > 0 ? $dpattachlimit : 0;

        // update phrase
        $vbphrase['you_may_only_attach_x_files_per_post'] =
          construct_phrase(
            $vbphrase['you_may_only_attach_x_files_per_this_post'],
            $vbulletin->options['attachlimit'],
            $dpattachlimit,
            $dpcurrentattaches['count']
          );

        $vbphrase['have_uploaded_maximum_x_files'] =
          construct_phrase(
            $vbphrase['have_uploaded_maximum_x_files_per_this_post'],
            $vbulletin->options['attachlimit'],
            $dpattachlimit,
            $dpcurrentattaches['count']
          );

        $vbulletin->options['attachlimit'] = $dpattachlimit;

        if (!$vbulletin->options['attachlimit'] AND $foruminfo['allowposting'])
        {
          $foruminfo['allowposting'] = false;

          $vbphrase['this_forum_is_not_accepting_new_attachments'] =
            construct_phrase(
              $vbphrase['have_uploaded_maximum_x_files_per_this_post'],
              $vbulletin->options['attachlimit'],
              $dpattachlimit,
              $dpcurrentattaches['count']
            );
        }
      }
    }
  }
}


/**
 * 
 *  Save joined messages to database.
 * 
 *  Hook: newpost_complete
 * 
 *  Input parameters:
 *    - previous post info (array)
 *    - new post info (array)
 *    - forum info (array)
 *    - saved thread info (array)
 *    - new thread info (array)
 * 
 *  Ouput:
 *    - nothing
 * 
 */

function mrgdp_newpost_complete ( &$dpostsaved, &$post, &$foruminfo, &$mrgdp_threadinfo, &$threadinfo )
{
  // Original mod by Xenon, http://www.vbulletin.org/forum/showthread.php?t=96602
  // Completely rewritten by Vitaly & Wildev

  global $permissions, $vbulletin;
  global $mrgdp_enable, $mrgdp_timespan;

  // set some important info,
  // check for it correctness and save message

  $dpostdataman =&
    datamanager_init('Post', $vbulletin, ERRTYPE_ARRAY, 'threadpost');

  $dpostdataman->set_existing($dpostsaved);


  // create splitter with optional formatted 'added after' time
  $mrgdp_splitter = '';

  if (!$dpostsaved['lasteditdateline'])
    $dpostsaved['lasteditdateline'] = $dpostsaved['dateline'];

  if ($vbulletin->options['mrgdp_delimeter_enable']
      AND $dpostsaved['lasteditdateline'] < (TIMENOW - ($vbulletin->options['noeditedbytime'] * 60)))
  {
    $dmpod_diff_time = TIMENOW - $dpostsaved['lasteditdateline'];

    // do not add splitter if time difference is less than a minute
    if ($dmpod_diff_time >= 60)
      $mrgdp_splitter = "\n" . construct_phrase(
          MrgDPFetchSplitter(),
          MrgDPFormatTime($dmpod_diff_time)
        );
  }


  $dpostsaved['message'] =
    $dpostsaved['pagetext']
    . "\n" . $mrgdp_splitter
    . "\n" . $post['message'];

  $dpostdataman->setr('pagetext'     , $dpostsaved['message']);
  $dpostdataman->setr('iconid'       , $post['iconid']       );
  $dpostdataman->setr('allowsmilie'  , $post['enablesmilies']);
  $dpostdataman->setr('showsignature', $post['signature']    );

  $dpostdataman->set_info('preview'  , $post['preview'] );
  $dpostdataman->set_info('parseurl' , $post['parseurl']);
  $dpostdataman->set_info('posthash' , $post['posthash']);
  $dpostdataman->set_info('forum'    , $foruminfo       );
  $dpostdataman->set_info('thread'   , $mrgdp_threadinfo);

  // move attachments from new post to old one
  $vbulletin->db->query_write("
      UPDATE
        `" . TABLE_PREFIX . "attachment`
      SET
        `postid`   = 0,
        `posthash` = '" . addslashes($post['posthash']) . "'
      WHERE
        `postid`   =  " . intval($post['postid'])       . "
    ");

  // check correctness
  $dpostdataman->pre_save();

  if (!$dpostdataman->errors)
  {
    // do bump thread if allowed in settings
    if ($vbulletin->options['mrgdp_bumpthread'])
    {
      $dpostsaved['dateline'] = TIMENOW;
      $dpostdataman->setr('dateline', $dpostsaved['dateline']);
    }

    // save post
    $dpostdataman->save();


    // delete added post
    $dpnewpostdataman =&
      datamanager_init('Post', $vbulletin, ERRTYPE_SILENT, 'threadpost');

    $dpnewpostdataman->set_existing($post);

    $dpnewpostdataman->delete(
        $foruminfo['countposts'],
        $threadinfo['threadid'],
        $removaltype = true,
        array(
          'userid'          => $vbulletin->userinfo['userid'],
          'username'        => $vbulletin->userinfo['username'],
          'reason'          => $vbulletin->options['mrgdp_editedby'],
          'keepattachments' => false
        ),
        false
      );

    unset($dpnewpostdataman);

    $dpostsaved['oldmessage'] = $post['message'];


    $post = $dpostsaved;
    $id   = $post['postid'];

    // add edit reason if not empty and allowed
    $mrgdp_edit_reason = trim($vbulletin->options['mrgdp_editedby']);

    // ug perm: show edited by
    if ($dpostsaved['lasteditdateline'] < (TIMENOW - ($vbulletin->options['noeditedbytime'] * 60)))
    {
      // save the postedithistory
      if ($vbulletin->options['postedithistory'])
      {
        // insert original post on first edit
        if (!$vbulletin->db->query_first("
              SELECT
                `postedithistoryid`
              FROM
                `" . TABLE_PREFIX . "postedithistory`
              WHERE
                    `original` = 1
                AND `postid`   = " . $post['postid']))
        {
          $vbulletin->db->query_write("
              INSERT INTO
                `" . TABLE_PREFIX . "postedithistory`
              SET
                `postid`   =  " . intval($post['postid'])                      . " ,
                `userid`   =  " . intval($vbulletin->userinfo['userid'])       . " ,
                `username` = '" . addslashes($vbulletin->userinfo['username']) . "',
                `title`    = '" . addslashes($post['title'])                   . "',
                `iconid`   =  " . intval($post['iconid'])                      . " ,
                `dateline` =  " . intval($post['dateline'])                    . " ,
                `reason`   = '',
                `original` = 1,
                `pagetext` = '" . addslashes($dpostsaved['pagetext'])          . "'
            ");
        }

        // insert the new version
        $vbulletin->db->query_write("
            INSERT INTO
              `" . TABLE_PREFIX . "postedithistory`
            SET
              `postid`   =  " . intval($post['postid'])                      . " ,
              `userid`   =  " . intval($vbulletin->userinfo['userid'])       . " ,
              `username` = '" . addslashes($vbulletin->userinfo['username']) . "',
              `title`    = '" . addslashes($post['title'])                   . "',
              `iconid`   =  " . intval($post['iconid'])                      . " ,
              `dateline` =  " . TIMENOW                                      . " ,
              `reason`   = '" . addslashes($mrgdp_edit_reason)               . "',
              `pagetext` = '" . addslashes($dpostsaved['message'])           . "'
          ");
      }

      // insert query
      $vbulletin->db->query_write("
        REPLACE INTO
          `" . TABLE_PREFIX . "editlog`
        SET
          `postid`     =  " . intval($post['postid'])                          . " ,
          `userid`     =  " . intval($vbulletin->userinfo['userid'])           . " ,
          `username`   = '" . addslashes($vbulletin->userinfo['username'])     . "',
          `dateline`   =  " . TIMENOW                                          . " ,
          `reason`     = '" . addslashes($mrgdp_edit_reason)                   . "',
          `hashistory` =  " . ($vbulletin->options['postedithistory'] ? 1 : 0) . "
      ");
    }

    // rebuild counters if thread bumped
    //if ($vbulletin->options['mrgdp_bumpthread'])
    //{
      build_thread_counters($post['threadid']);
      build_forum_counters($foruminfo['forumid']);
    //}
  }
}


/**
 * 
 *  Check if posts sould be joined.
 * 
 *  Hook: newpost_process
 * 
 *  Input parameters:
 *    - saved thread info (array)
 *    - previous post info (array)
 *    - new thread info (array)
 * 
 *  Ouput:
 *    - nothing
 * 
 */

function mrgdp_newpost_process ( &$mrgdp_threadinfo, &$dpostsaved, &$threadinfo )
{
  /*
   * Initialize important variables
   */

  global $vbulletin;
  global $mrgdp_enable, $mrgdp_timespan;

  // Module is enabled by default
  $mrgdp_enable   = true;

  // set timespan
  $mrgdp_timespan = 0;

  $mrgdp_enable   = CheckMrgDPEnabled();

  // save threadinfo for future use
  $mrgdp_threadinfo = $threadinfo;

  /*
   * Check merge conditions
   */

  $vbulletin->GPC['mrgdp_isdoublepost']   = false;
  $vbulletin->GPC['mrgdp_probdoublepost'] = false;

  if (    $mrgdp_enable
      AND $type                           != 'thread'
      AND $mrgdp_threadinfo['lastpost']    > TIMENOW - $mrgdp_timespan * 60
      AND $mrgdp_threadinfo['lastposter'] == $vbulletin->userinfo['username'])
  {
    $dpostsaved = array();

    $vbulletin->GPC['mrgdp_probdoublepost'] = CheckMrgDPLastPost($dpostsaved);
  }
}
