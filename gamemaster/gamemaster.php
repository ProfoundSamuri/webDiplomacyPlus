<?php
/*
    Copyright (C) 2004-2010 Kestas J. Kuliukas

	This file is part of webDiplomacy.

    webDiplomacy is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    webDiplomacy is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with webDiplomacy.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('IN_CODE') or die('This script can not be run by itself.');

/**
 * A class which performs utility functions for the gamemaster script, such as
 * adding/removing/fetching items from the process-queue, and doing various maintenance
 * tasks.
 *
 * @package GameMaster
 */
class libGameMaster
{
	/**
	 * Removes temporary (keep='No') notices that are more than a week old.
	 */
	public static function clearStaleNotices()
	{
		global $DB;

		$DB->sql_put("DELETE FROM wD_Notices
			WHERE keep='No' AND timeSent < (".time()."-7*24*60*60)");
	}

	/**
	 * Update the session table; for users which have expired from it enter their data into the
	 * access log and add their hits to the global hits counter.
	 */
	static public function updateSessionTable()
	{
		global $DB, $Misc;

		$DB->sql_put("BEGIN");

		$tabl = $DB->sql_tabl("SELECT userID FROM wD_Sessions
						WHERE UNIX_TIMESTAMP(lastRequest) < UNIX_TIMESTAMP(CURRENT_TIMESTAMP) - 10 * 60");

		$userIDs = array();

		while ( list($userID) = $DB->tabl_row($tabl) )
			$userIDs[] = $userID;

		if ( count($userIDs) > 0 )
		{
			$userIDs = implode(', ', $userIDs);

			// Update the hit counter
			list($newhits) = $DB->sql_row("SELECT SUM(hits) FROM wD_Sessions WHERE userID IN (".$userIDs.")");

			$Misc->Hits += $newhits;
			$Misc->write();

			// Save access logs, to detect multi-accounters
			$DB->sql_put("INSERT INTO wD_AccessLog
				( userID, lastRequest, hits, ip, userAgent, cookieCode, browserFingerprint )
				SELECT userID, lastRequest, hits, ip, userAgent, cookieCode, browserFingerprint
				FROM wD_Sessions
				WHERE userID IN (".$userIDs.")");

			$DB->sql_put("DELETE FROM wD_Sessions WHERE userID IN (".$userIDs.")");

			if( isset(Config::$customForumURL) )
			{
				$DB->sql_put("UPDATE wD_Users
					SET timeLastSessionEnded = ".time().", lastMessageIDViewed = (SELECT MAX(f.id) FROM wD_ForumMessages f)
					WHERE id IN (".$userIDs.")");
			}
			else
			{
				// No need for this query if using a third party DB
				$DB->sql_put("UPDATE wD_Users SET timeLastSessionEnded = ".time()." WHERE id IN (".$userIDs.")");
			}
		}

		$DB->sql_put("COMMIT");
	}

	/**
	 * Update users' phase-per-year count, which is used to calculate reliability ratings. This has to be 
	 * done carefully as the refresh rate is fairly high and the dataset is v large. The queries below have 
	 * been optimized to ensure they don't scan the table but go straight to the index boundaries.
	 */
	static public function updatePhasePerYearCount($recalculateAll = false)
	{
		global $DB;

		//-- Careful, the below is carefully optimized to use the indexes in the best way, small changes can make this v slow:

		if( $recalculateAll )
		{
			// Recalculating everything; set all turns younger than a year to be in reliability period, and count all the phases younger than a year for each user
			$DB->sql_put("UPDATE wD_TurnDate SET isInReliabilityPeriod = CASE WHEN turnDateTime>UNIX_TIMESTAMP() - 60*60*24*365 THEN 1 ELSE 0 END;");

			$DB->sql_put("UPDATE wD_Users u LEFT JOIN (SELECT userID, COUNT(1) yearlyPhaseCount FROM wD_TurnDate WHERE isInReliabilityPeriod = 1 GROUP BY userID) phases ON phases.userID = u.id SET u.yearlyPhaseCount = COALESCE(phases.yearlyPhaseCount,0);");
		}
		else
		{
			/*
			Every phase in non-bot games a users phasePerYear count is incremented and wD_TurnDate is added to, and when run 
			this routine should find all phases from over a year ago which are still flagged as in the reliability period, and
			decrement them from the users yearly phase count.

			The aim is to need to scan as few turndate records as possible, and update as few user records as possible
			*/

			// Set any phases that have just turned older than 1 year to have a NULL isInReliabilityPeriod flag, so that
			// the count of phases that have expired can be removed from the user's phases per year count.
			$DB->sql_put("UPDATE wD_TurnDate t
				INNER JOIN (
					-- Find the first id marked as in the last year using the isInReliabilityPeriod,turnDateTime index
					SELECT id FROM wD_TurnDate WHERE isInReliabilityPeriod = 1 ORDER BY isInReliabilityPeriod,turnDateTime LIMIT 1
				) lwr
				INNER JOIN (
					-- Up to the first id younger than a year using the turnDateTime index
					SELECT id FROM wD_TurnDate WHERE turnDateTime > UNIX_TIMESTAMP() - 365*24*60*60 ORDER BY turnDateTime LIMIT 1
				) upr
				SET t.isInReliabilityPeriod = NULL
				WHERE t.id >= lwr.id AND t.id <= upr.id;");

			$DB->sql_put("UPDATE wD_Users u
				INNER JOIN (
					SELECT t.userID, COUNT(1) yearlyPhaseCountJustExpired
					FROM wD_TurnDate t 
					WHERE t.isInReliabilityPeriod IS NULL
					GROUP BY t.userID
				) p ON p.userID = u.id
				SET u.yearlyPhaseCount = u.yearlyPhaseCount - p.yearlyPhaseCountJustExpired;");

			// Now set any phases that have just become older than a year as outside the reliability rating period:
			$DB->sql_put("UPDATE wD_TurnDate SET isInReliabilityPeriod = 0 WHERE isInReliabilityPeriod IS NULL;");
		}
		$DB->sql_put("COMMIT"); // Ensure no users are left locked
		$DB->sql_put("BEGIN"); // I think this might be needed to ensure we are within a transaction going forward?
	}
	/**
	 * Recalculates the reliability ratings for all users using wD_MissedTurns, the rules are on the profile page
	 * A similar method to counting the yearly phase count is used to ensure this can be constantly updated without
	 * having to scan the entire users table
	 */
	static public function updateReliabilityRating()
	{
		global $DB, $Misc;

		/*
		The RR calculation is based on this query which recalculates the RR for a user, but in 
		UPDATE wD_Users u 
		set u.reliabilityRating = greatest(0, 
		(100 *(1 - ((SELECT COUNT(1) FROM wD_MissedTurns t  WHERE t.userID = u.id AND t.modExcused = 0 and t.turnDateTime > ".$year.") / greatest(1,u.yearlyPhaseCount))))
		-(6*(SELECT COUNT(1) FROM wD_MissedTurns t  WHERE t.userID = u.id AND t.liveGame = 0 AND t.modExcused = 0 and t.samePeriodExcused = 0 and t.systemExcused = 0 and t.turnDateTime > ".$lastMonth."))
		-(6*(SELECT COUNT(1) FROM wD_MissedTurns t  WHERE t.userID = u.id AND t.liveGame = 1 AND t.modExcused = 0 and t.samePeriodExcused = 0 and t.systemExcused = 0 and t.turnDateTime > ".$lastWeek."))
		-(5*(SELECT COUNT(1) FROM wD_MissedTurns t  WHERE t.userID = u.id AND t.liveGame = 1 AND t.modExcused = 0 and t.samePeriodExcused = 0 and t.systemExcused = 0 and t.turnDateTime > ".$lastMonth."))
		-(5*(SELECT COUNT(1) FROM wD_MissedTurns t  WHERE t.userID = u.id AND t.liveGame = 0 AND t.modExcused = 0 and t.samePeriodExcused = 0 and t.systemExcused = 0 and t.turnDateTime > ".$year.")))
		where u.id = ".$userIDtoUpdate;
		*/


		// Find all turns which are in the wrong reliabilityPeriod; -1 is unassigned, NULL is being updated, 3 is under 7 days, 2 is under 28 days, 1 is under a year, 0 is over a year
		// Set all missed turns for those users to NULL (except missed turns already over a year old) which will cause a recalc:
		$timestamp = time();
		$DB->sql_put("UPDATE wD_MissedTurns 
			SET reliabilityPeriod = NULL 
			WHERE COALESCE(reliabilityPeriod,-1) <> 0 AND userID IN (
				SELECT DISTINCT userID 
				FROM wD_MissedTurns 
				WHERE COALESCE(reliabilityPeriod,-1) <> (CASE 
					WHEN turnDateTime > ".$timestamp." - 7*24*60*60 THEN 3 
					WHEN turnDateTime > ".$timestamp." - 28*24*60*60 THEN 2 
					WHEN turnDateTime > ".$timestamp." - 365*24*60*60 THEN 1 
					ELSE 0
				END ))");

		// Calculates the RR for members. 
		$DB->sql_put("UPDATE wD_Users u
		INNER JOIN (
			SELECT 
				t.userID, 
				SUM(
				CASE 
					-- If not missed for an exempt reason
					WHEN t.systemExcused = 0 AND t.samePeriodExcused = 0
					THEN
						CASE
						-- If not live ..
						WHEN liveGame = 0
						THEN
							CASE WHEN t.turnDateTime > ".$timestamp." - 28*24*60*60 
							THEN 0.11 -- .. add 11% for missed turns newer than 28 days
							ELSE 0.05  -- .. or 5% for missed turns older than 28 days
							END
						ELSE -- liveGame = 1
						
							CASE WHEN t.turnDateTime > ".$timestamp." - 7*24*60*60 
							THEN 0.11 -- .. add 11% for missed turns newer than 7 days
							WHEN t.turnDateTime > ".$timestamp." - 28*24*60*60
							THEN 0.05  -- .. or 5% for missed turns newer than 28 days
							ELSE 0.0 
							END
						END
					-- If missed for an exempt reason add a value from 100 to 0 that gets smaller as the user does more games.
					-- Goes from 99 with 100 phases / year, and 1 with 0 phases / year.
					ELSE 0.0
				END) missedTurnPenalty,
				COUNT(1) missedTurnCount
			FROM wD_MissedTurns t
			WHERE t.modExcused = 0 AND reliabilityPeriod IS NULL AND t.turnDateTime > ".$timestamp." - 60*60*24*365
			GROUP BY t.userID
		) t ON t.userID = u.id
		SET u.reliabilityRating = 100.0 * GREATEST(((1.0 - COALESCE(missedTurnCount,0) / GREATEST(u.yearlyPhaseCount,1)) 
			- COALESCE(t.missedTurnPenalty,0)), 0);
		");
		
		// Now set the turns just processed to the period they were calculated as, so that when they go into a different period it
		// will trigger a recalc
		$DB->sql_put("UPDATE wD_MissedTurns 
			SET reliabilityPeriod = CASE 
					WHEN turnDateTime > ".$timestamp." - 7*24*60*60 THEN 3 
					WHEN turnDateTime > ".$timestamp." - 28*24*60*60 THEN 2 
					WHEN turnDateTime > ".$timestamp." - 365*24*60*60 THEN 1 
					ELSE 0
				END  
			WHERE reliabilityPeriod IS NULL");

		$DB->sql_put("COMMIT");
	}

	// Finds and processes all games where all playing members excluding bots have voted for something
	static public function findAndApplyGameVotes()
	{
		global $DB;

		$tabl = $DB->sql_tabl("SELECT g.variantID, g.id, 
			CASE 
			WHEN DrawVotes = Voters THEN 'Draw'
			WHEN CancelVotes = Voters THEN 'Cancel'
			WHEN ConcedeVotes = Voters THEN 'Concede'
			WHEN PauseVotes = Voters THEN 'Pause'
			ELSE ''
			END Vote
			FROM (
				SELECT g.variantID, g.id,
					SUM(1) Voters,
					SUM(CASE WHEN (votes & 1 ) = 1 THEN 1 ELSE 0 END) DrawVotes, 
					SUM(CASE WHEN (votes & 2 ) = 2 THEN 1 ELSE 0 END) PauseVotes, 
					SUM(CASE WHEN (votes & 4 ) = 4 THEN 1 ELSE 0 END) CancelVotes, 
					SUM(CASE WHEN (votes & 8 ) = 8 THEN 1 ELSE 0 END) ConcedeVotes
				FROM wD_Games g
				INNER JOIN wD_Members m ON m.gameID = g.id
				INNER JOIN wD_Users u ON u.id = m.userID
				WHERE m.status = 'Playing' AND NOT u.`type` LIKE '%Bot%'
				AND g.phase <> 'Finished'
				GROUP BY g.id
			) g
			WHERE g.Voters = g.DrawVotes
			OR g.Voters = g.CancelVotes
			OR g.Voters = g.PauseVotes
			OR g.Voters = g.ConcedeVotes");
		$gameVotes = array();
		while(list($variantID, $gameID, $vote) = $DB->tabl_row($tabl))
		{
			$gameVotes[$gameID] = array('variantID'=>$variantID, 'name'=>$vote);
		}
		$DB->sql_put("COMMIT");
		$DB->sql_put("BEGIN");
		if( count($gameVotes) > 0 )
		{
			foreach($gameVotes as $gameID => $vote)
			{
				$DB->sql_put("BEGIN");
				$Variant=libVariant::loadFromVariantID($vote['variantID']);
				$Game = $Variant->processGame($gameID, UPDATE);
				$Game->applyVote($vote['name']);
				$DB->sql_put("COMMIT");
			}
		}
		$DB->sql_put("COMMIT");
	}
	// Finds all games where all users (incuding bots) with orders have set ready. It's similar to the function above
	// but there are enough differences to make it messy to combine
	static public function findGameReadyVotes()
	{
		global $DB;

		$tabl = $DB->sql_tabl("SELECT g.id
			FROM (
				SELECT g.id,
					SUM(1) Players,
					SUM(CASE WHEN (orderStatus & 1 ) = 1 THEN 1 ELSE 0 END) NoOrders, 
					SUM(CASE WHEN (orderStatus & 2 ) = 2 THEN 1 ELSE 0 END) SavedOrders, 
					SUM(CASE WHEN (orderStatus & 2 ) = 2 THEN 1 ELSE 0 END) CompletedOrders, 
					SUM(CASE WHEN (orderStatus & 8 ) = 8 THEN 1 ELSE 0 END) ReadyOrders
				FROM wD_Games g
				INNER JOIN wD_Members m ON m.gameID = g.id
				INNER JOIN wD_Users u ON u.id = m.userID
				WHERE m.status = 'Playing'
				AND g.phase <> 'Finished'
				GROUP BY g.id
			) g
			WHERE (g.Players - g.NoOrders) <= g.ReadyOrders"); // Everyone is ready, or only people with no orders arent ready
		$readyGames = array();
		while(list($gameID) = $DB->tabl_row($tabl))
		{
			$readyGames[] = $gameID;
		}
		return $readyGames;
	}
}

?>
