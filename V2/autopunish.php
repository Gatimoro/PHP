<?php
//To stop after 6.
$now = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$endOfDay = clone $now;
$endOfDay->setTime(18, 0);  // 6 PM

if ($now >= $endOfDay) {
    echo "⏳ It's after 6 PM Cairo time ({$now->format('H:i')}), skipping all checks.<br>";
    exit;  // or return, or break if inside a function
}


//actual script
$mysqli = new mysqli('localhost', 'root', 'tarhush', 'cw');
date_default_timezone_set('Africa/Cairo');

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8");

function add_violation_if_none($mysqli, $user_id) {
    // Check if user has any push_ups available
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

   // Check for existing unpaid violation in last 3 minutes.
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

    // Find or insert rule "Gravity Demands Tribute."
    $rule_stmt = $mysqli->prepare("
	SELECT id FROM rules WHERE user_id = ? AND rule = 'Gravity Demands Tribute.' LIMIT 1
    ");
    if (!$rule_stmt) die("Prepare failed (rule select): " . $mysqli->error);
    $rule_stmt->bind_param("i", $user_id);
    $rule_stmt->execute();
    $rule_result = $rule_stmt->get_result();

    if ($row = $rule_result->fetch_assoc()) {
	$rule_id = $row['id'];
    } else {
	$rule_insert_stmt = $mysqli->prepare("
	    INSERT INTO rules 
	    (rule, user_id, date_start, date_end, creator_id, payment, bonus, hm_times, max, indulgence, indul_date, ind_discount)
	    VALUES ('Gravity Demands Tribute.', ?, NOW(), '0000-00-00 00:00:00', ?, 50, 0, 0, 40, 0, '0000-00-00 00:00:00', 3)
	");
	if (!$rule_insert_stmt) die("Prepare failed (rule insert): " . $mysqli->error);
	$creator_id = $user_id; // Adjust this if needed
	$rule_insert_stmt->bind_param("ii", $user_id, $creator_id);
	if (!$rule_insert_stmt->execute()) {
	    die("❌ Error inserting rule: " . $rule_insert_stmt->error . "<br>");
	}
	$rule_id = $rule_insert_stmt->insert_id;
	echo "📜 Rule 'Gravity Demands Tribute.' created for user $user_id (ID $rule_id).<br>";
    }

    // Insert new violation with money penalty equal to current push_ups
    $now = date("Y-m-d H:i:s");
    $money_penalty = $current_pushups;

    $insert_stmt = $mysqli->prepare("
	INSERT INTO violation 
	(rule_id, date_creation, complaint, date_paid, money, log_action_id, say1, say2, saw)
	VALUES (?, ?, 'Bro/Sis got caught.', '0000-00-00 00:00:00', ?, 0, 0, 0, 0)
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

//Iterate through users and evaluate task status
$result = $mysqli->query("SELECT DISTINCT ch_id FROM parents WHERE ch_id IS NOT NULL");
$now = new DateTime();

while ($row = $result->fetch_assoc()) {
    $user_id = $row['ch_id'];
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
	echo "User $user_id get's a delta pass";
	continue;
    }


    // 1) Get task to check:
    $today     = new DateTime('now', new DateTimeZone('Africa/Cairo'));
    $today->setTime(5, 0, 0);
    $cutoffStr = $today->format('Y-m-d H:i:s');

    $stmt = $mysqli->prepare("
	SELECT sched_id,
	       time_end,
	       CASE WHEN time_end = '0000-00-00 00:00:00'
		    THEN 1 ELSE 0
	       END AS in_progress,
	       time_start
	  FROM log_action
	 WHERE user_id    = ?
	   AND time_start >= ?
	 ORDER BY in_progress DESC, time_end DESC
	 LIMIT 1
    ");
    $stmt->bind_param("is", $user_id, $cutoffStr);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();

    if (! $task) {
	echo "❌ User $user_id hasn’t started any tasks today.<br>";
	continue;
    }

    $sched_id = (int)$task['sched_id'];
    $inProg   = (bool)$task['in_progress'];
    $startTs  = strtotime($task['time_start']);
    $endTs    = $inProg ? null : strtotime($task['time_end']);
    $nowTs    = (new DateTime('now', new DateTimeZone('Africa/Cairo')))->getTimestamp();

    // If it's finished and within 3‑min grace, skip
    if (! $inProg && ($nowTs - $endTs) <= 180) {
	echo "✅ User $user_id finished task recently (" . ($nowTs - $endTs) . "s ago).<br>";
	continue;
    }

    // Rogue‑task check: sched_id == 0
    if ($sched_id === 0) {
	// If in‑progress longer than 5 min
	$elapsed = $inProg
		   ? ($nowTs - $startTs)
		   : ($nowTs - $endTs);
	if ($elapsed > 300) {
	    echo "🚨 User $user_id stuck on ROGUE task for {$elapsed}s → punish.<br>";
	    add_violation_if_none($mysqli, $user_id);
	} else {
	    echo "⚠️ User $user_id on rogue task but only {$elapsed}s in, within grace.<br>";
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

	// check up through this sched_id
	if (! is_schedule_in_order($mysqli, $user_id, $shed_id)) {
	    echo "User $user_id jumped ahead on sched entry #{$sched_id}!<br>";
	    add_violation_if_none($mysqli, $user_id);
	} else {
	    if ($inProg) {
		echo "✅ User $user_id is in order on the in-progress task (#{$sched_id}).<br>";
	    } else {
		$over = $nowTs - $endTs;
		if ($over > 180) {  // More than 3 minutes overdue → punish
		    echo "❌ User $user_id is overdue by {$over}s (more than 3 minutes) and in order for last task (#{$sched_id}) → punish.<br>";
		    add_violation_if_none($mysqli, $user_id);
		} else {
		    echo "✅ User $user_id finished last task recently (within grace period).<br>";
		}
	    }
	}
}




function is_schedule_in_order(mysqli $mysqli, int $user_id, int $shed_id): bool
{
    // Get today's date at 6 - 7
    $today = new DateTime('now', new DateTimeZone('Africa/Cairo'));
    $today->setTime(5, 0, 0);
    $cutoffStr = $today->format('Y-m-d H:i:s');

    // Find user's most recent or in-progress task today
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
	// No tasks done today at all
	return true;
    }
    $lastSchedId = (int)$lastRow['sched_id'];

    // Get all tasks from this schedule with id < lastSchedId
    $stmt2 = $mysqli->prepare("
	SELECT id
	  FROM schedule
	 WHERE shed_id = ?
	   AND id < ?
	   AND id <> 0
    ");
    if (!$stmt2) {
	throw new RuntimeException("Prepare failed (get prior tasks): " . $mysqli->error);
    }
    $stmt2->bind_param("ii", $shed_id, $lastSchedId);
    $stmt2->execute();
    $priorTasks = array_column($stmt2->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

    if (empty($priorTasks)) {
	// There are no prior tasks, so nothing to check
	return true;
    }

    //  Get the list of completed prior tasks today
    $placeholders = implode(',', array_fill(0, count($priorTasks), '?'));
    $types = str_repeat('i', count($priorTasks));
    $stmt3 = $mysqli->prepare("
	SELECT DISTINCT sched_id
	  FROM log_action
	 WHERE user_id = ?
	   AND sched_id IN ($placeholders)
	   AND time_start >= ?
	   AND time_end != '0000-00-00 00:00:00'
	   AND all_ = 1
    ");
    if (!$stmt3) {
	throw new RuntimeException("Prepare failed (check completed tasks): " . $mysqli->error);
    }

    $params = array_merge([$user_id], $priorTasks, [$cutoffStr]);
    $types = 'i' . $types . 's';
    $stmt3->bind_param($types, ...$params);
    $stmt3->execute();
    $doneTasks = array_column($stmt3->get_result()->fetch_all(MYSQLI_ASSOC), 'sched_id');

    // Verify all prior tasks are completed
    foreach ($priorTasks as $tid) {
	if (!in_array($tid, $doneTasks, true)) {
	    return false; // A prior task was missed
	}
    }

    return true; // All required tasks completed
}
?>
