
<?php
//stop after 6.
$now = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$endOfDay = clone $now;
$endOfDay->setTime(18, 0);  

if ($now >= $endOfDay) {
    echo "⏳ It's after 6 PM Cairo time ({$now->format('H:i')}), skipping all checks.<br>";
    exit;  
}

//connect to database
$mysqli = new mysqli('localhost', 'root', 'tarhush', 'cw');
date_default_timezone_set('Africa/Cairo');

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8");

function add_violation_if_none($mysqli, $user_id, $complaint = "GDT") {
    // pushups == 0 is a flag to skip them.
    $check_pushups_stmt = $mysqli->prepare("
        SELECT push_ups FROM parents WHERE ch_id = ?
    ");
    if (!$check_pushups_stmt) die("Prepare failed (push_ups check): " . $mysqli->error);
    $check_pushups_stmt->bind_param("i", $user_id);
    $check_pushups_stmt->execute();
    $pushups_result = $check_pushups_stmt->get_result();

    if ($row = $pushups_result->fetch_assoc()) {
        $current_pushups = (int)$row['push_ups'];
        if ($current_pushups <= 0) {
            echo "⚠️ User $user_id has 0 push-ups, skipping violation.<br>";
            return false;
        }
    } else {
        echo "❌ User $user_id not found in parents table.<br>";
        return false;
    }

    // Check if they already have a violation / one was recently removed.
    $check_stmt = $mysqli->prepare("
        SELECT v.id 
        FROM violation v
        JOIN rules r ON v.rule_id = r.id
        WHERE r.user_id = ? 
         AND (
             v.date_paid = '0000-00-00 00:00:00'
             OR v.date_paid >= NOW() - INTERVAL 3 MINUTE
         )
        LIMIT 1
    ");
    if (!$check_stmt) die("Prepare failed: " . $mysqli->error);
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows > 0) {
        echo "❌ User $user_id already has an unpaid violation / one was recently removed.<br>";
        return false;
    }

    // Find or insert rule based on the complaint
    $rule_stmt = $mysqli->prepare("
        SELECT id FROM rules WHERE user_id = ? AND rule = ? LIMIT 1
    ");
    if (!$rule_stmt) die("Prepare failed (rule select): " . $mysqli->error);
    $rule_stmt->bind_param("is", $user_id, $complaint);
    $rule_stmt->execute();
    $rule_result = $rule_stmt->get_result();

    if ($row = $rule_result->fetch_assoc()) {
        $rule_id = $row['id'];
    } else {
        $rule_insert_stmt = $mysqli->prepare("
            INSERT INTO rules 
            (rule, user_id, date_start, date_end, creator_id, payment, bonus, hm_times, max, indulgence, indul_date, ind_discount)
            VALUES (?, ?, NOW(), '0000-00-00 00:00:00', ?, 50, 0, 0, 40, 0, '0000-00-00 00:00:00', 3)
        ");
        if (!$rule_insert_stmt) die("Prepare failed (rule insert): " . $mysqli->error);
        $creator_id = $user_id; // Adjust if creator differs
        $rule_insert_stmt->bind_param("sii", $complaint, $user_id, $creator_id);
        if (!$rule_insert_stmt->execute()) {
            die("❌ Error inserting rule: " . $rule_insert_stmt->error . "<br>");
        }
        $rule_id = $rule_insert_stmt->insert_id;
        echo "📜 Rule '$complaint' created for user $user_id (ID $rule_id).<br>";
    }

    // End the student's current task if it has no time_end
    $end_task_stmt = $mysqli->prepare("
        UPDATE log_action
        SET time_end = NOW(), all_ = -1, sched_id = 0, note = 'ended by punishment'
        WHERE user_id = ? AND time_end = '0000-00-00 00:00:00'
        ORDER BY time_start DESC
        LIMIT 1
    ");
    if (!$end_task_stmt) die("Prepare failed (end task): " . $mysqli->error);
    $end_task_stmt->bind_param("i", $user_id);
    $end_task_stmt->execute();
    if ($end_task_stmt->affected_rows > 0) {
        echo "Ended current task for user $user_id.<br>";
    } else {
        echo "No active task to end for user $user_id.<br>";
    }

    // Insert new violation with money penalty equal to current push_ups
    $now = date("Y-m-d H:i:s");
    $money_penalty = $current_pushups;

    $insert_stmt = $mysqli->prepare("
        INSERT INTO violation 
        (rule_id, date_creation, complaint, date_paid, money, log_action_id, say1, say2, saw)
        VALUES (?, ?, 'GDT', '0000-00-00 00:00:00', ?, 0, 0, 0, 0)
    ");
    if (!$insert_stmt) die("Prepare failed (violation insert): " . $mysqli->error);
    $insert_stmt->bind_param("isi", $rule_id, $now, $money_penalty);

    if ($insert_stmt->execute()) {
        echo "✅ New violation added for user $user_id (rule $rule_id). Money: $money_penalty<br>";

        // Increment push_ups
        $update_pushups_stmt = $mysqli->prepare("
            UPDATE parents SET push_ups = push_ups + 1 WHERE ch_id = ?
        ");
        if (!$update_pushups_stmt) die("Prepare failed (update push_ups): " . $mysqli->error);
        $update_pushups_stmt->bind_param("i", $user_id);
        $update_pushups_stmt->execute();

        return true;
    } else {
        echo "❌ Error inserting violation: " . $insert_stmt->error . "<br>";
        return false;
    }
}


//Iterate through users and check their tasks.
$result = $mysqli->query("SELECT DISTINCT ch_id FROM parents WHERE ch_id IS NOT NULL");
$now = new DateTime();

while ($row = $result->fetch_assoc()) {
    $user_id = $row['ch_id'];
    echo"\n-------------------\nChecking $user_id\n-------------------\n";
    

    //check delta-pass.
    $get_delta = $mysqli->prepare("
	SELECT delta 
	FROM dle_users
	WHERE user_id = ?
	LIMIT 1
	");
    $get_delta->bind_param("i", $user_id);
    $get_delta->execute();
    $deltapass = $get_delta->get_result()->fetch_assoc()['delta'];
    if ($deltapass === 1){
	echo "User $user_id gets a delta pass";
	continue;
    }


    //  Get task to check:
    $today     = new DateTime('now', new DateTimeZone('Africa/Cairo'));
    $today->setTime(5, 0, 0);
    $cutoffStr = $today->format('Y-m-d H:i:s');

    $stmt = $mysqli->prepare("
	SELECT sched_id,
	       time_end,
	       CASE WHEN time_end = '0000-00-00 00:00:00'
		    THEN 1 ELSE 0
	       END AS in_progress,
	       time_start,
	       task_id
	  FROM log_action
	 WHERE user_id    = ?
	   AND time_start >= ?
	 ORDER BY in_progress DESC, time_end DESC
	 LIMIT 1
    ");
    $stmt->bind_param("is", $user_id, $cutoffStr);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
	echo "Task for $user_id: " . var_export($task, true) . "<br>";
    if (! $task) {
	echo "❌ User $user_id hasn’t started any tasks today.<br>";
	continue;
    }
    //used for bonus id
    $task_id  = (int)$task['task_id'];

    $sched_id = (int)$task['sched_id'];
    $inProg   = (bool)$task['in_progress'];
    $startTs  = strtotime($task['time_start']);
    $endTs    = $inProg ? null : strtotime($task['time_end']);
    $nowTs    = (new DateTime('now', new DateTimeZone('Africa/Cairo')))->getTimestamp();

    // Rogue‑task check: sched_id == 0 means it's not on their schedule.
    // inProg mans that they are doing the task.
    if ($sched_id === 0) {
	echo"\nInside sched_id now: ";
	// Chek bonus
	$bonus_stmt = $mysqli->prepare("
	    SELECT bonus FROM common_action WHERE id = ? LIMIT 1
	");
	if (!$bonus_stmt) die("Prepare failed (bonus check): " . $mysqli->error);
	$bonus_stmt->bind_param("i", $task_id);
	$bonus_stmt->execute();
	$bonus_result = $bonus_stmt->get_result();

	if ($bonus_row = $bonus_result->fetch_assoc()) {
	    if ((int)$bonus_row['bonus'] === 1) {
		echo "User $user_id is working on a bonus task → skip punishment.<br>";
		continue;
	    }else{
		echo "Not a bonus. ";
	    }
	    // If in‑progress longer than 5 min
            $elapsed = $inProg
		       ? ($nowTs - $startTs)
		       : ($nowTs - $endTs);
	    if (!$inProg && $elapsed > 180) {  // More than 3 minutes overdue → punish
		echo "❌ User $user_id is overdue by {$over}s (more than 3 minutes) and in order for last task (#{$sched_id}) → punish. (from sched_id == 0)\n<br>";
		add_violation_if_none($mysqli, $user_id, 'Lazybonesitis.');
	    }
	    else if ($elapsed > 300) {
	        echo "🚨 User $user_id stuck on ROGUE task for {$elapsed}s → punish.<br>";
		add_violation_if_none($mysqli, $user_id, 'Unscheduled Adventure');
	    } else {
	        echo "⚠️ User $user_id on rogue task but only {$elapsed}s in, within grace.<br>";
	    }
	}
    	continue;
    }

    // Legit task → look up its schedule instance (shed_id)
    $sch = $mysqli->prepare("
	SELECT shed_id
	  FROM schedule
	 WHERE id = ?
	 LIMIT 1
    ");
    $sch->bind_param("i", $sched_id);
    $sch->execute();
    $shed_id = (int)$sch->get_result()->fetch_assoc()['shed_id'];
    echo"before update shedule the active schedule was $shed_id\n";
   
    if (update_active_schedule($mysqli, $user_id, $shed_id)){
	    echo "$user_id tried to outsmart the system.";
	    add_violation_if_none($mysqli, $user_id, "10 Schedules?");
	    continue;
    }else{
	    echo "Schedule good!";
    }
	
    
    

    // check up through this sched_id
    $check_result =checkAndPunishTooShortAll($mysqli, $user_id, $cutoffStr);
    if ($check_result != ""){
        add_violation_if_none($mysqli, $user_id, $check_result);
        continue;
    } 
    $skippedTask = is_schedule_in_order($mysqli, $user_id, $shed_id);
    if ($skippedTask != ""){ 
	echo "User $user_id jumped ahead on sched entry #{$sched_id}!<br>";
	add_violation_if_none($mysqli, $user_id, $skippedTask);
	continue;
    }
    if ($inProg) {
        echo "✅ User $user_id is in order on the in-progress task (#{$sched_id}).<br>";
    } else {
        $over = $nowTs - $endTs;
        if ($over > 180) {  // More than 3 minutes overdue → punish
    	    echo "❌ User $user_id is overdue by {$over}s (more than 3 minutes) and in order for last task (#{$sched_id}) → punish.<br>";
    	    add_violation_if_none($mysqli, $user_id, 'Lazybonesitis.');
        } else {
    	    echo "✅ User $user_id finished last task recently (within grace period).<br>";
        }
    }
}



/**
 * Check if a student has skipped any tasks up through their current/last task,
 * considering only today’s schedule entries (after 06:00:00).
 *
 * @param mysqli $mysqli    Your DB connection (timezone already set to Africa/Cairo)
 * @param int    $user_id   The student's user ID
 * @param int    $shed_id   The schedule instance ID (schedule.shed_id)
 */
function is_schedule_in_order(mysqli $mysqli, int $user_id, int $shed_id): string 
{ 
    $today = new DateTime('now', new DateTimeZone('Africa/Cairo'));
    $today->setTime(5, 0, 0);
    $cutoffStr = $today->format('Y-m-d H:i:s');

    // Find the most recent or in-progress task today
    $stmt = $mysqli->prepare("
        SELECT sched_id,
               CASE WHEN time_end = '0000-00-00 00:00:00' THEN 1 ELSE 0 END AS in_progress,
               GREATEST(UNIX_TIMESTAMP(time_end), 0) AS sort_key
        FROM log_action
        WHERE user_id = ?
          AND time_start >= ?
          AND sched_id <> 0
        ORDER BY in_progress DESC, sort_key DESC
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed (find last task): " . $mysqli->error);
    }
    $stmt->bind_param("is", $user_id, $cutoffStr);
    $stmt->execute();
    $lastRow = $stmt->get_result()->fetch_assoc();
    if (!$lastRow) {
        return ""; // No tasks done today
    }
    $lastSchedId = (int)$lastRow['sched_id'];

    // Get expected prior tasks from schedule
    $stmt2 = $mysqli->prepare("
        SELECT id, task_id
        FROM schedule
        WHERE shed_id = ?
          AND id < ?
          AND id <> 0
          AND task_id > 0
        ORDER BY id ASC
    ");
    if (!$stmt2) {
        throw new RuntimeException("Prepare failed (get prior tasks): " . $mysqli->error);
    }
    $stmt2->bind_param("ii", $shed_id, $lastSchedId);
    $stmt2->execute();
    $results = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    $expectedSchedIds = [];
    echo "📋 Collected Prior Tasks:\n";
    foreach ($results as $row) {
        $expectedSchedIds[] = $row['id'];

        // Optional: fetch task name
        $taskId = $row['task_id'];
        $taskStmt = $mysqli->prepare("SELECT name FROM common_action WHERE id = ?");
        $taskStmt->bind_param("i", $taskId);
        $taskStmt->execute();
        $taskResult = $taskStmt->get_result()->fetch_assoc();
        $taskName = $taskResult ? $taskResult['name'] : 'Unknown';
        echo "- Schedule ID {$row['id']} (task_id: $taskId): $taskName\n";
    }

    echo "Expected prior task count: " . count($expectedSchedIds) . "\n";

    // ✅ NOW: collect all done tasks (sched_ids)
    $doneStmt = $mysqli->prepare("
        SELECT l.sched_id
        FROM log_action l
        WHERE l.user_id = ?
          AND l.time_start >= ?
          AND l.time_end   != '0000-00-00 00:00:00'
          AND l.all_       = 1
    ");
    if (!$doneStmt) {
        throw new RuntimeException("Prepare failed (doneTasks): " . $mysqli->error);
    }
    $doneStmt->bind_param('is', $user_id, $cutoffStr);
    $doneStmt->execute();
    $doneResult = $doneStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $doneTasks = array_column($doneResult, 'sched_id');

    // Now check whether all expected prior tasks were done
    foreach ($expectedSchedIds as $sched_id) {
        // Get the name for debug output
        $name = 'Unknown';
        $taskIdQuery = $mysqli->prepare("SELECT task_id FROM schedule WHERE id = ?");
        $taskIdQuery->bind_param("i", $sched_id);
        $taskIdQuery->execute();
        $taskRow = $taskIdQuery->get_result()->fetch_assoc();
        if ($taskRow) {
            $taskId = $taskRow['task_id'];
            $taskNameQuery = $mysqli->prepare("SELECT name FROM common_action WHERE id = ?");
            $taskNameQuery->bind_param("i", $taskId);
            $taskNameQuery->execute();
            $taskNameRow = $taskNameQuery->get_result()->fetch_assoc();
	    $name = $taskNameRow ? $taskNameRow['name'] : 'Unknown';
            echo "- Schedule ID {$sched_id} (task_id: $taskId): $name - ";
            if (!in_array($sched_id, $doneTasks, true)) {
                echo "❌Skipped Task Detected: \"$name\" (sched_id: $sched_id)\n";
                return "Don't skip $name!";
            } else {
                echo "✅ Completed\n";
            }
	}else{
	    echo"CRITICAL ERROR WHEN CHECKING FOR SKIPS!!!\n";
	}
    }

    echo "✅ All prior tasks completed.\n";
    return "";
}

/**
 * Check all completed tasks for a user since $cutoff,
 * punish any that ran too short, and return the first
 * punishment message or "" if none.
 *
 * @param mysqli $mysqli
 * @param int    $user_id
 * @param string $cutoff      // e.g. '2025-05-28 05:00:00'
 * @return string             // "<task name> was too short." or ""
 */
function checkAndPunishTooShortAll(mysqli $mysqli, int $user_id, string $cutoff): string 
{
    $paramTypes = 'is';
    $params = [$user_id, $cutoff];

    $sql = "
        SELECT
          l.id            AS log_id,
	  l.sched_id      AS sched_id,
	  l.task_id	  AS task_id,
          (UNIX_TIMESTAMP(l.time_end) - UNIX_TIMESTAMP(l.time_start)) AS actual_duration,
          ca.duration_min,
          ca.name         AS task_name
        FROM log_action l
        JOIN schedule       s  ON l.sched_id   = s.id
        JOIN common_action ca ON s.task_id   = ca.id
        WHERE
          l.user_id      = ?
          AND l.time_start >= ?
          AND l.time_end   != '0000-00-00 00:00:00'
          AND l.all_       = 1
    ";

    $stmt = $mysqli->prepare($sql);
    if (! $stmt) {
        throw new RuntimeException("Prepare failed: " . $mysqli->error);
    }
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ret = "";
    foreach ($rows as $row) {
        $logId    = (int)$row['log_id'];
        $schedId  = (int)$row['sched_id'];
	$taskId   = (int)$row['task_id'];
	$actual   = (int)$row['actual_duration'] + get_untracked_time($mysqli, $user_id, $taskId, $logId, $cutoff); // + the function I need ot write
        $minimum  = ((int)$row['duration_min']) * 60;
        $name     = $row['task_name'];

        echo "🕒 sched_id {$schedId}: actual={$actual}s, required={$minimum}s\n";

        if ($actual < $minimum) {
            $updateStmt = $mysqli->prepare("
                UPDATE log_action
                   SET all_    = -1,
                       sched_id = 0,
                       note     = 'too short, sorry'
                 WHERE id = ?
                 LIMIT 1
            ");
            if (! $updateStmt) {
                throw new RuntimeException("Prepare failed (punish): " . $mysqli->error);
            }
	    $updateStmt->bind_param("i", $logId);
	    $updateStmt->execute();

	    echo"\n{$name} was too short.";
	    $ret = "{$name} was too short.";
	    
        }
    }

    return $ret; 
}


function get_untracked_time(mysqli $mysqli, int $user_id, int $task_id, int $log_id, string $cutoffStr): int {
    // Step 1: Find the latest completed log with sched_id != 0 and completed
    echo"The cutoff is: $cutoffStr";	
    $stmt = $mysqli->prepare("
        SELECT id
        FROM log_action
        WHERE user_id = ?
          AND time_start >= ?
          AND task_id = ?
          AND sched_id != 0
          AND time_end != '0000-00-00 00:00:00'
          AND id < ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException("Prepare failed (find last completed): " . $mysqli->error);
    }
    $stmt->bind_param("isii", $user_id, $cutoffStr, $task_id, $log_id);
    $stmt->execute();
    $stmt->bind_result($last_completed_id);
    $stmt->fetch();
    $stmt->close();

    // If no previous completed task, default to ID 0
    $last_completed_id = $last_completed_id ?? 0;

    // Step 2: Find all sched_id = 0, completed entries after last_completed_id up to log_id
    $stmt2 = $mysqli->prepare("
        SELECT time_start, time_end
        FROM log_action
        WHERE user_id = ?
          AND task_id = ?
          AND sched_id = 0
	  AND all_ = -1
          AND time_start >= ?
          AND time_end != '0000-00-00 00:00:00'
          AND id > ?
	  AND id <= ?
    ");
    if (!$stmt2) {
        throw new RuntimeException("Prepare failed (get untracked logs): " . $mysqli->error);
    }
    $stmt2->bind_param("iisii", $user_id, $task_id, $cutoffStr, $last_completed_id, $log_id);
    $stmt2->execute();
    $result = $stmt2->get_result();

    $totalSeconds = 0;
    while ($row = $result->fetch_assoc()) {
        $start = strtotime($row['time_start']);
        $end   = strtotime($row['time_end']);
        if ($end > $start) {
            $totalSeconds += $end - $start;
        }
    }

    return $totalSeconds;
}

//update schedule and return true if the student has changed the schedule today.
function update_active_schedule($mysqli, $user_id, $new_sched_id) {
    $today = date('Y-m-d');

    $stmt = $mysqli->prepare("SELECT sched_id, last_change FROM active_sched WHERE user_id = ?");
    if (!$stmt) die("Prepare failed (select active_sched): " . $mysqli->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $current_sched_id = (int)$row['sched_id'];
        $last_change = $row['last_change'];

        if ($last_change !== $today) {
            // Update sched_id and last_change because last_change is old
            $update_stmt = $mysqli->prepare("UPDATE active_sched SET sched_id = ?, last_change = ? WHERE user_id = ?");
            if (!$update_stmt) die("Prepare failed (update active_sched): " . $mysqli->error);
            $update_stmt->bind_param("isi", $new_sched_id, $today, $user_id);
            $update_stmt->execute();
            return false;  // date updated, but not schedule mismatch today
        } else {
            if ($current_sched_id !== $new_sched_id) {
		// Schedule mismatch and last_change is today
		echo"current schedule: $current_sched_id new schedule: $new_sched_id";
                $update_stmt = $mysqli->prepare("UPDATE active_sched SET sched_id = ? WHERE user_id = ?");
                if (!$update_stmt) die("Prepare failed (update sched_id): " . $mysqli->error);
                $update_stmt->bind_param("ii", $new_sched_id, $user_id);
                $update_stmt->execute();
                return true;  // schedule mismatch today
            } else {
                return false; // schedule matches and last_change is today
            }
        }
    } else {
        // New user, insert row
        $insert_stmt = $mysqli->prepare("INSERT INTO active_sched (user_id, sched_id, last_change) VALUES (?, ?, ?)");
        if (!$insert_stmt) die("Prepare failed (insert active_sched): " . $mysqli->error);
        $insert_stmt->bind_param("iis", $user_id, $new_sched_id, $today);
        $insert_stmt->execute();
        return false;  // new user inserted, no mismatch today
    }
}
?>
