<?php
/**
 * A PHP tool based on TwitterOAuth to retrieve, filter and count
 * the hashtags used by a Twitter user.
 *
 * @license MIT
 */
namespace Beurkinger\TwitterHashtags;

use Abraham\TwitterOAuth\TwitterOAuth;

 /**
  * The TwitterHashtags class, where the magic happens.
  *
  * @author Thibault Goehringer <tgoehringer@gmail.com>
  */
class TwitterHashtags {
  private $connection = null;
  private $is64bits = false;

  /**
   * Constructor
   */
  function __construct ($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret) {
    $this->connection = new TwitterOAuth($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);
    if (PHP_INT_MAX > 2147483647) $this->is64bits = true;
  }

  /**
   * Set the connection and response timeouts.
   *
   * @param int $connectionTimeout
   * @param int $timeout
   */
  public function setTimeouts ($connectionTimeout, $timeout) {
    $this->connection->setTimeouts($connectionTimeout, $timeout);
  }

  /**
   * Return a list of counted hashtags
   *
   * @param string  $screenName         The user's screen name, without @
   * @param array   $hashtagFilter      An array of hashtags your want to filter, without the #
   * @param int     $maxTweetsCount     The maximum number of tweets that must be retrieved
   * @param int     $nbTweetsByRequest  The number of tweets that must be retrieved with each request to the Twitter's servers
   */
  function getUserHashtags ($screenName, array $hashtagsFilter = [], $maxTweetsCount = 500, $nbTweetsByRequest = 200) {
    $hashtags = [];
    $tweetsCount = 0;
    $lastTweetDate = null;
    $maxId = null;
    $hashtagsFilter = array_map([$this, 'cleanString'], $hashtagsFilter);

    $user = $this->getUser($screenName);

    while ($tweetsCount < $maxTweetsCount) {

      $nbTweetsToRequest = $this->getNbTweetsToRequest ($tweetsCount, $nbTweetsByRequest, $maxTweetsCount);
      $tweets = $this->getUserTweets($screenName, $nbTweetsToRequest, $maxId);

      if (!$tweets || empty($tweets)) break;

      $this->addTweetsHashtags($tweets, $hashtags, $hashtagsFilter);

      $tweetsCount += count($tweets);
      $lastTweetDate = $this->getLastTweetDate($tweets);
      $maxId = $this->getMaxId($tweets);
    }

    return [
      'screenName' => $screenName,
      'followersCount' => $user['followersCount'],
      'tweetsCount' => $user['tweetsCount'],
      'currentDate' => date(DATE_ATOM),
      'nbTweetsRead' => $tweetsCount,
      'oldestTweetRead' => $lastTweetDate,
      'hashtags' => $hashtags
    ];
  }

  /**
   * Return a user basic infos
   *
   * @param string  $screenName   The user's screen name, without @
   */
  private function getUser ($screenName) {
    $params = ['screen_name' => $screenName];
    $user = $this->connection->get('users/show', $params);
    if ($this->connection->getLastHttpCode() !== 200) {
      throw new \Exception("Error retrieving the user. Error code : " .
      $this->connection->getLastHttpCode());
    }
    return [
      'screenName' => $user->screen_name,
      'followersCount' => $user->followers_count,
      'tweetsCount' => $user->statuses_count
    ];
  }

  /**
   * Return the number of tweets that must be retrieved in the next request
   *
   * @param int $tweetsCount        The number of tweets that have already been retrieved
   * @param int $nbTweetsByRequest  The number of tweets that must be retrieved with each request to the Twitter's servers
   * @param int $maxTweetsCount     The maximum number of tweets that must be retrieved
   */
  private function getNbTweetsToRequest ($tweetsCount, $nbTweetsByRequest, $maxTweetsCount) {
    if ($tweetsCount + $nbTweetsByRequest > $maxTweetsCount) {
      return $maxTweetsCount - $tweetsCount;
    } else {
      return $nbTweetsByRequest;
    }
  }

  /**
   * Return the user's tweets
   * @param string  $screenName The user's screen name, without @
   * @param int     $count      The number of tweets that must be retrieved
   * @param int     $maxId      The heighest id that must be retrieved
   */
  private function getUserTweets ($screenName, $count = null, $maxId = null) {
    $params = ['screen_name' => $screenName, 'trim_user' => 1];
    if ($count) $params['count'] = $count;
    if ($maxId) $params['max_id'] = $maxId;
    $tweets = $this->connection->get('statuses/user_timeline', $params);
    if ($this->connection->getLastHttpCode() !== 200) {
      throw new \Exception("Error retrieving the user. Error code : " .
      $this->connection->getLastHttpCode());
    }
    return $tweets;
  }

  /**
   * Take an array of tweets, extracts the hashtags and add them to an array of hashtags
   * @param array   $tweets         An array of tweets
   * @param array   $hashtags       An array of hashtags
   * @param array   $hashtagFilter  An array of hashtags your want to filter, without the #
   */
  private function addTweetsHashtags (array $tweets, array &$hashtags, array $hashtagsFilter = []) {
    foreach ($tweets as $tweet) {
      $tweetHashtags = $tweet->entities->hashtags;
      if (!$tweetHashtags || empty($tweetHashtags)) continue;
      foreach ($tweetHashtags as $tweetHashtag) {
        $text = $tweetHashtag->text;
        $cleanText = $this->cleanString($text);
        if (!empty($hashtagsFilter) && !in_array($cleanText, $hashtagsFilter, false)) continue;
        if (!array_key_exists($text, $hashtags)) $hashtags[$text] = 0;
        $hashtags[$text]++;
      }
    }
  }

  /**
   * Get the new madIx
   * @param array   $tweets   An array of tweets
   */
  private function getMaxId ($tweets) {
    $maxId = end($tweets)->id;
    if ($this->is64bits) $maxId--;
    return $maxId;
  }

  /**
   * Get the last tweet date
   * @param array   $tweets   An array of tweets
   */
  private function getLastTweetDate ($tweets) {
    return end($tweets)->created_at;
  }

  /**
   * Remove special characters from a string
   * @param string  $text A string
   */
  private function cleanString($text) {
    $text = strtolower($text);
    $utf8 = [
      '/[áàâãªä]/u'   =>   'a',
      '/[ÁÀÂÃÄ]/u'    =>   'A',
      '/[ÍÌÎÏ]/u'     =>   'I',
      '/[íìîï]/u'     =>   'i',
      '/[éèêë]/u'     =>   'e',
      '/[ÉÈÊË]/u'     =>   'E',
      '/[óòôõºö]/u'   =>   'o',
      '/[ÓÒÔÕÖ]/u'    =>   'O',
      '/[úùûü]/u'     =>   'u',
      '/[ÚÙÛÜ]/u'     =>   'U',
      '/ç/'           =>   'c',
      '/Ç/'           =>   'C',
      '/ñ/'           =>   'n',
      '/Ñ/'           =>   'N',
      '/–/'           =>   '-', // UTF-8 hyphen to "normal" hyphen
      '/[’‘‹›‚]/u'    =>   ' ', // Literally a single quote
      '/[“”«»„]/u'    =>   ' ', // Double quote
      '/ /'           =>   ' ', // nonbreaking space (equiv. to 0x160)
    ];
    return preg_replace(array_keys($utf8), array_values($utf8), $text);
  }
}
