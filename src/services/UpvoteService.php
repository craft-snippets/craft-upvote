<?php
/**
 * Upvote plugin for Craft CMS
 *
 * Lets your users upvote/downvote, "like", or favorite any type of element.
 *
 * @author    Double Secret Agency
 * @link      https://www.doublesecretagency.com/
 * @copyright Copyright (c) 2014 Double Secret Agency
 */

namespace doublesecretagency\upvote\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\Json;

use doublesecretagency\upvote\Upvote;

/**
 * Class UpvoteService
 * @since 2.0.0
 */
class UpvoteService extends Component
{

    public $settings;

    public $userCookie = 'VoteHistory';
    public $userCookieLifespan = 315569260; // Lasts 10 years
    public $anonymousHistory = [];
    public $loggedInHistory = [];
    public $history;

    //
    public function init()
    {
        // If login is required
        if (Upvote::$plugin->getSettings()->requireLogin) {
            // Rely on user history from DB
            $this->history =& $this->loggedInHistory;
        } else {
            // Rely on anonymous user history
            $this->history =& $this->anonymousHistory;
        }

        parent::init();
    }

    // Generate combined item key
    public function setItemKey($elementId, $key, $separator = ':')
    {
        return $elementId.($key ? $separator.$key : '');
    }


    private function getHistoryInGroup($groupHandle, $userId)
    {
        // If table has not been created yet, bail
        if (!Craft::$app->getDb()->tableExists('{{%upvote_userhistories}}')) {
            return false;
        }

        // if user not logged in (userId == null)
        if(is_null($userId)){
            return false;
        }   

        $history = Upvote::$plugin->upvote_query->userHistory($userId);

        // if user has no history, bail
        if(empty($history)){
            return false;
        }

        // filter user history to get votes from specific groups
        if(is_null($groupHandle)){
             // if group handle was not privided
            $votesFromGroup = array_filter($history, function($singleKey){
                $keyParts = explode(':', $singleKey);
                return (count($keyParts) == 1);
            }, ARRAY_FILTER_USE_KEY );
        }else{
            // if group handle was provided
            $votesFromGroup = array_filter($history, function($singleKey) use($groupHandle){
                $keyParts = explode(':', $singleKey);
                return (count($keyParts) == 2 && $keyParts[1] == $groupHandle);
            }, ARRAY_FILTER_USE_KEY );            
        }

         // if group has no votes, bail
        if(empty($votesFromGroup)){
            return false;
        }

        return $votesFromGroup;
    }

    private function formatHistoryInGroup($groupHandle, $userId)
    {
        // votes from provided group
        $votesInGroup = $this->getHistoryInGroup($groupHandle, $userId);

        // is something went wrong 
        if(!$votesInGroup){
            return false;
        }

        // transform into proper format
        $votesFormatted = [];
        foreach ($votesInGroup as $key => $value) {
            $singleVote = array();
            $singleVote['id'] = (int)explode(':', $key)[0];
            $singleVote['vote'] = $value;
            $votesFormatted[] = $singleVote;
        }

        return $votesFormatted;
    }


    public function getVotesInGroup($groupHandle, $userId)
    {   
        $votesInGroup = $this->formatHistoryInGroup($groupHandle, $userId);

        // if something went wrong return empty array
        if(!$votesInGroup){
            return array();
        }

        return $votesInGroup;
    }

    public function getHasVoted($elementId, $groupHandle, $userId)
    {
        // votes from provided group
        $votesInGroup = $this->formatHistoryInGroup($groupHandle, $userId);

        // default vote state - not voted
        $vote_state = 0;

        // is something went wrong
        if(empty($votesInGroup)){
            return $vote_state;
        }

        // check if voted for element
        foreach ($votesInGroup as $singleVote) {
            if($singleVote['id'] == $elementId){
                $vote_state = $singleVote['vote'];
            }
        }

        return $vote_state;
    }


    // Get history of logged-in user
    public function getUserHistory()
    {
        // If table has not been created yet, bail
        if (!Craft::$app->getDb()->tableExists('{{%upvote_userhistories}}')) {
            return false;
        }

        // Get current user
        $currentUser = Craft::$app->user->getIdentity();

        // If no current user, bail
        if (!$currentUser) {
            return false;
        }

        // Get history of current user
        $this->loggedInHistory = Upvote::$plugin->upvote_query->userHistory($currentUser->id);
    }

    // Get history of anonymous user
    public function getAnonymousHistory()
    {
        // Get request
        $request = Craft::$app->getRequest();

        // If running via command line, bail
        if ($request->getIsConsoleRequest()) {
            return false;
        }

        // If login is required, bail
        if (Upvote::$plugin->getSettings()->requireLogin) {
            return false;
        }

        // Get cookies object
        $cookies = $request->getCookies();

        // If cookie exists
        if ($cookies->has($this->userCookie)) {
            // Get anonymous history
            $cookieValue = $cookies->getValue($this->userCookie);
            $this->anonymousHistory = Json::decode($cookieValue);
        }

        // If no anonymous history
        if (!$this->anonymousHistory) {
            // Initialize anonymous history
            $this->anonymousHistory = [];
            Upvote::$plugin->upvote_vote->saveUserHistoryCookie();
        }

    }

    // Check if a key is valid
    public function validKey($key)
    {
        return (null === $key || is_string($key) || is_numeric($key));
    }

    // ========================================================================= //

    /**
     */
    public function compileElementData($itemKey, $userVote = null, $isAntivote = false)
    {
        // Get current user
        $currentUser = Craft::$app->user->getIdentity();

        // Split ID into array
        $parts = explode(':', $itemKey);

        // Get the element ID
        $elementId = (int) array_shift($parts);

        // If no element ID, bail
        if (!$elementId) {
            return false;
        }

        // Reassemble the remaining parts (in case the key contains a colon)
        $key = implode(':', $parts);

        // If no key, set to null
        if (!$key) {
            $key = null;
        }

        // Get user's vote history for this item
        $itemHistory = ($this->history[$itemKey] ?? null);

        // Set vote configuration
        $vote = [
            'id' => $elementId,
            'key' => $key,
            'itemKey' => $itemKey,
            'userId' => ($currentUser ? (int) $currentUser->id : null),
            'userVote' => ($userVote ?? $itemHistory),
            'isAntivote' => $isAntivote,
        ];

        // Get element totals from BEFORE the vote is calculated
        $totals = [
            'tally' => Upvote::$plugin->upvote_query->tally($elementId, $key),
            'totalVotes' => Upvote::$plugin->upvote_query->totalVotes($elementId, $key),
            'totalUpvotes' => Upvote::$plugin->upvote_query->totalUpvotes($elementId, $key),
            'totalDownvotes' => Upvote::$plugin->upvote_query->totalDownvotes($elementId, $key),
        ];

        // If existing vote was removed
        if ($isAntivote && $itemHistory) {
            // Create antivote
            $userVote = $itemHistory * -1;
            // Set total type
            $totalType = (1 === $userVote ? 'totalDownvotes' : 'totalUpvotes');
        } else {
            // Set total type
            $totalType = (1 === $userVote ? 'totalUpvotes' : 'totalDownvotes');
        }

        // If a vote was cast or removed
        if ($userVote) {

            // Add to tally
            $totals['tally'] += $userVote;

            // If removing vote
            if ($isAntivote) {
                // One less vote
                $totals['totalVotes']--;
                $totals[$totalType]--;
            } else {
                // One more vote
                $totals['totalVotes']++;
                $totals[$totalType]++;
            }

        }

        // Return element's vote data
        return array_merge($vote, $totals);
    }

    // ========================================================================= //

    // $userId can be valid user ID or UserModel
    public function validateUserId(&$userId)
    {
        // No user by default
        $user = null;

        // Handle user ID
        if (!$userId) {
            // Default to logged in user
            $user = Craft::$app->user->getIdentity();
        } else {
            if (is_numeric($userId)) {
                // Get valid UserModel
                $user = Craft::$app->users->getUserById($userId);
            } else if (is_object($userId) && is_a($userId, User::class)) {
                // It's already a User model
                $user = $userId;
            }
        }

        // Get user ID, or rate anonymously
        $userId = ($user ? $user->id : null);
    }

    // ========================================================================= //

    // Does the plugin contain legacy data?
    public function hasLegacyData(): bool
    {
        return (new craft\db\Query())
            ->select('[[totals.id]]')
            ->from('{{%upvote_elementtotals}} totals')
            ->where('[[totals.legacyTotal]] <> 0')
            ->exists();
    }

}
